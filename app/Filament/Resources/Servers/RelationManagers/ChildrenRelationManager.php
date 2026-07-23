<?php

namespace App\Filament\Resources\Servers\RelationManagers;

use App\Filament\Resources\Servers\ServerResource;
use App\Models\Server;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class ChildrenRelationManager extends RelationManager
{
    protected static string $relationship = 'children';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('name')
                    ->label(trans('admin/server.children.table.name'))
                    ->searchable()
                    ->sortable()
                    ->url(fn (Server $record): string => ServerResource::getUrl('edit', ['record' => $record->id])),

                TextColumn::make('uuidShort')
                    ->label(trans('admin/server.children.table.identifier'))
                    ->copyable(),

                TextColumn::make('cpu')
                    ->label(trans('admin/server.children.table.cpu'))
                    ->suffix('%'),

                TextColumn::make('memory')
                    ->label(trans('admin/server.children.table.memory'))
                    ->suffix(' MiB'),

                TextColumn::make('disk')
                    ->label(trans('admin/server.children.table.disk'))
                    ->suffix(' MiB'),
            ])
            ->defaultSort('id');
    }

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return trans('admin/server.children.title');
    }

    public static function getBadge(Model $ownerRecord, string $pageClass): ?string
    {
        /** @var Server $ownerRecord */
        $count = $ownerRecord->children()->count();

        return $count > 0 ? (string) $count : null;
    }
}
