<?php

namespace App\Filament\Widgets;

use App\Enums\TaskStatusEnum;
use App\Filament\Resources\TeamTicketResource;
use App\Models\Team;
use App\Models\Ticket;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * "What needs me now": requests assigned to me, unassigned requests in the
 * pool of my teams / teams I supervise, and resolutions waiting for my
 * confirmation. Most urgent deadline first.
 */
class MyRequestQueue extends TableWidget
{
    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $pollingInterval = '60s';

    public static function canView(): bool
    {
        return auth()->check();
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading(__('ui.dashboard_queue'))
            ->description(__('ui.dashboard_queue_desc'))
            ->query($this->query())
            ->defaultSort('sla_deadline')
            ->paginated([5, 10, 25])
            ->defaultPaginationPageOption(5)
            ->recordUrl(fn (Ticket $record): string => TeamTicketResource::getUrl('view', ['record' => $record]))
            ->columns([
                Tables\Columns\TextColumn::make('ticket_no')
                    ->label(__('ui.ticket_no'))
                    ->weight('bold'),
                Tables\Columns\TextColumn::make('reason')
                    ->label(__('ui.dashboard_queue_reason'))
                    ->state(fn (Ticket $record): string => $this->reason($record))
                    ->badge()
                    ->color(fn (Ticket $record): string => match ($record->status) {
                        TaskStatusEnum::RESOLVED => 'success',
                        TaskStatusEnum::OPEN => 'info',
                        default => 'warning',
                    }),
                Tables\Columns\TextColumn::make('ticketType.name')
                    ->label(__('ui.ticket_type'))
                    ->badge()
                    ->color(fn (Ticket $record): string => $record->ticketType?->color ?: 'gray'),
                Tables\Columns\TextColumn::make('team.name')
                    ->label(__('ui.team')),
                Tables\Columns\TextColumn::make('priority')
                    ->label(__('ui.priority'))
                    ->badge(),
                Tables\Columns\TextColumn::make('status')
                    ->label(__('ui.status'))
                    ->badge(),
                Tables\Columns\TextColumn::make('sla_state')
                    ->label(__('ui.sla_indicator'))
                    ->state(fn (Ticket $record): string => TeamTicketResource::slaLabel($record))
                    ->color(fn (Ticket $record): string => TeamTicketResource::slaColor($record))
                    ->badge(),
            ])
            ->emptyStateHeading(__('ui.dashboard_queue_empty'))
            ->emptyStateIcon('heroicon-o-check-circle');
    }

    private function query(): Builder
    {
        $user = auth()->user();
        $employeeId = $user?->employee?->id;
        $teamIds = $employeeId
            ? DB::table('team_members')->where('employee_id', $employeeId)->pluck('team_id')
                ->merge(Team::query()->where('supervisor_employee_id', $employeeId)->pluck('id'))
                ->unique()
            : collect();

        return Ticket::query()
            ->visibleInTeamWorkflow($user)
            ->with(['ticketType', 'team', 'currentCycle.workCalendar'])
            ->where(function (Builder $q) use ($user, $employeeId, $teamIds): void {
                $q->where(fn (Builder $mine) => $mine
                    ->where('status', TaskStatusEnum::RESOLVED)
                    ->where('created_by', $user?->id));

                if ($employeeId) {
                    $q->orWhere(fn (Builder $assigned) => $assigned
                        ->where('employee_id', $employeeId)
                        ->whereIn('status', [TaskStatusEnum::ASSIGNED, TaskStatusEnum::IN_PROGRESS, TaskStatusEnum::ON_HOLD]));
                }

                if ($teamIds->isNotEmpty()) {
                    $q->orWhere(fn (Builder $pool) => $pool
                        ->where('status', TaskStatusEnum::OPEN)
                        ->whereIn('team_id', $teamIds));
                }
            });
    }

    private function reason(Ticket $ticket): string
    {
        return match ($ticket->status) {
            TaskStatusEnum::RESOLVED => __('ui.dashboard_reason_confirm'),
            TaskStatusEnum::OPEN => __('ui.dashboard_reason_pool'),
            default => __('ui.dashboard_reason_assigned'),
        };
    }
}
