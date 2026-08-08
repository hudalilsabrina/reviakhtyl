<?php

namespace App\Services\Servers;

use App\Models\Server;
use Illuminate\Support\Str;

class StartupCommandService
{
    public function __construct(private EnvironmentService $environmentService) {}

    /**
     * Generates a startup command for a given server instance.
     */
    public function handle(Server $server, bool $hideAllValues = false): string
    {
        $find = ['{{SERVER_MEMORY}}', '{{SERVER_IP}}', '{{SERVER_PORT}}'];
        $replace = [$server->memory, $server->allocation->ip, $server->allocation->port];

        foreach ($server->variables as $variable) {
            $find[] = '{{'.$variable->env_variable.'}}';
            $replace[] = ($variable->user_viewable && ! $hideAllValues) ? ($variable->server_value ?? $variable->default_value) : '[hidden]';
        }

        $find[] = '{{STARTUP_PARTS}}';
        $replace[] = $hideAllValues ? '[hidden]' : $this->environmentService->handle($server)['STARTUP_PARTS'] ?? '';

        return Str::replace($find, $replace, $server->startup);
    }
}
