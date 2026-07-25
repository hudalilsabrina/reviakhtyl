<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use App\Services\Users\UserUpdateService;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('suspend')
                ->visible(fn (User $record) => !$record->isSuspended())
                ->requiresConfirmation()
                ->form([
                    Textarea::make('suspension_reason')
                        ->label('Suspension Reason')
                        ->required()
                        ->placeholder('Reason for account suspension...'),
                    DateTimePicker::make('suspend_until')
                        ->label('Suspend Until (Optional)')
                        ->helperText('Leave empty for permanent suspension'),
                ])
                ->action(function (User $record, array $data) {
                    $record->update([
                        'suspended' => true,
                        'suspended_at' => now(),
                        'suspension_reason' => $data['suspension_reason'],
                        'suspend_until' => $data['suspend_until'] ?? null,
                    ]);

                    // Suspend all user's servers
                    $record->servers()->update(['status' => 'suspended']);

                    Notification::make()
                        ->title('User Suspended')
                        ->success()
                        ->send();
                })
                ->color('warning')
                ->icon('heroicon-o-lock-closed'),
            Action::make('unsuspend')
                ->visible(fn (User $record) => $record->isSuspended())
                ->requiresConfirmation()
                ->action(function (User $record) {
                    $record->update([
                        'suspended' => false,
                        'suspended_at' => null,
                        'suspension_reason' => null,
                        'suspend_until' => null,
                    ]);

                    // Unsuspend all user's servers
                    $record->servers()->where('status', 'suspended')->update(['status' => null]);

                    Notification::make()
                        ->title('User Unsuspended')
                        ->success()
                        ->send();
                })
                ->color('success')
                ->icon('heroicon-o-lock-open'),
            DeleteAction::make(),
        ];
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        if (! $record instanceof User) {
            throw new \RuntimeException('Invalid user record provided for update.');
        }

        return app(UserUpdateService::class)->handle($record, $data);
    }
}
