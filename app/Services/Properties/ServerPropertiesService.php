<?php

namespace App\Services\Properties;

use App\Contracts\Repository\SettingsRepositoryInterface;
use App\Exceptions\DisplayException;
use App\Exceptions\Http\Connection\DaemonConnectionException;
use App\Models\Server;
use App\Repositories\Agent\DaemonFileRepository;
use Illuminate\Http\Response;

/**
 * Reads and writes a Minecraft server's server.properties through the daemon
 * file API, preserving comments, ordering and any keys the panel does not know
 * about.
 */
class ServerPropertiesService
{
    public const FILE = '/server.properties';

    public const EULA_FILE = '/eula.txt';

    public const MAX_BYTES = 512 * 1024;

    private const KEY_PATTERN = '/^[A-Za-z0-9._\-]+$/';

    /** @var array<int, int>|null */
    private ?array $eggIdsCache = null;

    public function __construct(
        private DaemonFileRepository $fileRepository,
        private SettingsRepositoryInterface $settings,
    ) {}

    /**
     * Whether the properties editor is enabled for this server's egg.
     */
    public function isEnabledFor(Server $server): bool
    {
        return in_array($server->egg_id, $this->enabledEggIds(), true);
    }

    /**
     * Egg IDs allowed to use the properties editor, from settings.
     *
     * @return array<int, int>
     */
    public function enabledEggIds(): array
    {
        if ($this->eggIdsCache !== null) {
            return $this->eggIdsCache;
        }

        $value = $this->settings->get('settings::panel:properties:egg_ids', null);

        if (empty($value)) {
            return $this->eggIdsCache = [];
        }

        if (is_array($value)) {
            return $this->eggIdsCache = array_map('intval', $value);
        }

        return $this->eggIdsCache = array_map('intval', json_decode($value, true) ?: []);
    }

    /**
     * Known property definitions, keyed by property name.
     *
     * @return array<string, array<string, mixed>>
     */
    public function definitions(): array
    {
        return config('server_properties.properties', []);
    }

    /**
     * Group render order.
     *
     * @return array<int, string>
     */
    public function groups(): array
    {
        return config('server_properties.groups', []);
    }

    /**
     * Read and parse the server's properties file.
     *
     * @return array{exists: bool, raw: string, values: array<string, string>}
     */
    public function read(Server $server): array
    {
        try {
            $raw = $this->fileRepository->setServer($server)->getContent(self::FILE, self::MAX_BYTES);
        } catch (DaemonConnectionException $exception) {
            if ($exception->getStatusCode() === Response::HTTP_NOT_FOUND) {
                return ['exists' => false, 'raw' => '', 'values' => []];
            }

            throw $exception;
        }

        return [
            'exists' => true,
            'raw' => $raw,
            'values' => $this->values($this->parse($raw)),
        ];
    }

    /**
     * Apply a set of property changes, validating them first.
     *
     * @param  array<string, mixed>  $changes  raw user input
     * @return array{exists: bool, raw: string, values: array<string, string>}
     */
    public function apply(Server $server, array $changes): array
    {
        $normalized = $this->normalize($changes);

        return $this->applyNormalized($server, $normalized);
    }

    /**
     * Apply already-normalized changes (used by the controller after explicit
     * normalization, and internally by this class).
     *
     * @param  array<string, string>  $normalized
     * @return array{exists: bool, raw: string, values: array<string, string>}
     */
    public function applyNormalized(Server $server, array $normalized): array
    {
        $current = $this->read($server);

        if ($normalized === []) {
            return $current;
        }

        $content = $this->render($this->parse($current['raw']), $normalized);

        $this->fileRepository->setServer($server)->putContent(self::FILE, $content);

        return [
            'exists' => true,
            'raw' => $content,
            'values' => $this->values($this->parse($content)),
        ];
    }

    /**
     * Overwrite the whole file with user supplied content.
     *
     * @return array{exists: bool, raw: string, values: array<string, string>}
     */
    public function updateRaw(Server $server, string $content): array
    {
        if (strlen($content) > self::MAX_BYTES) {
            throw new DisplayException('The properties file may not be larger than 512 KB.');
        }

        if (str_contains($content, "\0")) {
            throw new DisplayException('The properties file may not contain null bytes.');
        }

        $this->fileRepository->setServer($server)->putContent(self::FILE, $content);

        return [
            'exists' => true,
            'raw' => $content,
            'values' => $this->values($this->parse($content)),
        ];
    }

    /**
     * Whether the Minecraft EULA has been accepted for this server.
     */
    public function eulaAccepted(Server $server): bool
    {
        try {
            $raw = $this->fileRepository->setServer($server)->getContent(self::EULA_FILE, self::MAX_BYTES);
        } catch (DaemonConnectionException $exception) {
            if ($exception->getStatusCode() === Response::HTTP_NOT_FOUND) {
                return false;
            }

            throw $exception;
        }

        foreach ($this->parse($raw) as $node) {
            if ($node['type'] === 'entry' && $node['key'] === 'eula') {
                return strtolower(trim($node['value'])) === 'true';
            }
        }

        return false;
    }

    /**
     * Write out an accepted EULA.
     */
    public function acceptEula(Server $server): void
    {
        $this->fileRepository->setServer($server)->putContent(self::EULA_FILE, "eula=true\n");
    }

    /**
     * Validate and stringify incoming property changes. Locked properties are
     * dropped rather than rejected, they are managed by the panel.
     *
     * @param  array<string, mixed>  $changes
     * @return array<string, string>
     */
    public function normalize(array $changes): array
    {
        $definitions = $this->definitions();
        $normalized = [];

        foreach ($changes as $key => $value) {
            $key = (string) $key;

            if (! preg_match(self::KEY_PATTERN, $key)) {
                throw new DisplayException(sprintf('"%s" is not a valid property name.', $key));
            }

            $definition = $definitions[$key] ?? null;

            if ($definition && ($definition['locked'] ?? false)) {
                continue;
            }

            $normalized[$key] = $this->escape($this->cast($key, $value, $definition));
        }

        return $normalized;
    }

    /**
     * Coerce a submitted value into the string form Minecraft expects.
     *
     * @param  array<string, mixed>|null  $definition
     */
    private function cast(string $key, mixed $value, ?array $definition): string
    {
        if (is_bool($value)) {
            $value = $value ? 'true' : 'false';
        }

        if ($value === null) {
            $value = '';
        }

        if (! is_scalar($value)) {
            throw new DisplayException(sprintf('The value for "%s" must be a scalar.', $key));
        }

        $value = (string) $value;

        if (preg_match('/[\r\n\0]/', $value)) {
            throw new DisplayException(sprintf('The value for "%s" may not contain line breaks.', $key));
        }

        $type = $definition['type'] ?? 'string';

        if ($type === 'bool') {
            $lowered = strtolower($value);

            if (! in_array($lowered, ['true', 'false'], true)) {
                throw new DisplayException(sprintf('The value for "%s" must be true or false.', $key));
            }

            return $lowered;
        }

        if ($type === 'int') {
            if (! preg_match('/^-?\d+$/', $value)) {
                throw new DisplayException(sprintf('The value for "%s" must be a whole number.', $key));
            }

            $number = (int) $value;
            $min = $definition['min'] ?? null;
            $max = $definition['max'] ?? null;

            if ($min !== null && $number < $min) {
                throw new DisplayException(sprintf('The value for "%s" must be at least %d.', $key, $min));
            }

            if ($max !== null && $number > $max) {
                throw new DisplayException(sprintf('The value for "%s" may not be greater than %d.', $key, $max));
            }

            return (string) $number;
        }

        if ($type === 'enum') {
            $options = $definition['options'] ?? [];

            if (! in_array($value, $options, true)) {
                throw new DisplayException(sprintf('The value for "%s" must be one of: %s.', $key, implode(', ', $options)));
            }
        }

        return $value;
    }

    /**
     * Split a properties file into an ordered list of nodes. Comments and blank
     * lines are kept verbatim so they survive a round trip.
     *
     * @return array<int, array<string, mixed>>
     */
    public function parse(string $raw): array
    {
        $lines = preg_split('/\r\n|\n|\r/', $raw) ?: [];

        // A trailing newline produces an empty final element; drop it so we do
        // not grow the file by one blank line on every save.
        if ($lines !== [] && end($lines) === '') {
            array_pop($lines);
        }

        $nodes = [];
        $count = count($lines);

        for ($i = 0; $i < $count; $i++) {
            $source = [$lines[$i]];
            $logical = $lines[$i];

            // Java properties allow a line to continue onto the next one when it
            // ends with an odd number of backslashes.
            while ($this->continues($logical) && $i + 1 < $count) {
                $logical = substr($logical, 0, -1).ltrim($lines[++$i]);
                $source[] = $lines[$i];
            }

            $trimmed = ltrim($logical);

            if ($trimmed === '' || str_starts_with($trimmed, '#') || str_starts_with($trimmed, '!')) {
                $nodes[] = ['type' => 'raw', 'source' => $source];

                continue;
            }

            $position = $this->separatorPosition($trimmed);

            if ($position === null) {
                // A bare key with no separator is still a valid (empty) property.
                $nodes[] = [
                    'type' => 'entry',
                    'key' => $this->unescapeKey(rtrim($trimmed)),
                    'raw_key' => rtrim($trimmed),
                    'value' => '',
                    'source' => $source,
                ];

                continue;
            }

            $rawKey = rtrim(substr($trimmed, 0, $position));

            $nodes[] = [
                'type' => 'entry',
                'key' => $this->unescapeKey($rawKey),
                'raw_key' => $rawKey,
                'value' => ltrim(substr($trimmed, $position + 1)),
                'source' => $source,
            ];
        }

        return $nodes;
    }

    /**
     * Rebuild a properties file, replacing only the keys that changed.
     *
     * @param  array<int, array<string, mixed>>  $nodes
     * @param  array<string, string>  $changes
     */
    public function render(array $nodes, array $changes): string
    {
        $out = [];
        $pending = $changes;

        foreach ($nodes as $node) {
            if ($node['type'] === 'entry' && array_key_exists($node['key'], $pending)) {
                // Preserve continuation lines (all but the last source line),
                // then write the new key=value as the final line.  This keeps
                // multi-line properties intact when only the value changes.
                $source = $node['source'];

                foreach (array_slice($source, 0, -1) as $line) {
                    $out[] = $line;
                }

                $out[] = $node['raw_key'].'='.$pending[$node['key']];

                unset($pending[$node['key']]);

                continue;
            }

            foreach ($node['source'] as $line) {
                $out[] = $line;
            }
        }

        foreach ($pending as $key => $value) {
            $out[] = $this->escapeKey($key).'='.$value;
        }

        return implode("\n", $out)."\n";
    }

    /**
     * Flatten parsed nodes into a key => value map for the client. Values are
     * only decoded far enough to be editable, never re-escaped here.
     *
     * @param  array<int, array<string, mixed>>  $nodes
     * @return array<string, string>
     */
    private function values(array $nodes): array
    {
        $values = [];

        foreach ($nodes as $node) {
            if ($node['type'] === 'entry') {
                $values[$node['key']] = $this->decode($node['value']);
            }
        }

        return $values;
    }

    /**
     * Whether a line ends with an odd number of backslashes.
     */
    private function continues(string $line): bool
    {
        $backslashes = strlen($line) - strlen(rtrim($line, '\\'));

        return $backslashes % 2 === 1;
    }

    /**
     * Locate the first unescaped `=` or `:` in a logical line.
     */
    private function separatorPosition(string $line): ?int
    {
        $length = strlen($line);

        for ($i = 0; $i < $length; $i++) {
            if ($line[$i] === '\\') {
                $i++;

                continue;
            }

            if ($line[$i] === '=' || $line[$i] === ':') {
                return $i;
            }
        }

        return null;
    }

    private function unescapeKey(string $key): string
    {
        return preg_replace('/\\\\(.)/', '$1', $key) ?? $key;
    }

    private function escapeKey(string $key): string
    {
        return preg_replace('/([=:\s])/', '\\\\$1', $key) ?? $key;
    }

    /**
     * Turn `\uXXXX` sequences into real characters so the form shows what the
     * player actually sees. Other backslash sequences are left alone; MOTDs are
     * routinely pasted around with literal escape codes in them.
     */
    private function decode(string $value): string
    {
        if (! str_contains($value, '\\u')) {
            return $value;
        }

        return preg_replace_callback(
            '/\\\\u([0-9a-fA-F]{4})/',
            fn (array $match) => mb_chr((int) hexdec($match[1]), 'UTF-8') ?: $match[0],
            $value
        ) ?? $value;
    }

    /**
     * Escape a value the way Java's Properties writer would. Non-ASCII becomes
     * `\uXXXX` so the file stays readable regardless of how the server decodes
     * it, and a leading space is escaped so it is not swallowed on load.
     */
    private function escape(string $value): string
    {
        $escaped = preg_replace_callback(
            '/[^\x20-\x7E]/u',
            fn (array $match) => sprintf('\\u%04x', mb_ord($match[0], 'UTF-8')),
            $value
        ) ?? $value;

        if (str_starts_with($escaped, ' ')) {
            $escaped = '\\'.$escaped;
        }

        if (str_starts_with($escaped, '#') || str_starts_with($escaped, '!')) {
            $escaped = '\\'.$escaped;
        }

        return $escaped;
    }
}
