<?php

namespace App\Filament\Resources\CloudflareDomains\Pages;

use App\Filament\Resources\CloudflareDomains\CloudflareDomainResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCloudflareDomains extends ListRecords
{
    protected static string $resource = CloudflareDomainResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
