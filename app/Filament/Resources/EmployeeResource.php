<?php

namespace App\Filament\Resources;

use App\Enums\TaskStatusEnum;
use App\Filament\Concerns\ScopedByVisibility;
use App\Filament\Resources\EmployeeResource\Pages;
use App\Filament\Resources\EmployeeResource\RelationManagers;
use App\Models\Employee;
use App\Models\SlaPolicy;
use App\Models\Team;
use App\Services\TeamPerformanceService;
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

    protected static ?int $navigationSort = 20;

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
        if (! config('legacy.enabled')) {
            return static::teamTable($table);
        }

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
                Tables\Columns\TextColumn::make('company.name')
                    ->label(__('ui.company'))
                    ->icon('heroicon-o-building-office-2')
                    ->placeholder('—')
                    ->searchable()
                    ->sortable()
                    ->toggleable(),

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
                        if (is_null($record->performance_score)) return 'gray';
                        if (is_null($record->current_threshold)) return 'gray';
                        $t = $record->current_threshold;
                        return $record->performance_score >= $t
                            ? 'success'
                            : ($record->performance_score >= $t * 0.75
                                ? 'warning' : 'danger');
                    }),

                Tables\Columns\TextColumn::make('current_threshold')
                    ->label(__('ui.sla_target_threshold'))
                    ->formatStateUsing(fn ($state) => is_null($state) ? '—' : '%' . number_format($state, 1, ',', '.'))
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('company_id')
                    ->label(__('ui.company'))
                    ->relationship('company', 'name')
                    ->multiple()
                    ->searchable()
                    ->preload(),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
            ])
            ->bulkActions([
                //
            ]);
    }

    /**
     * Team-model list: teams, requests on the person's plate and how many of
     * those are past their SLA. Performance lives on the detail page.
     */
    public static function teamTable(Table $table): Table
    {
        $active = fn (Builder $q) => $q->whereNotNull('team_id')->whereIn('status', TeamPerformanceService::ACTIVE_STATUSES);

        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['company', 'teams', 'supervisedTeams']))
            ->defaultSort('name')
            ->paginated([10, 25, 50])
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label(__('ui.name'))
                    ->description(fn (Employee $record): ?string => $record->title)
                    ->weight('bold')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('email')
                    ->label(__('ui.email'))
                    ->searchable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('company.name')
                    ->label(__('ui.company'))
                    ->icon('heroicon-o-building-office-2')
                    ->placeholder('—')
                    ->searchable()
                    ->sortable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('team_names')
                    ->label(__('ui.teams'))
                    ->state(fn (Employee $record): array => static::teamLabels($record))
                    ->badge()
                    ->color('info')
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('held_requests_count')
                    ->label(__('ui.employee_requests_on_them'))
                    ->counts(['tickets as held_requests_count' => $active])
                    ->badge()
                    ->color(fn ($state) => $state > 0 ? 'warning' : 'gray')
                    ->sortable(),
                Tables\Columns\TextColumn::make('overdue_requests_count')
                    ->label(__('ui.employee_requests_overdue'))
                    ->counts(['tickets as overdue_requests_count' => fn (Builder $q) => $active($q)->where('sla_breached', true)])
                    ->badge()
                    ->color(fn ($state) => $state > 0 ? 'danger' : 'gray')
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('company_id')
                    ->label(__('ui.company'))
                    ->relationship('company', 'name')
                    ->multiple()
                    ->searchable()
                    ->preload(),
                Tables\Filters\SelectFilter::make('team')
                    ->label(__('ui.team'))
                    ->options(fn (): array => Team::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->multiple()
                    ->query(fn (Builder $query, array $data) => $query->when($data['values'] ?? [], fn (Builder $q, array $teamIds) => $q
                        ->where(fn (Builder $w) => $w->whereHas('teams', fn (Builder $t) => $t->whereIn('teams.id', $teamIds))
                            ->orWhereHas('supervisedTeams', fn (Builder $t) => $t->whereIn('id', $teamIds))))),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
            ]);
    }

    /**
     * Team names for the list, supervised ones marked.
     *
     * @return array<int, string>
     */
    public static function teamLabels(Employee $employee): array
    {
        $supervisedIds = $employee->supervisedTeams->modelKeys();

        return $employee->supervisedTeams->toBase()
            ->map(fn (Team $team): string => $team->name.' ('.__('ui.supervisor').')')
            ->merge($employee->teams->toBase()->reject(fn (Team $team): bool => in_array($team->id, $supervisedIds, true))->pluck('name'))
            ->sort()
            ->values()
            ->all();
    }

    public static function getRelations(): array
    {
        if (! config('legacy.enabled')) {
            return [
                RelationManagers\TeamTicketsRelationManager::class,
                RelationManagers\TeamMembershipsRelationManager::class,
            ];
        }

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
