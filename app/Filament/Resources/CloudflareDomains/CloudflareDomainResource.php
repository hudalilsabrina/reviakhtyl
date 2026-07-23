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
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;

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
            TextInput::make('domain')
                ->label('Domain')
                ->required()
                ->unique(ignoreRecord: true)
                ->maxLength(191)
                ->placeholder('example.com'),

            TextInput::make('zone_id')
                ->label('Zone ID')
                ->required()
                ->maxLength(191),

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
                DeleteAction::make()
                    ->before(function (CloudflareDomain $record, DeleteAction $action) {
                        $count = $record->subdomains()->count();

                        if ($count > 0) {
                            Notification::make()
                                ->title('Cannot delete domain')
                                ->body($count.' subdomain(s) still use this domain. Remove them first or disable the domain instead.')
                                ->danger()
                                ->send();

                            $action->cancel();
                        }
                    }),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->before(function (Collection $records, DeleteBulkAction $action) {
                            $blocked = $records->filter(fn ($record) => $record instanceof CloudflareDomain && $record->subdomains()->exists());

                            if ($blocked->isNotEmpty()) {
                                Notification::make()
                                    ->title('Cannot delete domains')
                                    ->body('In use: '.$blocked->pluck('domain')->implode(', ').'. Remove their subdomains first or disable them instead.')
                                    ->danger()
                                    ->send();

                                $action->cancel();
                            }
                        }),
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
