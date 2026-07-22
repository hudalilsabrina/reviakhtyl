<?php

namespace App\Filament\Resources\CloudflareDomains;

use App\Filament\Resources\CloudflareDomains\Pages\ListCloudflareDomains;
use App\Models\CloudflareDomain;
use App\Services\Servers\CloudflareSubdomainService;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Contracts\Encryption\Encrypter;

class CloudflareDomainResource extends Resource
{
    protected static ?string $model = CloudflareDomain::class;

    protected static string|\BackedEnum|null $navigationIcon = 'tabler-cloud';

    protected static string|\BackedEnum|null $activeNavigationIcon = 'tabler-cloud-filled';

    public static function getNavigationGroup(): ?string
    {
        return trans('admin/navigation.administration.title');
    }

    public static function getNavigationLabel(): string
    {
        return 'Domains';
    }

    public static function getModelLabel(): string
    {
        return 'domain';
    }

    public static function getPluralModelLabel(): string
    {
        return 'domains';
    }

    public static function getNavigationBadge(): ?string
    {
        return CloudflareDomain::count() > 0 ? (string) CloudflareDomain::count() : null;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('api_token_lookup')
                ->label('API Token')
                ->helperText('Used for the zone lookup below and as this domain\'s token if "Custom API Token" is left empty.')
                ->password()
                ->revealable()
                ->live()
                ->dehydrated(false)
                ->afterStateUpdated(fn (Set $set) => $set('zone_id', null))
                ->columnSpanFull(),

            Select::make('zone_id')
                ->label('Zone')
                ->required()
                ->searchable()
                ->native(false)
                ->disabled(fn (Get $get) => empty($get('api_token_lookup')))
                ->helperText(fn (Get $get) => empty($get('api_token_lookup'))
                    ? 'Enter an API token above to load your zones.'
                    : 'Pick the zone for this domain.')
                ->options(function (Get $get): array {
                    $token = $get('api_token_lookup');

                    if (empty($token)) {
                        return [];
                    }

                    try {
                        return CloudflareSubdomainService::fetchZones($token);
                    } catch (\Throwable) {
                        return [];
                    }
                })
                ->afterStateUpdated(function ($state, Set $set, Get $get) {
                    $token = $get('api_token_lookup');

                    if (! $state || empty($token)) {
                        return;
                    }

                    try {
                        $zones = CloudflareSubdomainService::fetchZones($token);
                        if (isset($zones[$state])) {
                            $set('domain', $zones[$state]);
                        }
                    } catch (\Throwable) {
                    }
                })
                ->columnSpanFull(),

            TextInput::make('domain')
                ->label('Domain')
                ->required()
                ->unique(ignoreRecord: true)
                ->maxLength(191)
                ->placeholder('example.com'),

            TextInput::make('api_token')
                ->label('Custom API Token')
                ->helperText('Optional. Overrides the default token from Settings for this domain only.')
                ->password()
                ->revealable()
                ->maxLength(191)
                ->dehydrateStateUsing(fn (?string $state) => filled($state)
                    ? app(Encrypter::class)->encrypt($state)
                    : null)
                ->afterStateHydrated(fn (TextInput $component) => $component->state(null)),

            Toggle::make('is_enabled')
                ->label('Enabled')
                ->helperText('Disabled domains are hidden from users but keep existing subdomains working.')
                ->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('domain')
                    ->label('Domain')
                    ->searchable()
                    ->sortable()
                    ->weight('medium'),

                TextColumn::make('zone_id')
                    ->label('Zone ID')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('subdomains_count')
                    ->label('Subdomains')
                    ->counts('subdomains')
                    ->sortable(),

                IconColumn::make('api_token')
                    ->label('Custom Token')
                    ->boolean()
                    ->state(fn (CloudflareDomain $record): bool => filled($record->api_token))
                    ->toggleable(),

                IconColumn::make('is_enabled')
                    ->label('Enabled')
                    ->boolean()
                    ->sortable(),
            ])
            ->recordActions([
                Action::make('test')
                    ->label('Test')
                    ->icon('tabler-plug-connected')
                    ->color('gray')
                    ->action(function (CloudflareDomain $record) {
                        try {
                            app(CloudflareSubdomainService::class)->testDomain($record);

                            Notification::make()->title('Connection OK')->success()->send();
                        } catch (\Throwable $e) {
                            Notification::make()->title('Connection failed')->body($e->getMessage())->danger()->send();
                        }
                    }),
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCloudflareDomains::route('/'),
        ];
    }
}
