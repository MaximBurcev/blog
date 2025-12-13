<?php

namespace App\Filament\Resources;

use App\Filament\Resources\UserResource\Pages;
use App\Filament\Resources\UserResource\RelationManagers;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $navigationIcon = 'heroicon-o-user';

    protected static ?string $navigationGroup = 'Настройки';
    protected static ?string $recordTitleAttribute  = 'name';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                TextInput::make('name')->required(),
                TextInput::make('email')->required()->email()->unique(),
                TextInput::make('password')->required()->password()->rule(Password::default()),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')->sortable()->label('ID')->searchable(),
                TextColumn::make('name')->sortable()->label('Name')->searchable(),
                TextColumn::make('email')->sortable()->label('Email')->searchable(),
                TextColumn::make('created_at')->date('d.m.Y H:i:s')->sortable()->label('created_at')->searchable(),
            ])
            ->defaultSort('id', 'desc')
            ->filters([
                //
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\Action::make(__('changePassword'))
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
                    )->action(function (User $record, array $data) {
                        $record->update([
                            'password' => Hash::make($data['new_password'])
                        ]);

                        Notification::make()
                            ->success()
                            ->title(__('user.password_updated'))
                            ->body(__('Your password has been changed.'))
                            ->send();
                    })
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListUsers::route('/'),
            'create' => Pages\CreateUser::route('/create'),
            'edit' => Pages\EditUser::route('/{record}/edit'),
        ];
    }

//    public static function canDelete(Model $record): bool
//    {
//        return false;
//    }

    public static function getNavigationBadge(): ?string
    {
        return self::getModel()::count();
    }
}
