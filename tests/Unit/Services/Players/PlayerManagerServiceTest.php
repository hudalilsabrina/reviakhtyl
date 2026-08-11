<?php

namespace Tests\Unit\Services\Players;

use App\Contracts\Repository\SettingsRepositoryInterface;
use App\Models\Server;
use App\Repositories\Agent\DaemonCommandRepository;
use App\Repositories\Agent\DaemonFileRepository;
use App\Services\Players\PlayerManagerService;
use Mockery;
use Tests\TestCase;

class PlayerManagerServiceTest extends TestCase
{
    private PlayerManagerService $service;

    /** @var DaemonCommandRepository|MockInterface */
    private $command;

    /** @var DaemonFileRepository|MockInterface */
    private $files;

    protected function setUp(): void
    {
        parent::setUp();

        $this->command = Mockery::mock(DaemonCommandRepository::class);
        $this->files = Mockery::mock(DaemonFileRepository::class);
        $settings = Mockery::mock(SettingsRepositoryInterface::class);

        $this->service = new PlayerManagerService($this->command, $this->files, $settings);
    }

    public function test_offline_whitelist_add_writes_file_with_offline_uuid(): void
    {
        $server = $this->mockServer('offline');

        $this->files->shouldReceive('setServer')->andReturnSelf();
        $this->files->shouldReceive('getContent')->with('/whitelist.json', 2_000_000)->andReturn('[]');
        $this->files->shouldReceive('putContent')->withArgs(function (string $path, string $content) {
            $decoded = json_decode($content, true);

            return $path === '/whitelist.json'
                && count($decoded) === 1
                && $decoded[0]['name'] === 'Steve'
                && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-3[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $decoded[0]['uuid']) === 1;
        })->once();

        $this->service->whitelistAdd($server, 'Steve');
    }

    public function test_online_op_sends_command_instead_of_writing_file(): void
    {
        $server = $this->mockServer('running');

        $this->command->shouldReceive('setServer')->andReturnSelf();
        $this->command->shouldReceive('send')->with('op Notch')->once();

        $this->files->shouldNotReceive('getContent');
        $this->files->shouldNotReceive('putContent');

        $this->service->op($server, 'Notch', 4);
    }

    public function test_offline_unban_removes_case_insensitively(): void
    {
        $server = $this->mockServer('offline');

        $existing = json_encode([
            ['uuid' => 'a', 'name' => 'Notch'],
            ['uuid' => 'b', 'name' => 'Steve'],
        ]);

        $this->files->shouldReceive('setServer')->andReturnSelf();
        $this->files->shouldReceive('getContent')->with('/banned-players.json', 2_000_000)->andReturn($existing);
        $this->files->shouldReceive('putContent')->withArgs(function (string $path, string $content) {
            $decoded = json_decode($content, true);

            return $path === '/banned-players.json' && count($decoded) === 1 && $decoded[0]['name'] === 'Steve';
        })->once();

        $this->service->unban($server, 'notch');
    }

    public function test_online_players_parsed_from_log_tail(): void
    {
        $server = $this->mockServer('running');

        $log = implode("\n", [
            '[12:00:00] [Server thread/INFO]: Steve joined the game',
            '[12:01:00] [Server thread/INFO]: Alex joined the game',
            '[12:02:00] [Server thread/INFO]: Steve left the game',
        ]);

        $this->files->shouldReceive('setServer')->andReturnSelf();
        $this->files->shouldReceive('getContent')->with('/logs/latest.log', 2_000_000)->andReturn($log);

        $this->assertSame(['Alex'], $this->service->online($server));
    }

    private function mockServer(string $status): Server|MockInterface
    {
        $server = Mockery::mock(Server::class);
        $server->shouldReceive('getResolvedStatus')->andReturn($status);
        $server->shouldReceive('getAttribute')->with('egg_id')->andReturn(1);

        return $server;
    }
}
