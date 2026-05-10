<?php

namespace App\Filament\Resources;

use App\Enums\TaskStatusEnum;
use App\Filament\Concerns\ScopedByVisibility;
use App\Filament\Resources\UnitResource\Pages;
use App\Filament\Resources\UnitResource\RelationManagers;
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
use Illuminate\Support\Facades\Auth;

class UnitResource extends Resource
{
    use ScopedByVisibility;

    protected static string $viewAllPermission = 'view_all_units';

    protected static ?string $model = Unit::class;

    protected static ?string $navigationIcon = 'heroicon-o-building-office';

    protected static ?int $navigationSort = -1000;

    public static function getModelLabel(): string
    {
        return __('ui.unit');
    }

    public static function getPluralModelLabel(): string
    {
        return __('ui.units');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('ui.panel_management');
    }

    public static function getNavigationBadge(): ?string
    {
        return (string) static::getEloquentQuery()->count();
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                \Filament\Forms\Components\Card::make()
                    ->schema([
                        Fieldset::make(__('ui.unit_information'))
                            ->columns(1)
                            ->schema([
                                Forms\Components\TextInput::make('name')
                                    ->label(__('ui.name'))
                                    ->placeholder(__('ui.unit_placeholder'))
                                    ->required()
                                    ->maxLength(255)
                                    ->validationMessages([
                                        'required' => __('ui.required'),
                                    ]),
                            ]),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        $scope = function ($q) {
            return $q->visibleBy(auth()->user());
        };

        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->withCount([
                'tickets'                                  => $scope,
                'tickets as active_tickets_count'          => fn ($q) => $scope($q)->whereIn('status', [
                    TaskStatusEnum::OPEN,
                    TaskStatusEnum::ASSIGNED,
                    TaskStatusEnum::IN_PROGRESS,
                    TaskStatusEnum::ON_HOLD,
                ]),
                'tickets as breached_tickets_count'        => fn ($q) => $scope($q)
                    ->where('sla_breached', true)
                    ->whereNotIn('status', [
                        TaskStatusEnum::RESOLVED,
                        TaskStatusEnum::CLOSED,
                        TaskStatusEnum::CANCELLED,
                    ]),
            ]))
            ->defaultSort('updated_at', 'desc')
            ->paginated([5, 10, 25, 50])
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label(__('ui.name'))
                    ->icon('heroicon-o-building-office')
                    ->badge()
                    ->color('primary')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('tickets_count')
                    ->label('Toplam Talep')
                    ->badge()
                    ->sortable(),

                Tables\Columns\TextColumn::make('active_tickets_count')
                    ->label('Aktif')
                    ->badge()
                    ->color(fn ($state) => $state > 0 ? 'warning' : 'gray')
                    ->sortable(),

                Tables\Columns\TextColumn::make('breached_tickets_count')
                    ->label('İhlal')
                    ->badge()
                    ->color(fn ($state) => $state > 0 ? 'danger' : 'gray')
                    ->sortable(),

                Tables\Columns\TextColumn::make('createdBy.name')
                    ->visible(fn () => auth()->user()->hasRole('super_admin') || auth()->user()->can('view_all_units'))
                    ->label(__('ui.created_by'))
                    ->icon('heroicon-o-user')
                    ->searchable()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('created_at')
                    ->label(__('ui.created_at'))
                    ->icon('heroicon-o-calendar-days')
                    ->dateTime(),
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
                    Tables\Actions\DeleteAction::make()
                        ->requiresConfirmation()
//                        ->action(function ($record) {
//                            $record->deleted_by = Auth::id();
//                            $record->deleted_at = now();
//                            $record->save();
//                            $record->delete();
//                        }),
                ]),
            ])
            ->bulkActions([
                //
            ]);
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\TicketsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListUnits::route('/'),
            'create' => Pages\CreateUnit::route('/create'),
            'edit' => Pages\EditUnit::route('/{record}/edit'),
            'view' => Pages\ViewUnit::route('/{record}'),
        ];
    }

    public static function canDelete(Model $record): bool
    {
        return $record->tickets()->count() === 0
            && (auth()->user()->hasRole('super_admin')
                || $record->created_by === auth()->user()->id);
    }
}
