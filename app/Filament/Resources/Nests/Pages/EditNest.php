<?php

namespace App\Filament\Resources\Nests\Pages;

use App\Filament\Resources\Nests\NestResource;
use App\Models\Nest;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditNest extends EditRecord
{
    protected static string $resource = NestResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->before(function (Nest $record, DeleteAction $action): void {
                    if ($record->servers()->count() > 0) {
                        Notification::make()
                            ->title(trans('admin/nests.notices.cannot_delete'))
                            ->body(trans('admin/nests.notices.cannot_delete_body', ['count' => $record->servers()->count()]))
                            ->danger()
                            ->send();

                        $action->cancel();
                    }
                }),
        ];
    }
}
