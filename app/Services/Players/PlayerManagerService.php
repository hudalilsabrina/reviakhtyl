<?php

namespace App\Services\Players;

use App\Contracts\Repository\SettingsRepositoryInterface;
use App\Exceptions\Http\Connection\DaemonConnectionException;
use App\Exceptions\Http\Server\FileSizeTooLargeException;
use App\Models\Server;
use App\Repositories\Agent\DaemonCommandRepository;
use App\Repositories\Agent\DaemonFileRepository;
use Illuminate\Support\Facades\Cache;
use Webmozart\Assert\Assert;

class PlayerManagerService
{
    /** @var array<int, int>|null */
    private ?array $eggIdsCache = null;

    private const WHITELIST_FILE = 'whitelist.json';

    private const OPS_FILE = 'ops.json';

    private const BANS_FILE = 'banned-players.json';

    public function __construct(
        private DaemonCommandRepository $commandRepository,
        private DaemonFileRepository $fileRepository,
        private SettingsRepositoryInterface $settings,
    ) {}

    /**
     * Whether the player manager is enabled for this server's egg.
     */
    public function isEnabledFor(Server $server): bool
    {
        return in_array($server->egg_id, $this->enabledEggIds(), true);
    }

    /**
     * Egg IDs allowed to use the player manager, from settings.
     *
     * @return array<int, int>
     */
    public function enabledEggIds(): array
    {
        if ($this->eggIdsCache !== null) {
            return $this->eggIdsCache;
        }

        return $this->eggIdsCache = Cache::remember('panel:players:egg_ids_cache', now()->addHours(1), function () {
            $value = $this->settings->get('settings::panel:players:egg_ids', null);

            if (empty($value)) {
                return [];
            }

            if (is_array($value)) {
                return array_map('intval', $value);
            }

            return array_map('intval', json_decode($value, true) ?: []);
        });
    }

    /**
     * Whether the server is currently running (powered on), as reported by the daemon.
     */
    public function isOnline(Server $server): bool
    {
        return in_array($server->getResolvedStatus(), ['running', 'online', 'starting'], true);
    }

    /**
     * Full status snapshot: power state plus whitelist, ops and bans lists.
     *
     * @return array{online: bool, whitelist: array<int, array<string, mixed>>, ops: array<int, array<string, mixed>>, bans: array<int, array<string, mixed>>}
     */
    public function status(Server $server): array
    {
        return [
            'online' => $this->isOnline($server),
            'whitelist' => $this->readJson($server, self::WHITELIST_FILE),
            'ops' => $this->readJson($server, self::OPS_FILE),
            'bans' => $this->readJson($server, self::BANS_FILE),
        ];
    }

    /**
     * Players currently online, inferred from the tail of the server log.
     *
     * Not a live source of truth: Minecraft logs are rewritten each boot, so the
     * result is the join/leave activity seen since the current boot. Best-effort
     * for servers that do not expose RCON to the panel.
     *
     * @return array<int, string>
     */
    public function online(Server $server): array
    {
        if (! $this->isOnline($server)) {
            return [];
        }

        $content = $this->readLogTail($server);

        $players = [];
        $joined = [];

        foreach (preg_split('/\R/', $content) ?: [] as $line) {
            if (preg_match('/\] \[[^\]]*\]: ([A-Za-z0-9_]{1,16}) (joined the game|left the game)/', $line, $m)) {
                $joined[$m[1]] = $m[2] === 'joined the game';
            }
        }

        foreach ($joined as $name => $isOnline) {
            if ($isOnline) {
                $players[] = $name;
            }
        }

        sort($players);

        return $players;
    }

    /**
     * Add a player to the whitelist. Uses the game command when the server is
     * online (so the whitelist is reloaded), otherwise edits whitelist.json.
     */
    public function whitelistAdd(Server $server, string $name): void
    {
        $this->assertUsername($name);

        if ($this->isOnline($server)) {
            $this->sendCommand($server, "whitelist add {$name}");

            return;
        }

        $this->writeJson($server, self::WHITELIST_FILE, $this->upsertEntry(
            $this->readJson($server, self::WHITELIST_FILE),
            ['uuid' => $this->uuidFor($name), 'name' => $name],
        ));
    }

    /**
     * Remove a player from the whitelist.
     */
    public function whitelistRemove(Server $server, string $name): void
    {
        $this->assertUsername($name);

        if ($this->isOnline($server)) {
            $this->sendCommand($server, "whitelist remove {$name}");

            return;
        }

        $this->writeJson($server, self::WHITELIST_FILE, $this->removeEntry(
            $this->readJson($server, self::WHITELIST_FILE),
            $name,
        ));
    }

    /**
     * Grant operator status. `level` is the vanilla op level (1-4).
     */
    public function op(Server $server, string $name, int $level = 4): void
    {
        $this->assertUsername($name);

        if ($this->isOnline($server)) {
            $this->sendCommand($server, "op {$name}");

            return;
        }

        $this->writeJson($server, self::OPS_FILE, $this->upsertEntry(
            $this->readJson($server, self::OPS_FILE),
            ['uuid' => $this->uuidFor($name), 'name' => $name, 'level' => $level, 'bypassesPlayerLimit' => false],
        ));
    }

    /**
     * Revoke operator status.
     */
    public function deop(Server $server, string $name): void
    {
        $this->assertUsername($name);

        if ($this->isOnline($server)) {
            $this->sendCommand($server, "deop {$name}");

            return;
        }

        $this->writeJson($server, self::OPS_FILE, $this->removeEntry(
            $this->readJson($server, self::OPS_FILE),
            $name,
        ));
    }

    /**
     * Ban a player, optionally with a reason.
     */
    public function ban(Server $server, string $name, ?string $reason = null): void
    {
        $this->assertUsername($name);

        if ($this->isOnline($server)) {
            $this->sendCommand($server, $reason ? "ban {$name} {$reason}" : "ban {$name}");

            return;
        }

        $entry = [
            'uuid' => $this->uuidFor($name),
            'name' => $name,
            'created' => gmdate('Y-m-d H:i:s O'),
            'source' => 'Panel',
            'expires' => 'forever',
        ];

        if ($reason) {
            $entry['reason'] = $reason;
        }

        $this->writeJson($server, self::BANS_FILE, $this->upsertEntry(
            $this->readJson($server, self::BANS_FILE),
            $entry,
        ));
    }

    /**
     * Unban a player.
     */
    public function unban(Server $server, string $name): void
    {
        $this->assertUsername($name);

        if ($this->isOnline($server)) {
            $this->sendCommand($server, "pardon {$name}");

            return;
        }

        $this->writeJson($server, self::BANS_FILE, $this->removeEntry(
            $this->readJson($server, self::BANS_FILE),
            $name,
        ));
    }

    /**
     * Read and decode a Minecraft JSON list file from the server directory.
     *
     * @return array<int, array<string, mixed>>
     */
    private function readJson(Server $server, string $file): array
    {
        try {
            $content = $this->fileRepository->setServer($server)->getContent('/'.$file, 2_000_000);
        } catch (DaemonConnectionException|FileSizeTooLargeException) {
            return [];
        }

        if (empty($content)) {
            return [];
        }

        $decoded = json_decode($content, true);

        if (! is_array($decoded)) {
            return [];
        }

        $entries = [];
        foreach ($decoded as $entry) {
            if (is_array($entry)) {
                $entries[] = $entry;
            }
        }

        return $entries;
    }

    /**
     * Serialize and write a Minecraft JSON list file to the server directory.
     *
     * @param  array<int, array<string, mixed>>  $entries
     */
    private function writeJson(Server $server, string $file, array $entries): void
    {
        $this->fileRepository->setServer($server)->putContent(
            '/'.$file,
            json_encode($entries, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL,
        );
    }

    /**
     * Add or update an entry by matching `uuid` or `name`.
     *
     * @param  array<int, array<string, mixed>>  $entries
     * @param  array<string, mixed>  $entry
     * @return array<int, array<string, mixed>>
     */
    private function upsertEntry(array $entries, array $entry): array
    {
        $key = strtolower((string) ($entry['name'] ?? ''));

        foreach ($entries as $index => $existing) {
            $existingKey = strtolower((string) ($existing['name'] ?? ''));

            if ($existingKey === $key) {
                $entries[$index] = $entry;

                return $entries;
            }
        }

        $entries[] = $entry;

        return $entries;
    }

    /**
     * Remove an entry by name (case-insensitive).
     *
     * @param  array<int, array<string, mixed>>  $entries
     * @return array<int, array<string, mixed>>
     */
    private function removeEntry(array $entries, string $name): array
    {
        $key = strtolower($name);

        return array_values(array_filter($entries, function ($entry) use ($key) {
            return strtolower((string) ($entry['name'] ?? '')) !== $key;
        }));
    }

    /**
     * The version-3 offline UUID Minecraft derives from a player name. Used when
     * the server is offline so file entries carry a stable, unique identifier.
     */
    private function uuidFor(string $name): string
    {
        $data = hash('md5', 'OfflinePlayer:'.$name, true);
        $data[6] = chr((ord($data[6]) & 0x0F) | 0x30); // version 3
        $data[8] = chr((ord($data[8]) & 0x3F) | 0x80); // variant 1

        return vsprintf('%02x%02x%02x%02x-%02x%02x-%02x%02x-%02x%02x-%02x%02x%02x%02x%02x%02x', unpack('C16', $data));
    }

    private function assertUsername(string $name): void
    {
        Assert::regex($name, '/^[A-Za-z0-9_]{1,16}$/', 'Invalid Minecraft username.');
    }

    private function sendCommand(Server $server, string $command): void
    {
        $this->commandRepository->setServer($server)->send($command);
    }

    /**
     * Read the tail of the server's log file.
     */
    private function readLogTail(Server $server): string
    {
        $paths = ['/logs/latest.log', '/logs/console.log'];

        foreach ($paths as $path) {
            try {
                return $this->fileRepository->setServer($server)->getContent($path, 2_000_000);
            } catch (DaemonConnectionException|FileSizeTooLargeException) {
                // Try the next candidate path.
            }
        }

        return '';
    }
}
