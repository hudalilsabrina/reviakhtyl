<?php

namespace App\Http\Controllers\Api\Client\Servers;

use App\Exceptions\Model\DataValidationException;
use App\Exceptions\Repository\RecordNotFoundException;
use App\Facades\Activity;
use App\Http\Controllers\Api\Client\ClientApiController;
use App\Http\Requests\Api\Client\Servers\Startup\GetStartupRequest;
use App\Http\Requests\Api\Client\Servers\Startup\UpdateStartupVariableRequest;
use App\Models\Server;
use App\Repositories\Eloquent\ServerVariableRepository;
use App\Services\Servers\StartupCommandService;
use App\Transformers\Api\Client\EggVariableTransformer;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

class StartupController extends ClientApiController
{
    /**
     * StartupController constructor.
     */
    public function __construct(
        private StartupCommandService $startupCommandService,
        private ServerVariableRepository $repository,
    ) {
        parent::__construct();
    }

    /**
     * Returns the startup information for the server including all the variables.
     */
    public function index(GetStartupRequest $request, Server $server): array
    {
        $startup = $this->startupCommandService->handle($server);

        return $this->fractal->collection(
            $server->variables()->where('user_viewable', true)->get()
        )
            ->transformWith($this->getTransformer(EggVariableTransformer::class))
            ->addMeta([
                'startup_command' => $startup,
                'docker_images' => $server->egg->docker_images,
                'raw_startup_command' => $server->startup,
            ])
            ->toArray();

        $eggParts = $server->egg->startupParts;

        if ($eggParts->isNotEmpty()) {
            $choices = $server->startupPartChoices();

            $parts = $this->fractal->collection($eggParts)
                ->transformWith($this->getTransformer(EggStartupPartTransformer::class))
                ->toArray();

            foreach ($parts['data'] as &$part) {
                $part['attributes']['user_enabled'] = $choices[$part['attributes']['id']]
                    ?? $part['attributes']['default_enabled'];
            }

            $response['meta']['startup_parts'] = $parts['data'];
            $response['meta']['has_modular_startup'] = true;
        }

        return $response;
    }

    /**
     * Updates a single variable for a server.
     *
     * @throws ValidationException
     * @throws DataValidationException
     * @throws RecordNotFoundException
     */
    public function update(UpdateStartupVariableRequest $request, Server $server): array
    {
        $variable = $server->variables()->where('env_variable', $request->input('key'))->first();

        if (is_null($variable) || ! $variable->user_viewable) {
            throw new BadRequestHttpException('The environment variable you are trying to edit does not exist.');
        } elseif (! $variable->user_editable) {
            throw new BadRequestHttpException('The environment variable you are trying to edit is read-only.');
        }

        $original = $variable->server_value;

        // Revalidate the variable value using the egg variable specific validation rules for it.
        $this->validate($request, ['value' => $variable->rules]);

        $this->repository->updateOrCreate([
            'server_id' => $server->id,
            'variable_id' => $variable->id,
        ], [
            'variable_value' => $request->input('value') ?? '',
        ]);

        $variable = $variable->refresh();
        $variable->server_value = $request->input('value');

        $startup = $this->startupCommandService->handle($server);

        if ($original !== $request->input('value')) {
            Activity::event('server:startup.edit')
                ->subject($variable)
                ->property([
                    'variable' => $variable->env_variable,
                    'old' => $original,
                    'new' => $request->input('value') ?? '',
                ])
                ->log();
        }

        return $this->fractal->item($variable)
            ->transformWith($this->getTransformer(EggVariableTransformer::class))
            ->addMeta([
                'startup_command' => $startup,
                'raw_startup_command' => $server->startup,
            ])
            ->toArray();
    }

    /**
     * Updates the enabled/disabled state of the egg's modular startup parts for a server.
     *
     * @throws ValidationException
     */
    public function updateParts(UpdateStartupPartsRequest $request, Server $server): array
    {
        $eggParts = $server->egg->startupParts;

        if ($eggParts->isEmpty()) {
            throw new BadRequestHttpException('This server does not have configurable startup parts.');
        }

        // The `boolean` validation rule accepts "0" and "1" without casting them,
        // and "0" is truthy. Normalize before anything decides what is enabled,
        // otherwise a required part can be disabled through the guard below.
        $requested = collect($request->input('parts', []))
            ->map(fn (array $part) => [
                'part_id' => (int) $part['part_id'],
                'enabled' => filter_var($part['enabled'], FILTER_VALIDATE_BOOLEAN),
            ]);

        $validIds = $eggParts->pluck('id');

        if ($invalid = $requested->pluck('part_id')->diff($validIds)->first()) {
            throw new BadRequestHttpException("Invalid startup part ID: {$invalid}");
        }

        foreach ($eggParts->where('required', true) as $part) {
            $choice = $requested->firstWhere('part_id', $part->id);

            if (! ($choice['enabled'] ?? $part->default_enabled)) {
                throw new BadRequestHttpException("The startup part '{$part->name}' is required and cannot be disabled.");
            }
        }

        // Only persist parts the user explicitly sent; omitted parts fall back to their default state.
        $server->update([
            'startup_parts' => $requested->values()->all(),
        ]);

        $startup = $this->startupCommandService->handle($server->refresh());

        Activity::event('server:startup.edit')
            ->subject($server)
            ->property([
                'variable' => 'startup_parts',
                'old' => null,
                'new' => json_encode($server->startup_parts),
            ])
            ->log();

        return [
            'meta' => [
                'startup_command' => $startup,
                'raw_startup_command' => $server->startup,
            ],
        ];
    }
}
