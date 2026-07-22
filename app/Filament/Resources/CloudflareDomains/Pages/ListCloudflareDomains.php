<?php

namespace App\Filament\Resources\CloudflareDomains\Pages;

use App\Contracts\Repository\SettingsRepositoryInterface;
use App\Filament\Resources\CloudflareDomains\CloudflareDomainResource;
use App\Models\CloudflareDomain;
use App\Services\Servers\CloudflareSubdomainService;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\Encryption\Encrypter;

class ListCloudflareDomains extends ListRecords
{
    protected static string $resource = CloudflareDomainResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('import_zones')
                ->label('Import from Cloudflare')
                ->icon('tabler-cloud-download')
                ->modalSubmitActionLabel('Add Selected')
                ->form([
                    TextInput::make('api_token')
                        ->label('API Token')
                        ->helperText('Defaults to the token saved in Settings.')
                        ->password()
                        ->revealable()
                        ->required()
                        ->default(fn () => self::defaultToken()),

                    CheckboxList::make('zones')
                        ->label('Zones')
                        ->helperText('Only zones not already added are shown.')
                        ->options(function (callable $get): array {
                            $token = $get('api_token');

                            if (empty($token)) {
                                return [];
                            }

                            try {
                                $zones = CloudflareSubdomainService::fetchZones($token);
                            } catch (\Throwable) {
                                return [];
                            }

                            $existing = CloudflareDomain::query()->pluck('zone_id')->all();

                            return collect($zones)->except($existing)->all();
                        })
                        ->descriptions(fn (): array => [])
                        ->bulkToggleable()
                        ->required()
                        ->minItems(1),
                ])
                ->action(function (array $data) {
                    $zones = CloudflareSubdomainService::fetchZones($data['api_token']);

                    $created = 0;

                    foreach ($data['zones'] as $zoneId) {
                        if (! isset($zones[$zoneId])) {
                            continue;
                        }

                        CloudflareDomain::query()->firstOrCreate(
                            ['zone_id' => $zoneId],
                            ['domain' => $zones[$zoneId], 'is_enabled' => true]
                        );

                        $created++;
                    }

                    Notification::make()->title($created.' domain(s) added.')->success()->send();
                }),

            CreateAction::make()->label('Add Manually'),
        ];
    }

    private static function defaultToken(): ?string
    {
        $value = app(SettingsRepositoryInterface::class)->get('settings::panel:cloudflare:api_token', null);

        if (empty($value)) {
            return null;
        }

        try {
            return app(Encrypter::class)->decrypt($value);
        } catch (\Throwable) {
            return $value;
        }
    }
}
