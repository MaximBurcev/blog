<?php

namespace App\Filament\Resources;

use App\Enums\UserRole;
use App\Filament\Resources\UserResource\Pages;
use App\Models\User;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $navigationIcon = 'heroicon-o-user';

    protected static ?string $navigationGroup = 'Настройки';

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $modelLabel = 'Пользователь';

    protected static ?string $pluralModelLabel = 'Пользователи';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                TextInput::make('name')->required()->label('Имя'),
                TextInput::make('email')->required()->email()->unique(ignoreRecord: true),
                // Только на создании. На редактировании пароль меняется
                // действием «Сменить пароль», которое идёт через
                // changePassword() и ротирует remember_token — здесь же
                // сохранение шло обычным save(), то есть мимо ротации.
                // Побочно чинится UX: required() на форме Edit требовал
                // переустанавливать пароль ради правки имени или роли.
                TextInput::make('password')->required()->password()
                    ->rule(Password::default())->label('Пароль')
                    ->visibleOn('create'),
                // Роли не было ни в одной форме приложения: созданный из панели
                // пользователь получал role = NULL (колонка nullable без
                // default) и в саму панель попасть уже не мог —
                // User::canAccessPanel() требует роль Admin. Нового
                // администратора приходилось заводить руками в БД.
                // Понижение роли закрыто тем же инвариантом, что и удаление
                // (canDelete): иначе оставался соседний путь к тому же
                // результату — админ выставлял себе Reader, canAccessPanel()
                // начинал отдавать 403, и, если он был последним, панель
                // становилась недоступна безвозвратно (роль правится только
                // руками в БД).
                Select::make('role')
                    ->label('Роль')
                    ->options(UserRole::options())
                    ->default(UserRole::Reader->value)
                    ->required()
                    ->native(false)
                    ->disableOptionWhen(fn (string $value, ?User $record): bool => $record !== null
                        && $value !== UserRole::Admin->value
                        && $record->role === UserRole::Admin
                        && ! static::canDelete($record)
                    ),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')->sortable()->label('ID')->searchable(),
                TextColumn::make('name')->sortable()->label('Имя')->searchable(),
                TextColumn::make('email')->sortable()->label('Email')->searchable(),
                TextColumn::make('role')->label('Роль')->sortable()
                    ->formatStateUsing(fn (?UserRole $state): string => $state?->label() ?? '—')
                    ->badge()
                    ->color(fn (?UserRole $state): string => $state === UserRole::Admin ? 'warning' : 'gray'),
                TextColumn::make('created_at')->date('d.m.Y H:i:s')->sortable()->label('Дата создания')->searchable(),
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
                        static::changePassword($record, $data['new_password']);

                        Notification::make()
                            ->success()
                            ->title(__('user.password_updated'))
                            ->body(__('Your password has been changed.'))
                            ->send();
                    }),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    // canDelete() массовое удаление не проверяет — сверяем сами
                    // и отменяем всю операцию, если в выборку попал защищённый.
                    Tables\Actions\DeleteBulkAction::make()
                        ->before(function (Tables\Actions\DeleteBulkAction $action, Collection $records) {
                            $protected = $records->reject(fn (User $user) => static::canDelete($user));

                            if ($protected->isEmpty()) {
                                return;
                            }

                            Notification::make()
                                ->danger()
                                ->title('Удаление отменено')
                                ->body('Нельзя удалить себя или последнего администратора: '.$protected->pluck('email')->implode(', '))
                                ->send();

                            $action->cancel();
                        }),
                ]),
            ]);
    }

    /**
     * Смена пароля админом — единственная точка для обеих форм панели
     * (действие в таблице и на странице редактирования).
     *
     * remember_token обязателен: EloquentUserProvider::retrieveByToken()
     * сверяет только его, пароль в проверке не участвует. Без ротации
     * админ менял пароль скомпрометированному пользователю, писал в
     * audit-trail «пароль сменён» — а угнанная recaller-cookie (срок жизни
     * 5 лет) продолжала пускать атакующего. Серверные сессии на других
     * устройствах добивает AuthenticateSession в группе web (Http\Kernel).
     */
    public static function changePassword(User $user, string $newPassword): void
    {
        $user->forceFill([
            'password' => Hash::make($newPassword),
            'remember_token' => Str::random(60),
        ])->save();
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

    /**
     * Удалить себя или последнего администратора нельзя — иначе доступ
     * к панели теряется безвозвратно.
     */
    public static function canDelete(Model $record): bool
    {
        if ($record->getKey() === auth()->id()) {
            return false;
        }

        if ($record->role !== UserRole::Admin) {
            return true;
        }

        return User::query()->where('role', UserRole::Admin)->count() > 1;
    }

    public static function getNavigationBadge(): ?string
    {
        return self::getModel()::count();
    }
}
