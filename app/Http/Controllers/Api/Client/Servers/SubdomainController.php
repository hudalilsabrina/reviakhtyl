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
            'domain' => $this->subdomainService->domain(),
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

        $subdomain = $this->subdomainService->store($server, $request->input('subdomain'));

        Activity::event('server:subdomain.manage')
            ->subject($subdomain)
            ->property('subdomain', $subdomain->getFqdn())
            ->log();

        return $this->fractal->item($subdomain)
            ->transformWith($this->getTransformer(ServerSubdomainTransformer::class))
            ->toArray();
    }

    public function delete(DeleteSubdomainRequest $request, Server $server): JsonResponse
    {
        if (! $this->subdomainService->isEnabledFor($server)) {
            throw new DisplayException('Subdomains are not available for this server.');
        }

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
