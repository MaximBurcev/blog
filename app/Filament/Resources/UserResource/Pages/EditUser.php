<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Resources\UserResource;
use Filament\Actions;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
            Action::make(__('changePassword'))
                ->form([
                        TextInput::make('new_password')
                            ->password()
                            ->label(__('New password'))
                            ->required()
                            ->rule(Password::default())
                            ->validationAttribute('new_password'),
                        TextInput::make('new_password_confirmation')
                            ->password()
                            ->label(__('Confirm New password'))
                            ->required()
                            ->same('new_password')
                            ->rule(Password::default())
                            ->validationAttribute('new_password_confirmation'),
                    ]
                )->action(function (array $data) {
                    $this->record->update([
                        'password' => Hash::make($data['new_password'])
                    ]);

                    Notification::make()
                        ->success()
                        ->title(__('user.password_updated'))
                        ->body(__('Your password has been changed.'))
                        ->send();
                })
        ];
    }
}
