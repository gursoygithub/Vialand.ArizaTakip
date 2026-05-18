<?php

namespace App\Filament\Resources;

use App\Enums\TaskStatusEnum;
use App\Filament\Concerns\ScopedByVisibility;
use App\Filament\Resources\EmployeeResource\Pages;
use App\Filament\Resources\EmployeeResource\RelationManagers;
use App\Models\Employee;
use App\Models\SlaPolicy;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class EmployeeResource extends Resource
{
    use ScopedByVisibility;

    protected static string $viewAllPermission = 'view_all_employees';

    protected static ?string $model = Employee::class;

    protected static ?string $navigationIcon = 'heroicon-o-users';

    public static function getModelLabel(): string
    {
        return __('ui.employee');
    }

    public static function getPluralModelLabel(): string
    {
        return __('ui.employees');
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
                //
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            // "Worst-performers first" — managers open this list to find
            // who needs attention. nulls (no data yet) sort first by
            // default for asc, surfacing untracked employees too.
            ->defaultSort('performance_score', 'asc')
            ->paginated([5, 10, 25, 50])
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label(__('ui.name'))
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('email')
                    ->label(__('ui.email'))
                    ->searchable()
                    ->sortable(),

                // Active workload — currently on this person's plate.
                Tables\Columns\TextColumn::make('active_tickets_count')
                    ->label(__('ui.employee_active_tickets'))
                    ->counts([
                        'tickets as active_tickets_count' => fn (Builder $q) =>
                            $q->whereIn('status', [
                                TaskStatusEnum::OPEN,
                                TaskStatusEnum::ASSIGNED,
                                TaskStatusEnum::IN_PROGRESS,
                                TaskStatusEnum::ON_HOLD,
                            ]),
                    ])
                    ->badge()
                    ->color(fn ($state) => $state > 0 ? 'warning' : 'gray')
                    ->sortable(),

                // SLA breaches still on the assignee — terminal-statuses
                // excluded so this is "active breach surface", not history.
                Tables\Columns\TextColumn::make('breached_tickets_count')
                    ->label(__('ui.employee_breached_tickets'))
                    ->counts([
                        'tickets as breached_tickets_count' => fn (Builder $q) =>
                            $q->where('sla_breached', true)
                              ->whereNotIn('status', [
                                  TaskStatusEnum::RESOLVED,
                                  TaskStatusEnum::CLOSED,
                                  TaskStatusEnum::CANCELLED,
                              ]),
                    ])
                    ->badge()
                    ->color(fn ($state) => $state > 0 ? 'danger' : 'gray')
                    ->sortable(),

                // SLA score. Null = no sealed data yet → render "—" rather
                // than a misleading "0%".
                Tables\Columns\TextColumn::make('performance_score')
                    ->label(__('ui.employee_sla_success'))
                    ->formatStateUsing(fn ($state) => is_null($state) ? '—' : '%' . number_format($state, 1, ',', '.'))
                    ->badge()
                    ->sortable()
                    ->color(function ($record) {
                        if (is_null($record->performance_score)) {
                            return 'gray';
                        }
                        $threshold = (float) ($record->current_threshold ?? 80);
                        return $record->performance_score >= $threshold
                            ? 'success'
                            : ($record->performance_score >= $threshold * 0.75 ? 'warning' : 'danger');
                    }),

                Tables\Columns\TextColumn::make('current_threshold')
                    ->label(__('ui.sla_target_threshold'))
                    ->formatStateUsing(fn ($state) => is_null($state) ? '—' : '%' . number_format($state, 1, ',', '.'))
                    ->sortable(),
            ])
            ->filters([
                //
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
            ])
            ->bulkActions([
                //
            ]);
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\TicketsRelationManager::class,
            RelationManagers\GroupMembershipsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        // Read-only resource: employee records come from LDAP, never edited
        // in-app. canEdit/canDelete already return false; dropping the
        // create/edit routes also prevents typed-URL access to a blank
        // form that would persist a half-empty record.
        return [
            'index' => Pages\ListEmployees::route('/'),
            'view'  => Pages\ViewEmployee::route('/{record}'),
        ];
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }
}
