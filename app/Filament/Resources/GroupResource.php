<?php

namespace App\Filament\Resources;

use App\Enums\ActiveStatusEnum;
use App\Enums\TaskStatusEnum;
use App\Filament\Concerns\ScopedByVisibility;
use App\Filament\Resources\GroupResource\Pages;
use App\Filament\Resources\GroupResource\RelationManagers;
use App\Models\Group;
use App\Models\Unit;
use Filament\Forms;
use Filament\Forms\Components\Fieldset;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class GroupResource extends Resource
{
    use ScopedByVisibility;

    protected static string $viewAllPermission = 'view_all_groups';

    protected static ?string $model = Group::class;

    protected static ?string $navigationIcon = 'heroicon-o-user-group';

    public static function getModelLabel(): string
    {
        return __('ui.group');
    }

    public static function getPluralModelLabel(): string
    {
        return __('ui.groups');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('ui.user_management');
    }

    public static function getNavigationBadge(): ?string
    {
        return (string) static::getEloquentQuery()->count();
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Fieldset::make(__('ui.group_information'))
                    ->columns(3)
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->label(__('ui.name'))
                            ->placeholder(__('ui.group_placeholder'))
                            ->required()
                            ->maxLength(255)
                            ->validationMessages([
                                'required' => __('ui.required'),
                            ]),
                        Forms\Components\Select::make('company_id')
                            ->label(__('ui.company'))
                            ->options(function () {
                                $user = auth()->user();
                                $companyIds = $user?->scopedCompanyIds() ?? [];
                                $query = \App\Models\Company::query()
                                    ->where('status', ActiveStatusEnum::ACTIVE);
                                if (!empty($companyIds)) {
                                    $query->whereIn('id', $companyIds);
                                }
                                return $query->pluck('name', 'id')->toArray();
                            })
                            ->live()
                            ->preload()
                            ->searchable()
                            ->required()
                            ->validationMessages([
                                'required' => __('ui.required'),
                            ]),
                        Forms\Components\Select::make('area_id')
                            ->label(__('ui.area'))
                            ->options(function (callable $get) {
                                $companyId = $get('company_id');
                                if (!$companyId) {
                                    return [];
                                }
                                return \App\Models\Area::query()
                                    ->where('status', ActiveStatusEnum::ACTIVE)
                                    ->where('company_id', $companyId)
                                    ->pluck('name', 'id');
                            })
                            ->live()
                            ->preload()
                            ->searchable()
                            ->required()
                            ->validationMessages([
                                'required' => __('ui.required'),
                            ]),
                        Forms\Components\Select::make('unit_id')
                            ->label(__('ui.unit'))
                            ->options(
                                \App\Models\Unit::query()
                                    ->pluck('name', 'id')
                            )
                            ->live()
                            ->preload()
                            ->searchable()
                            ->required()
                            ->validationMessages([
                                'required' => __('ui.required'),
                            ]),
                        Forms\Components\Select::make('employee_id')
                            ->label(__('ui.group_manager'))
                            ->options(
                                \App\Models\Employee::query()
                                    ->where('status', ActiveStatusEnum::ACTIVE)
                                    ->pluck('name', 'id')
                            )
                            ->unique(
                                table: Group::class,
                                column: 'employee_id',
                                ignoreRecord: true,
                                modifyRuleUsing: function ($rule, callable $get) {
                                    return $rule
                                        ->where('company_id', $get('company_id'))
                                        ->where('unit_id', $get('unit_id'))
                                        ->whereNull('deleted_at');
                                }
                            )
                            ->required()
                            ->validationMessages([
                                'required' => __('ui.required'),
                                'unique' => __('ui.manager_already_assigned'),
                            ])
                            ->searchable()
                            ->preload(),
                        Forms\Components\ToggleButtons::make('status')
                            ->visibleOn(['edit', 'view'])
                            ->label(__('ui.status'))
                            ->options([
                                ActiveStatusEnum::ACTIVE->value => ActiveStatusEnum::ACTIVE->getLabel(),
                                ActiveStatusEnum::INACTIVE->value => ActiveStatusEnum::INACTIVE->getLabel(),
                            ])
                            ->icons([
                                ActiveStatusEnum::ACTIVE->value => ActiveStatusEnum::ACTIVE->getIcon(),
                                ActiveStatusEnum::INACTIVE->value => ActiveStatusEnum::INACTIVE->getIcon(),
                            ])
                            ->colors([
                                ActiveStatusEnum::ACTIVE->value => ActiveStatusEnum::ACTIVE->getColor(),
                                ActiveStatusEnum::INACTIVE->value => ActiveStatusEnum::INACTIVE->getColor(),
                            ])
                            ->default(ActiveStatusEnum::ACTIVE->value)
                            ->inline(),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label(__('ui.name'))
                    ->badge()
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('company.name')
                    ->label(__('ui.company'))
                    ->icon('heroicon-o-building-library')
                    ->badge()
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('area.name')
                    ->label(__('ui.area'))
                    ->icon('heroicon-o-map')
                    ->badge()
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('manager.name')
                    ->label(__('ui.group_manager'))
                    ->icon('heroicon-o-user')
                    ->badge()
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('members_count')
                    ->label(__('ui.members'))
                    ->counts('members')
                    ->icon('heroicon-o-users')
                    ->badge()
                    ->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->label(__('ui.status'))
                    ->badge()
                    ->sortable(),
                Tables\Columns\TextColumn::make('createdBy.name')
                    ->visible(fn () => auth()->user()->hasRole('super_admin') || auth()->user()->can('view_all_groups'))
                    ->label(__('ui.created_by'))
                    ->icon('heroicon-o-user')
                    ->searchable()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('created_at')
                    ->label(__('ui.created_at'))
                    ->icon('heroicon-o-calendar-days')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('updatedBy.name')
                    ->label(__('ui.updated_by'))
                    ->icon('heroicon-o-user')
                    ->searchable()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('updated_at')
                    ->label(__('ui.updated_at'))
                    ->icon('heroicon-o-calendar-days')
                    ->getStateUsing(fn ($record) => $record->updated_by ? $record->updated_at : null)
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
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
            RelationManagers\MembersRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListGroups::route('/'),
            'create' => Pages\CreateGroup::route('/create'),
            'edit' => Pages\EditGroup::route('/{record}/edit'),
            'view' => Pages\ViewGroup::route('/{record}'),
        ];
    }

    public static function canDelete(Model $record): bool
    {
        // Prevent deletion if the group has members
        if ($record->members()->count() > 0) {
            return false;
        }

        return auth()->user()->hasRole('super_admin')
            || $record->created_by === auth()->user()->id;
    }
}
