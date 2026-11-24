<?php

namespace App\Filament\Resources;

use App\Enums\ActiveStatusEnum;
use App\Filament\Concerns\ScopedByVisibility;
use App\Filament\Resources\EmployeeResource\Pages;
use App\Filament\Resources\EmployeeResource\RelationManagers;
use App\Models\Employee;
use App\Models\Team;
use App\Services\TeamPerformanceService;
use App\Support\CompanyEmail;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

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
        return static::teamTable($table);
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
                    ->placeholder(__('ui.email_status_missing'))
                    ->icon(fn (Employee $record): ?string => CompanyEmail::isCorporate($record->email) ? null : 'heroicon-m-exclamation-triangle')
                    ->color(fn (Employee $record): ?string => match (CompanyEmail::status($record->email)) {
                        CompanyEmail::CORPORATE => null,
                        CompanyEmail::PERSONAL => 'warning',
                        default => 'danger',
                    })
                    ->tooltip(fn (Employee $record): ?string => match (CompanyEmail::status($record->email)) {
                        CompanyEmail::PERSONAL => __('ui.email_status_personal_hint'),
                        CompanyEmail::INVALID => __('ui.email_status_invalid_hint'),
                        CompanyEmail::MISSING => __('ui.email_status_missing_hint'),
                        default => null,
                    })
                    ->searchable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('company.name')
                    ->label(__('ui.company'))
                    ->icon('heroicon-o-building-office-2')
                    ->placeholder('—')
                    ->searchable()
                    ->sortable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('team_count')
                    ->label(__('ui.teams'))
                    ->state(fn (Employee $record): int => count(static::teamLabels($record)))
                    ->formatStateUsing(fn (int $state, Employee $record): string => static::teamCountLabel($state, $record))
                    ->tooltip(fn (Employee $record): ?string => implode("\n", static::teamLabels($record)) ?: null)
                    ->badge()
                    ->color(fn (int $state): string => $state > 0 ? 'info' : 'gray'),
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
                // Old HR records of people who moved company are kept as
                // history; the list shows current records unless asked.
                Tables\Filters\SelectFilter::make('status')
                    ->label(__('ui.employee_show_inactive'))
                    ->options(ActiveStatusEnum::class)
                    ->default(ActiveStatusEnum::ACTIVE->value),
                Tables\Filters\SelectFilter::make('email_status')
                    ->label(__('ui.email_status'))
                    ->options(self::emailStatusOptions())
                    ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null)
                        ? $query->emailStatus($data['value'])
                        : $query),
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
     * "4 ekip · 2 amir" — names are in the tooltip and on the detail page.
     */
    /**
     * @return array<string, string>
     */
    public static function emailStatusOptions(): array
    {
        return [
            CompanyEmail::CORPORATE => __('ui.email_status_corporate'),
            CompanyEmail::PERSONAL => __('ui.email_status_personal'),
            CompanyEmail::INVALID => __('ui.email_status_invalid'),
            CompanyEmail::MISSING => __('ui.email_status_missing'),
            'duplicate' => __('ui.email_status_duplicate'),
        ];
    }

    public static function teamCountLabel(int $count, Employee $employee): string
    {
        $supervised = $employee->supervisedTeams->count();

        return $supervised > 0
            ? __('ui.employee_team_count_supervised', ['count' => $count, 'supervised' => $supervised])
            : __('ui.employee_team_count', ['count' => $count]);
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
        return [
            RelationManagers\TeamTicketsRelationManager::class,
            RelationManagers\TeamMembershipsRelationManager::class,
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
            'view' => Pages\ViewEmployee::route('/{record}'),
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
