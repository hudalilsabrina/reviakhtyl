<?php

namespace App\Http\Controllers\Api\Client\Servers;

use App\Exceptions\DisplayException;
use App\Facades\Activity;
use App\Http\Controllers\Api\Client\ClientApiController;
use App\Http\Requests\Api\Client\Servers\Players\BanPlayerRequest;
use App\Http\Requests\Api\Client\Servers\Players\DeletePlayerRequest;
use App\Http\Requests\Api\Client\Servers\Players\GetPlayerStatusRequest;
use App\Http\Requests\Api\Client\Servers\Players\OpPlayerRequest;
use App\Http\Requests\Api\Client\Servers\Players\WhitelistPlayerRequest;
use App\Models\Server;
use App\Services\Players\PlayerManagerService;
use Illuminate\Http\Response;

class PlayerController extends ClientApiController
{
    public function __construct(private PlayerManagerService $manager)
    {
        parent::__construct();
    }

    public function index(GetPlayerStatusRequest $request, Server $server): array
    {
        $this->assertEnabled($server);

        return $this->manager->status($server);
    }

    public function online(GetPlayerStatusRequest $request, Server $server): array
    {
        $this->assertEnabled($server);

        return [
            'online' => $this->manager->isOnline($server),
            'players' => $this->manager->online($server),
        ];
    }

    public function whitelistAdd(WhitelistPlayerRequest $request, Server $server): Response
    {
        $this->assertEnabled($server);

        $this->manager->whitelistAdd($server, $request->input('name'));

        Activity::event('server:player.whitelist-add')
            ->property('name', $request->input('name'))
            ->log();

        return $this->returnNoContent();
    }

    public function whitelistRemove(DeletePlayerRequest $request, Server $server, string $name): Response
    {
        $this->assertEnabled($server);

        $this->manager->whitelistRemove($server, $name);

        Activity::event('server:player.whitelist-remove')
            ->property('name', $name)
            ->log();

        return $this->returnNoContent();
    }

    public function op(OpPlayerRequest $request, Server $server): Response
    {
        $this->assertEnabled($server);

        $this->manager->op($server, $request->input('name'), (int) $request->input('level', 4));

        Activity::event('server:player.op')
            ->property('name', $request->input('name'))
            ->property('level', (int) $request->input('level', 4))
            ->log();

        return $this->returnNoContent();
    }

    public function deop(DeletePlayerRequest $request, Server $server, string $name): Response
    {
        $this->assertEnabled($server);

        $this->manager->deop($server, $name);

        Activity::event('server:player.deop')
            ->property('name', $name)
            ->log();

        return $this->returnNoContent();
    }

    public function ban(BanPlayerRequest $request, Server $server): Response
    {
        $this->assertEnabled($server);

        $this->manager->ban($server, $request->input('name'), $request->input('reason'));

        Activity::event('server:player.ban')
            ->property('name', $request->input('name'))
            ->log();

        return $this->returnNoContent();
    }

    public function unban(DeletePlayerRequest $request, Server $server, string $name): Response
    {
        $this->assertEnabled($server);

        $this->manager->unban($server, $name);

        Activity::event('server:player.unban')
            ->property('name', $name)
            ->log();

        return $this->returnNoContent();
    }

    private function assertEnabled(Server $server): void
    {
        if (! $this->manager->isEnabledFor($server)) {
            throw new DisplayException('Player management is not available for this server.');
        }
    }
}
