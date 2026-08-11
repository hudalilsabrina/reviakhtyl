<?php

namespace App\Filament\Resources\Nests\Eggs\Pages;

use App\Filament\Resources\Nests\EggResource;
use Filament\Resources\Pages\CreateRecord;
use Ramsey\Uuid\Uuid;

class CreateEgg extends CreateRecord
{
    protected static string $resource = EggResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // Set nest_id from query parameter if provided
        $nestId = request()->query('nest_id');
        if ($nestId) {
            $data['nest_id'] = $nestId;
        }

        // uuid and author are disabled/non-dehydrated in the form but are required
        // by the egg model. Default them here so model validation passes.
        $data['uuid'] = Uuid::uuid4()->toString();
        $data['author'] ??= config('panel.service.author');

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return EggResource::getUrl('edit', ['record' => $this->record]);
    }
}
