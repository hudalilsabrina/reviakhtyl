<?php

namespace App\Filament\Resources\Nests\Eggs\Pages;

use App\Filament\Resources\Nests\EggResource;
use Filament\Resources\Pages\CreateRecord;

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

        $data['features'] = self::mergeSubdomainFeature($data['features'] ?? [], $this->data['feature_subdomain'] ?? false);

        return $data;
    }

    /**
     * @param  array<int, string>|null  $features
     * @return array<int, string>
     */
    public static function mergeSubdomainFeature(?array $features, bool $enabled): array
    {
        $features = array_values(array_filter($features ?? [], fn ($f) => $f !== 'subdomain'));

        if ($enabled) {
            $features[] = 'subdomain';
        }

        return $features;
    }

    protected function getRedirectUrl(): string
    {
        return EggResource::getUrl('edit', ['record' => $this->record]);
    }
}
