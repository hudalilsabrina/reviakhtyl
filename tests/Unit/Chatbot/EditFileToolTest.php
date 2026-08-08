<?php

use App\Exceptions\DisplayException;
use App\Models\Server;
use App\Models\User;
use App\Repositories\Agent\DaemonFileRepository;
use App\Services\Chatbot\ToolContext;
use App\Services\Chatbot\Tools\Files\EditFileTool;

function editFileToolFixture(): array
{
    $repository = Mockery::mock(DaemonFileRepository::class);
    $repository->shouldReceive('setServer')->andReturnSelf();

    return [new EditFileTool($repository), $repository];
}

function editFileContext(): ToolContext
{
    return new ToolContext(Mockery::mock(Server::class), Mockery::mock(User::class));
}

it('replaces the single occurrence and writes the merged content back', function () {
    [$tool, $repository] = editFileToolFixture();

    $repository->shouldReceive('getContent')->with('/server.properties', Mockery::any())->andReturn(
        "motd=A boring server\ngamemode=survival\n"
    );
    $repository->shouldReceive('putContent')->with(
        '/server.properties',
        "motd=A boring server\ngamemode=creative\n"
    )->once();

    $result = $tool->handle(editFileContext(), [
        'path' => '/server.properties',
        'old' => 'gamemode=survival',
        'new' => 'gamemode=creative',
    ]);

    expect($result['message'])->toBeString()->not->toBe('');
});

it('fails without writing when the old text is not present', function () {
    [$tool, $repository] = editFileToolFixture();

    $repository->shouldReceive('getContent')->andReturn("motd=A boring server\n");
    $repository->shouldNotReceive('putContent');

    $tool->handle(editFileContext(), [
        'path' => '/server.properties',
        'old' => 'gamemode=survival',
        'new' => 'gamemode=creative',
    ]);
})->throws(DisplayException::class, 'not found');

it('fails without writing when the old text is whitespace only', function () {
    [$tool, $repository] = editFileToolFixture();

    $repository->shouldReceive('getContent')->with('/server.properties', Mockery::any())->andReturn("motd=A boring server\n");
    $repository->shouldNotReceive('putContent');

    $tool->handle(editFileContext(), [
        'path' => '/server.properties',
        'old' => '   ',
        'new' => 'x',
    ]);
})->throws(DisplayException::class, 'whitespace');

it('fails without writing when the old text matches more than once', function () {
    [$tool, $repository] = editFileToolFixture();

    $repository->shouldReceive('getContent')->andReturn("spawn=1\nspawn=1\n");
    $repository->shouldNotReceive('putContent');

    $tool->handle(editFileContext(), [
        'path' => '/server.properties',
        'old' => 'spawn=1',
        'new' => 'spawn=2',
    ]);
})->throws(DisplayException::class, 'more than once');
