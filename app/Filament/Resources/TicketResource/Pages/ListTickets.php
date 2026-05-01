<?php

namespace App\Filament\Resources\TicketResource\Pages;

use App\Enums\TaskStatusEnum;
use App\Filament\Resources\TicketResource;
use App\Models\Ticket;
use Filament\Actions;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListTickets extends ListRecords
{
    protected static string $resource = TicketResource::class;

    // Live SLA countdown refreshes every 60 seconds
    protected static ?string $pollingInterval = '60s';

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }

    public function getTabs(): array
    {
        // All badge counts must be scoped to what the current user can see —
        // the underlying list query is scoped via TicketResource::getEloquentQuery,
        // and badges have to match or they'll show counts the user can't actually
        // open. modifyQueryUsing is applied on top of the already-scoped list query
        // so it doesn't need its own visibleBy.
        $scoped = fn () => Ticket::query()->visibleBy(auth()->user());

        return [
            'all' => Tab::make(__('ui.all'))
                ->badge(fn () => $scoped()->count()),

            'open' => Tab::make(__('ui.open'))
                ->badge(fn () => $scoped()->where('status', TaskStatusEnum::OPEN)->count())
                ->badgeIcon(TaskStatusEnum::OPEN->getIcon())
                ->badgeColor(TaskStatusEnum::OPEN->getColor())
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', TaskStatusEnum::OPEN)),

            'assigned' => Tab::make(__('ui.assigned'))
                ->badge(fn () => $scoped()->where('status', TaskStatusEnum::ASSIGNED)->count())
                ->badgeIcon(TaskStatusEnum::ASSIGNED->getIcon())
                ->badgeColor(TaskStatusEnum::ASSIGNED->getColor())
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', TaskStatusEnum::ASSIGNED)),

            'in_progress' => Tab::make(__('ui.in_progress'))
                ->badge(fn () => $scoped()->where('status', TaskStatusEnum::IN_PROGRESS)->count())
                ->badgeIcon(TaskStatusEnum::IN_PROGRESS->getIcon())
                ->badgeColor(TaskStatusEnum::IN_PROGRESS->getColor())
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', TaskStatusEnum::IN_PROGRESS)),

            'on_hold' => Tab::make(__('ui.on_hold'))
                ->badge(fn () => $scoped()->where('status', TaskStatusEnum::ON_HOLD)->count())
                ->badgeIcon(TaskStatusEnum::ON_HOLD->getIcon())
                ->badgeColor(TaskStatusEnum::ON_HOLD->getColor())
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', TaskStatusEnum::ON_HOLD)),

            'resolved' => Tab::make(__('ui.resolved'))
                ->badge(fn () => $scoped()->where('status', TaskStatusEnum::RESOLVED)->count())
                ->badgeIcon(TaskStatusEnum::RESOLVED->getIcon())
                ->badgeColor(TaskStatusEnum::RESOLVED->getColor())
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', TaskStatusEnum::RESOLVED)),

            'closed' => Tab::make(__('ui.closed'))
                ->badge(fn () => $scoped()->where('status', TaskStatusEnum::CLOSED)->count())
                ->badgeIcon(TaskStatusEnum::CLOSED->getIcon())
                ->badgeColor(TaskStatusEnum::CLOSED->getColor())
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', TaskStatusEnum::CLOSED)),

            'breached' => Tab::make(__('ui.sla_breached'))
                ->badge(fn () => $scoped()->slaBreached()->count())
                ->badgeIcon('heroicon-o-exclamation-triangle')
                ->badgeColor('danger')
                ->modifyQueryUsing(fn (Builder $query) => $query->slaBreached()),
        ];
    }
}
