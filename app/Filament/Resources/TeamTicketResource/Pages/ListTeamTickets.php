<?php

namespace App\Filament\Resources\TeamTicketResource\Pages;

use App\Enums\TaskStatusEnum;
use App\Filament\Resources\TeamTicketResource;
use App\Models\Team;
use App\Models\Ticket;
use Filament\Actions;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Default views (agreement §7): assigned to me, my teams (incl. the pool),
 * opened by me, teams I supervise, and — with permission — everything.
 */
class ListTeamTickets extends ListRecords
{
    protected static string $resource = TeamTicketResource::class;

    /**
     * Statuses a team ticket can have.
     *
     * @var array<int, TaskStatusEnum>
     */
    public const STATUSES = [
        TaskStatusEnum::OPEN,
        TaskStatusEnum::ASSIGNED,
        TaskStatusEnum::IN_PROGRESS,
        TaskStatusEnum::ON_HOLD,
        TaskStatusEnum::RESOLVED,
        TaskStatusEnum::CLOSED,
        TaskStatusEnum::CANCELLED,
        TaskStatusEnum::REJECTED,
    ];

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()->label(__('ui.new_request')),
        ];
    }

    public function getTabs(): array
    {
        $user = auth()->user();
        $employeeId = $user?->employee?->id;
        $tabs = [];

        if ($employeeId) {
            $tabs['assigned'] = $this->tab(__('ui.tab_assigned_to_me'), 'heroicon-o-user',
                fn (Builder $q) => $q->where('employee_id', $employeeId), activeOnly: true);

            $memberTeams = DB::table('team_members')->where('employee_id', $employeeId)->pluck('team_id');

            if ($memberTeams->isNotEmpty()) {
                $tabs['team'] = $this->tab(__('ui.tab_my_teams'), 'heroicon-o-user-group',
                    fn (Builder $q) => $q->whereIn('team_id', $memberTeams), activeOnly: true);
            }

            $supervised = Team::query()->where('supervisor_employee_id', $employeeId)->pluck('id');

            if ($supervised->isNotEmpty()) {
                $tabs['supervised'] = $this->tab(__('ui.tab_supervised_teams'), 'heroicon-o-shield-check',
                    fn (Builder $q) => $q->whereIn('team_id', $supervised), activeOnly: true);
            }
        }

        $tabs['created'] = $this->tab(__('ui.tab_created_by_me'), 'heroicon-o-pencil-square',
            fn (Builder $q) => $q->where('created_by', $user?->id));

        $tabs['all'] = Tab::make(__('ui.all'))->icon('heroicon-o-queue-list');

        return $tabs;
    }

    public function getDefaultActiveTab(): string|int|null
    {
        $user = auth()->user();

        return match (true) {
            (bool) $user?->employee => 'assigned',
            $user?->hasRole('super_admin') || $user?->can('ticket.view.all') => 'all',
            default => 'created',
        };
    }

    /**
     * A tab whose badge counts what the user can actually open in it.
     * "Active only" tabs hide finished requests so the work queue stays short.
     */
    private function tab(string $label, string $icon, \Closure $scope, bool $activeOnly = false): Tab
    {
        $apply = function (Builder $query) use ($scope, $activeOnly): Builder {
            $scope($query);

            return $activeOnly
                ? $query->whereIn('status', [
                    TaskStatusEnum::OPEN,
                    TaskStatusEnum::ASSIGNED,
                    TaskStatusEnum::IN_PROGRESS,
                    TaskStatusEnum::ON_HOLD,
                    TaskStatusEnum::RESOLVED,
                ])
                : $query;
        };

        return Tab::make($label)
            ->icon($icon)
            ->badge(fn (): int => $apply(Ticket::query()->visibleInTeamWorkflow(auth()->user()))->count())
            ->modifyQueryUsing($apply);
    }
}
