<?php

namespace App\Services\Servers;

use App\Models\EggVariable;
use App\Models\Server;

class EnvironmentService
{
    private array $additional = [];

    /**
     * Dynamically configure additional environment variables to be assigned
     * with a specific server.
     */
    public function setEnvironmentKey(string $key, callable $closure): void
    {
        $this->additional[$key] = $closure;
    }

    /**
     * Return the dynamically added additional keys.
     */
    public function getEnvironmentKeys(): array
    {
        return $this->additional;
    }

    /**
     * Take all of the environment variables configured for this server and return
     * them in an easy to process format.
     */
    public function handle(Server $server): array
    {
        $variables = $server->variables->toBase()->mapWithKeys(function (EggVariable $variable) {
            return [$variable->env_variable => $variable->server_value ?? $variable->default_value];
        });

        // Process environment variables defined in this file. This is done first
        // in order to allow run-time and config defined variables to take
        // priority over built-in values.
        foreach ($this->getEnvironmentMappings() as $key => $object) {
            $variables->put($key, object_get($server, $object));
        }

        // Process variables set in the configuration file.
        foreach (config('panel.environment_variables', []) as $key => $object) {
            $variables->put(
                $key,
                is_callable($object) ? call_user_func($object, $server) : object_get($server, $object)
            );
        }

        // Process dynamically included environment variables.
        foreach ($this->additional as $key => $closure) {
            $variables->put($key, call_user_func($closure, $server));
        }

        $variables->put('STARTUP_PARTS', $this->buildStartupParts($server));

        return $variables->toArray();
    }

    /**
     * Builds the concatenated string of enabled modular startup parts for the server,
     * exposed as the {{STARTUP_PARTS}} placeholder in startup commands.
     */
    private function buildStartupParts(Server $server): string
    {
        $parts = $server->egg->startupParts;

        if ($parts->isEmpty()) {
            return '';
        }

        $choices = collect($server->startup_parts ?? [])
            ->filter(fn ($choice) => is_array($choice) && isset($choice['part_id']))
            ->keyBy('part_id');

        return $parts
            ->filter(fn ($part) => ($choices[$part->id]['enabled'] ?? $part->default_enabled) && trim($part->value) !== '')
            ->map(fn ($part) => trim($part->value))
            ->implode(' ');
    }

    /**
     * Return a mapping of Panel default environment variables.
     */
    private function getEnvironmentMappings(): array
    {
        return [
            'STARTUP' => 'startup',
            'P_SERVER_LOCATION' => 'location.short',
            'P_SERVER_UUID' => 'uuid',
        ];
    }
}
