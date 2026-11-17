<?php

namespace App\Filament\Resources\EmployeeResource\RelationManagers;

use App\Filament\Resources\TeamResource;
use App\Models\Team;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Teams the employee belongs to or supervises, in one list.
 */
class TeamMembershipsRelationManager extends RelationManager
{
    protected static string $relationship = 'teams';

    protected static ?string $icon = 'heroicon-o-user-group';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('ui.employee_team_memberships');
    }

    protected function getTableHeading(): string|Htmlable|null
    {
        return __('ui.employee_team_memberships');
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return true;
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    protected function getTableQuery(): Builder
    {
        $employeeId = $this->getOwnerRecord()->getKey();

        return Team::query()
            ->where(fn (Builder $q) => $q->where('supervisor_employee_id', $employeeId)
                ->orWhereHas('members', fn (Builder $m) => $m->where('employees.id', $employeeId)));
    }

    public function table(Table $table): Table
    {
        $employeeId = $this->getOwnerRecord()->getKey();

        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->with(['ticketType', 'supervisor.company', 'workCalendar'])
                ->withCount('members')
                ->withExists(['members as is_member' => fn (Builder $m) => $m->where('employees.id', $employeeId)]))
            ->defaultSort('name')
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label(__('ui.team'))
                    ->weight('bold')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('ticketType.name')
                    ->label(__('ui.ticket_type'))
                    ->badge()
                    ->color(fn (Team $record): string => $record->ticketType?->color ?: 'gray'),

                Tables\Columns\TextColumn::make('role')
                    ->label(__('ui.employee_team_role'))
                    ->state(fn (Team $record): string => self::roleLabel($record, $employeeId))
                    ->color(fn (Team $record): string => (int) $record->supervisor_employee_id === (int) $employeeId ? 'warning' : 'info')
                    ->badge(),

                Tables\Columns\TextColumn::make('supervisor.name')
                    ->label(__('ui.supervisor'))
                    ->description(fn (Team $record): ?string => $record->supervisor?->company?->name),

                Tables\Columns\TextColumn::make('members_count')
                    ->label(__('ui.team_members'))
                    ->badge()
                    ->color('gray'),

                Tables\Columns\TextColumn::make('workCalendar.name')
                    ->label(__('ui.work_calendar'))
                    ->placeholder(__('ui.employee_team_around_the_clock')),
            ])
            ->recordUrl(fn (Team $record): ?string => TeamResource::canView($record)
                ? TeamResource::getUrl('view', ['record' => $record])
                : null)
            ->emptyStateHeading(__('ui.employee_team_memberships_empty'))
            ->emptyStateIcon('heroicon-o-user-group');
    }

    public static function roleLabel(Team $team, int $employeeId): string
    {
        $supervises = (int) $team->supervisor_employee_id === $employeeId;
        $member = (bool) ($team->is_member ?? $team->members()->whereKey($employeeId)->exists());

        return match (true) {
            $supervises && $member => __('ui.employee_team_role_both'),
            $supervises => __('ui.supervisor'),
            default => __('ui.employee_team_role_member'),
        };
    }
}
