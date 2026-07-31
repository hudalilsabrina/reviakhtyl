<?php

namespace App\Http\Controllers\Api\Client\Servers;

use App\Exceptions\DisplayException;
use App\Facades\Activity;
use App\Http\Controllers\Api\Client\ClientApiController;
use App\Http\Requests\Api\Client\Servers\Properties\AcceptEulaRequest;
use App\Http\Requests\Api\Client\Servers\Properties\GetPropertiesRequest;
use App\Http\Requests\Api\Client\Servers\Properties\UpdatePropertiesRequest;
use App\Http\Requests\Api\Client\Servers\Properties\UpdateRawPropertiesRequest;
use App\Models\Server;
use App\Services\Properties\ServerPropertiesService;

class PropertiesController extends ClientApiController
{
    public function __construct(private ServerPropertiesService $service)
    {
        parent::__construct();
    }

    /**
     * Return the parsed properties file along with the schema used to render it.
     */
    public function index(GetPropertiesRequest $request, Server $server): array
    {
        $this->ensureEnabled($server);

        return $this->present($server, $this->service->read($server));
    }

    /**
     * Write a set of changed properties back to the file.
     */
    public function update(UpdatePropertiesRequest $request, Server $server): array
    {
        $this->ensureEnabled($server);

        /** @var array<string, mixed> $submitted */
        $submitted = $request->input('properties');
        $normalized = $this->service->normalize($submitted);

        $state = $this->service->applyNormalized($server, $normalized);

        if ($normalized !== []) {
            Activity::event('server:properties.update')
                ->property('properties', array_keys($normalized))
                ->log();
        }

        return $this->present($server, $state);
    }

    /**
     * Overwrite the entire properties file with user supplied content.
     */
    public function updateRaw(UpdateRawPropertiesRequest $request, Server $server): array
    {
        $this->ensureEnabled($server);

        $state = $this->service->updateRaw($server, (string) $request->input('content'));

        Activity::event('server:properties.update-raw')->log();

        return $this->present($server, $state);
    }

    /**
     * Accept the Minecraft EULA on behalf of the user.
     */
    public function acceptEula(AcceptEulaRequest $request, Server $server): array
    {
        $this->ensureEnabled($server);

        $this->service->acceptEula($server);

        Activity::event('server:properties.eula')->log();

        return ['eula_accepted' => true];
    }

    private function ensureEnabled(Server $server): void
    {
        if (! $this->service->isEnabledFor($server)) {
            throw new DisplayException('The properties editor is not available for this server.');
        }
    }

    /**
     * Merge the parsed file with the schema so the client can render a form
     * without knowing anything about Minecraft. Keys that are not in the schema
     * are appended to the "other" group as free-text fields.
     *
     * @param  array{exists: bool, raw: string, values: array<string, string>}  $state
     * @return array<string, mixed>
     */
    private function present(Server $server, array $state): array
    {
        $definitions = $this->service->definitions();
        $labels = trans('server/properties.fields');
        $labels = is_array($labels) ? $labels : [];

        $grouped = [];

        foreach ($definitions as $key => $definition) {
            $grouped[$definition['group'] ?? 'other'][] = [
                'key' => $key,
                'type' => $definition['type'] ?? 'string',
                'default' => $this->stringify($definition['default'] ?? ''),
                'options' => $definition['options'] ?? null,
                'min' => $definition['min'] ?? null,
                'max' => $definition['max'] ?? null,
                'locked' => (bool) ($definition['locked'] ?? false),
                'sensitive' => (bool) ($definition['sensitive'] ?? false),
                'warn' => (bool) ($definition['warn'] ?? false),
                'label' => $labels[$key]['label'] ?? $key,
                'description' => $labels[$key]['description'] ?? null,
            ];
        }

        foreach (array_keys($state['values']) as $key) {
            if (isset($definitions[$key])) {
                continue;
            }

            $grouped['other'][] = [
                'key' => $key,
                'type' => 'string',
                'default' => '',
                'options' => null,
                'min' => null,
                'max' => null,
                'locked' => false,
                'sensitive' => false,
                'warn' => false,
                'label' => $key,
                'description' => null,
            ];
        }

        $groupLabels = trans('server/properties.groups');
        $groupLabels = is_array($groupLabels) ? $groupLabels : [];

        $groups = [];

        foreach ($this->service->groups() as $group) {
            if (empty($grouped[$group])) {
                continue;
            }

            $groups[] = [
                'id' => $group,
                'label' => $groupLabels[$group] ?? $group,
                'properties' => $grouped[$group],
            ];
        }

        return [
            'exists' => $state['exists'],
            'eula_accepted' => $this->service->eulaAccepted($server),
            'raw' => $state['raw'],
            'values' => $state['values'],
            'groups' => $groups,
        ];
    }

    private function stringify(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return is_scalar($value) ? (string) $value : '';
    }
}
