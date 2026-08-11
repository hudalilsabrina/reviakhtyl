<?php

namespace App\Http\Controllers\Api\Client\Servers;

use App\Exceptions\DisplayException;
use App\Facades\Activity;
use App\Http\Controllers\Api\Client\ClientApiController;
use App\Http\Requests\Api\Client\Servers\Subdomain\DeleteSubdomainRequest;
use App\Http\Requests\Api\Client\Servers\Subdomain\GetSubdomainRequest;
use App\Http\Requests\Api\Client\Servers\Subdomain\StoreSubdomainRequest;
use App\Models\Server;
use App\Services\Servers\CloudflareSubdomainService;
use App\Transformers\Api\Client\ServerSubdomainTransformer;
use Illuminate\Http\JsonResponse;

class SubdomainController extends ClientApiController
{
    public function __construct(private CloudflareSubdomainService $subdomainService)
    {
        parent::__construct();
    }

    public function index(GetSubdomainRequest $request, Server $server): array
    {
        if (! $this->subdomainService->isEnabledFor($server)) {
            throw new DisplayException('Subdomains are not available for this server.');
        }

        $subdomain = $server->subdomain;

        return [
            'enabled' => true,
            'domains' => $this->subdomainService->domains()
                ->map(fn ($domain) => ['id' => $domain->id, 'domain' => $domain->domain])
                ->values()
                ->all(),
            'suggested' => $this->subdomainService->sanitize($server->name),
            'subdomain' => $subdomain
                ? $this->fractal->item($subdomain)
                    ->transformWith($this->getTransformer(ServerSubdomainTransformer::class))
                    ->toArray()
                : null,
        ];
    }

    public function store(StoreSubdomainRequest $request, Server $server): array
    {
        if (! $this->subdomainService->isEnabledFor($server)) {
            throw new DisplayException('Subdomains are not available for this server.');
        }

        $isNew = $server->subdomain === null;

        $subdomain = $this->subdomainService->store(
            $server,
            $request->input('subdomain'),
            $request->integer('domain_id')
        );

        Activity::event($isNew ? 'server:subdomain.create' : 'server:subdomain.update')
            ->subject($subdomain)
            ->property('subdomain', $subdomain->getFqdn())
            ->log();

        return $this->fractal->item($subdomain)
            ->transformWith($this->getTransformer(ServerSubdomainTransformer::class))
            ->toArray();
    }

    public function status(GetSubdomainRequest $request, Server $server): JsonResponse
    {
        $subdomain = $server->subdomain;

        if (! $subdomain) {
            return new JsonResponse([
                'has_subdomain' => false,
                'propagated' => false,
            ]);
        }

        return new JsonResponse([
            'has_subdomain' => true,
            'propagated' => $this->subdomainService->isPropagated($subdomain),
        ]);
    }

    public function delete(DeleteSubdomainRequest $request, Server $server): JsonResponse
    {
        // No isEnabledFor gate here on purpose: removing a subdomain is a cleanup
        // operation. If an admin disables the egg/domain, the owner must still be
        // able to tear the subdomain down — otherwise it is stranded forever.
        // destroy() no-ops when there is nothing to delete, so this is safe.

        $fqdn = $server->subdomain?->getFqdn();

        $this->subdomainService->destroy($server);

        if ($fqdn) {
            Activity::event('server:subdomain.delete')
                ->property('subdomain', $fqdn)
                ->log();
        }

        return new JsonResponse([], JsonResponse::HTTP_NO_CONTENT);
    }
}
