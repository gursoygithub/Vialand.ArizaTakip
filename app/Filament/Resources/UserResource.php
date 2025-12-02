<?php

namespace App\Filament\Resources;

use App\Enums\ActiveStatusEnum;
use App\Enums\ManagerStatusEnum;
use App\Enums\UserStatusEnum;
use App\Filament\Resources\UserResource\Pages;
use App\Filament\Resources\UserResource\RelationManagers;
use App\Models\Employee;
use App\Models\Report;
use App\Models\User;
use Filament\Forms;
use Filament\Forms\Components\Fieldset;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $navigationIcon = 'heroicon-o-user';

    public static function getModelLabel(): string
    {
        return __('ui.user');
    }

    public static function getPluralModelLabel(): string
    {
        return __('ui.users');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('ui.user_management');
    }

    public static function getNavigationBadge(): ?string
    {
        if (auth()->user()->hasRole('super_admin') || auth()->user()->can('view_all_users')) {
            return static::getModel()::where('id', '>', 1)->count();
        } else {
            return static::getModel()::where('created_by', auth()->id())->where('id', '>', 1)->count();
        }
    }

    public static function getEloquentQuery(): \Illuminate\Database\Eloquent\Builder
    {
        return parent::getEloquentQuery()->where(function ($query) {

            $query
                ->where('id', '!=', auth()->id())
                ->where('id', '>', 1); // exclude super admin user with ID 1

            if (auth()->user()?->hasRole('super_admin') || auth()->user()?->can('view_all_users')
            ) {
                return $query;
            }

            return $query->where('created_by', auth()->id());
        });
    }

    public static function form(Form $form): Form
    {

        return $form
            ->schema([
                \Filament\Forms\Components\Card::make()
                    ->schema([
                        Fieldset::make(__('ui.user_info'))
                            ->columns(1)
                            ->disabledOn('edit')
                            ->schema([
                                Forms\Components\Select::make('employee_id')
                                    ->label(__('ui.user'))
                                    ->searchable()
                                    ->preload()
                                    ->options(function () {
                                        return DB::connection('sqlsrv2')
                                            ->table('dbo._TGRY_PERSONEL')
                                            ->where('AKTIF_MI', 1)
                                            ->where('VERITABANI_ADI', 'VIALAND_EGLENCE')
                                            ->whereNotNull('E_POSTA')
                                            ->selectRaw("UNIQUE_ID as id, CONCAT(ADI, ' ', SOYADI) as name")
                                            ->orderBy('name')
                                            ->pluck('name', 'id')
                                            ->toArray();
                                    })
                                    ->required()
                                    ->unique(
                                        'users',
                                        'employee_id',
                                        ignoreRecord: true,
                                        modifyRuleUsing: function ($rule) {
                                            return $rule->whereNull('deleted_at');
                                        }
                                    )
                                    ->validationMessages([
                                        'required' => __('ui.required'),
                                        'unique' => __('ui.unique'),
                                        ]),
                                Forms\Components\TextInput::make('email')
                                    ->visibleOn('view')
                                    ->label(__('ui.email'))
                                    ->placeholder(__('ui.email'))
                                    ->email()
                                    ->maxLength(50)
                                    ->rule('email')
                                    ->validationMessages([
                                        'email' => __('ui.email_invalid'),
                                        'required' => __('ui.required'),
                                    ])
                                    ->required(),
                                Forms\Components\TextInput::make('phone')
                                    ->visibleOn('view')
                                    ->label(__('ui.phone'))
                                    ->placeholder(__('ui.phone_placeholder'))
                                    ->numeric()
                                    ->minLength(10)
                                    ->maxLength(10)
                                    ->rule('digits:10')
                                    ->mask('99999999999')
                                    ->validationMessages([
                                        'digits' => __('ui.phone_digits'),
                                        'numeric' => __('ui.phone_numeric'),
                                        'required' => __('ui.required'),
                                        'min_digits' => __('ui.phone_min_digits'),
                                        'max_digits' => __('ui.phone_max_digits'),
                                    ]),
                            ]),
                        Forms\Components\Fieldset::make(__('ui.signer_info'))
                            ->hidden()
                            ->schema([
                                Forms\Components\TextInput::make('phone')
                                    ->label(__('ui.phone'))
                                    ->placeholder(__('ui.phone_placeholder'))
                                    ->numeric()
                                    ->minLength(10)
                                    ->maxLength(10)
                                    ->rule('digits:10')
                                    ->mask('99999999999')
                                    ->validationMessages([
                                        'digits' => __('ui.phone_digits'),
                                        'numeric' => __('ui.phone_numeric'),
                                        'required' => __('ui.required'),
                                        'min_digits' => __('ui.phone_min_digits'),
                                        'max_digits' => __('ui.phone_max_digits'),
                                    ]),
                            ])->columns(3),
                        Forms\Components\Fieldset::make(__('ui.role_and_permissions'))
                            ->visible(fn ($record) => $record?->id !== auth()->id())
                            ->schema([
                                Forms\Components\Select::make('roles')
                                    ->label(__('ui.roles'))
                                    ->relationship('roles', 'name')
                                    ->multiple()
                                    ->preload()
                                    ->searchable()
                                    ->validationMessages([
                                        'required' => __('ui.required'),
                                    ])
                                    ->required(),
                                Forms\Components\Select::make('status')
                                    ->hiddenOn('create')
                                    ->label(__('ui.status'))
                                    ->options(ManagerStatusEnum::class)
                                    ->required()
                                    ->default(1),
                            ])->columns(2),
                        Forms\Components\Fieldset::make(__('ui.password'))
                            ->hidden()
                            //->hiddenOn(['edit', 'view'])
                            ->schema([
                                Forms\Components\TextInput::make('password')
                                    ->label(__('ui.password'))
                                    ->placeholder(__('ui.password'))
                                    ->password()
                                    ->minLength(8)
                                    ->maxLength(255)
                                    ->rule('confirmed')
                                    ->dehydrated(fn ($state) => filled($state))
                                    ->validationMessages([
                                        'required' => __('ui.required'),
                                        'confirmed' => __('ui.password_confirmed'),
                                        'min' => __('ui.password_min_length'),
                                        'max' => __('ui.password_max_length'),
                                    ])
                                    ->required(),
                                Forms\Components\TextInput::make('password_confirmation')
                                    ->label(__('ui.password_confirmation'))
                                    ->placeholder(__('ui.password_confirmation'))
                                    ->password()
                                    ->maxLength(255)
                                    ->dehydrated(false), // asla DB'ye gitmesin
                            ])->columns(2),

                        Forms\Components\Section::make()
                            ->visible(fn ($record) => $record->is(auth()->user()) || $record->roles->contains('name', 'super_admin'))
                            ->visibleOn(['edit'])
                            ->schema([
                                Forms\Components\Fieldset::make(__('ui.new_password'))
                                    ->schema([
                                        Forms\Components\TextInput::make('new_password')
                                            ->hiddenLabel()
                                            ->placeholder(__('ui.new_password'))
                                            ->password()
                                            ->minLength(8)
                                            ->nullable(),
                                        Forms\Components\TextInput::make('new_password_confirmation')
                                            ->hiddenLabel()
                                            ->placeholder(__('ui.new_password_confirmation'))
                                            ->password()
                                            ->same('new_password')
                                            ->minLength(8)
                                            ->validationMessages([
                                                'new_password_confirmation' => __('ui.password_confirmation'),
                                                'required_with' => __('ui.password_confirmation_required'),
                                                'same' => __('ui.password_same'),
                                            ])
                                            ->requiredWith('new_password'),
                                    ]),
                            ])->columns(2),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('updated_at', 'desc')
            ->paginated([5, 10, 25, 50])
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label(__('ui.name'))
                    ->searchable(),
                Tables\Columns\TextColumn::make('phone')
                    ->label(__('ui.phone'))
                    ->searchable(),
                Tables\Columns\TextColumn::make('email')
                    ->label(__('ui.email'))
                    ->searchable(),
                Tables\Columns\TextColumn::make('roles.name')
                    ->label(__('ui.roles'))
                    ->badge()
                    ->sortable(),
//                Tables\Columns\TextColumn::make('is_manager')
//                    ->label(__('ui.is_manager'))
//                    ->badge()
//                    ->sortable(),
//                Tables\Columns\TextColumn::make('staffs_count')
//                    ->label(__('ui.staffs_count'))
//                    ->counts('staffs')
//                    ->badge()
//                    ->color('info')
//                    //->alignCenter()
//                    ->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->label(__('ui.status'))
                    ->badge()
                    ->sortable(),
                Tables\Columns\TextColumn::make('createdBy.name')
                    ->visible(fn () => auth()->user()->hasRole('super_admin') || auth()->user()->can('view_all_users'))
                    ->label(__('ui.created_by'))
                    ->searchable()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('created_at')
                    ->label(__('ui.created_at'))
                    ->dateTime()
                    ->sortable()
                    ->toggleable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('updatedBy.name')
                    ->label(__('ui.updated_by'))
                    ->searchable()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('updated_at')
                    ->label(__('ui.updated_at'))
                    ->getStateUsing(fn ($record) => $record->updated_by ? $record->updated_at : null)
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label(__('ui.status'))
                    ->options(ManagerStatusEnum::class),
                Tables\Filters\SelectFilter::make('roles.name')
                    ->label(__('ui.roles'))
                    ->relationship('roles', 'name'),
                    Tables\Filters\SelectFilter::make('employee_id')
                        ->hidden()
                        ->label(__('ui.employee'))
                        ->options(function () {
                            return DB::connection('sqlsrv')
                                ->table('dbo.personnel_employee')
                                //->select('id', DB::raw("first_name + ' ' + last_name as full_name"))
                                ->pluck('first_name', 'id')
                                ->toArray();
                        })
                        ->searchable(),
            ])
            ->actions([
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\ViewAction::make(),
                    Tables\Actions\EditAction::make(),
                    Tables\Actions\DeleteAction::make(),
                ]),
            ])
            ->bulkActions([
                //
            ]);
    }

    public static function getRelations(): array
    {
        return [
            //RelationManagers\StaffsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListUsers::route('/'),
            'create' => Pages\CreateUser::route('/create'),
            'view' => Pages\ViewUser::route('/{record}'),
            'edit' => Pages\EditUser::route('/{record}/edit'),
        ];
    }

    public static function canDelete(Model $record): bool
    {
        // Check if the user is trying to delete themselves
        if ($record->id === auth()->id()) {
            return false;
        }

        // Check if the user is a super admin
        if (auth()->user()->hasRole('super_admin')) {
            // Super admin cannot delete if the user is associated with any Area, SubArea, or Task
            if ($record->areas()->exists() || $record->subAreas()->exists() || $record->tasks()->exists()) {
                return false;
            }

            return true;
        }

        // Check if the user is not the creator of the record
        if ($record->created_by !== auth()->id()) {
            return false;
        }

        // Check if the user is associated with any Area, SubArea, or Task
        if ($record->areas()->exists() || $record->subAreas()->exists() || $record->tasks()->exists()) {
            return false;
        }

        return true;
    }
}
