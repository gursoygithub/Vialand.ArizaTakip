<?php

namespace App\Filament\Resources\EmployeeResource\RelationManagers;

use App\Enums\TaskPriorityEnum;
use App\Enums\TaskStatusEnum;
use App\Filament\Resources\TeamTicketResource;
use App\Filament\Resources\TeamTicketResource\Pages\ListTeamTickets;
use App\Models\Ticket;
use App\Services\TeamPerformanceService;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Team-model requests the employee holds now or has held, limited to what
 * the viewer may see in the request screens.
 */
class TeamTicketsRelationManager extends RelationManager
{
    protected static string $relationship = 'tickets';

    protected static ?string $icon = 'heroicon-o-inbox-stack';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('ui.requests');
    }

    protected function getTableHeading(): string|Htmlable|null
    {
        return __('ui.employee_requests_heading');
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return TeamTicketResource::canViewAny();
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    protected function getTableQuery(): Builder
    {
        $employeeId = $this->getOwnerRecord()->getKey();

        return Ticket::query()
            ->visibleInTeamWorkflow(auth()->user())
            ->where(fn (Builder $q) => $q->where('employee_id', $employeeId)
                ->orWhereHas('assignments', fn (Builder $a) => $a->where('employee_id', $employeeId)));
    }

    public function table(Table $table): Table
    {
        $employeeId = $this->getOwnerRecord()->getKey();

        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['ticketType', 'team', 'employee', 'currentCycle.workCalendar']))
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('ticket_no')
                    ->label(__('ui.ticket_no'))
                    ->weight('bold')
                    ->searchable(),

                Tables\Columns\TextColumn::make('ticketType.name')
                    ->label(__('ui.ticket_type'))
                    ->badge()
                    ->color(fn (Ticket $record): string => $record->ticketType?->color ?: 'gray')
                    ->icon(fn (Ticket $record): ?string => $record->ticketType?->icon),

                Tables\Columns\TextColumn::make('team.name')
                    ->label(__('ui.team')),

                Tables\Columns\TextColumn::make('priority')
                    ->label(__('ui.priority'))
                    ->badge()
                    ->sortable(),

                Tables\Columns\TextColumn::make('status')
                    ->label(__('ui.status'))
                    ->badge()
                    ->sortable(),

                Tables\Columns\TextColumn::make('holder')
                    ->label(__('ui.employee_request_holder'))
                    ->state(fn (Ticket $record): string => (int) $record->employee_id === (int) $employeeId
                        ? __('ui.employee_request_on_them')
                        : ($record->employee?->name ?? __('ui.request_in_pool')))
                    ->color(fn (Ticket $record): string => (int) $record->employee_id === (int) $employeeId ? 'primary' : 'gray')
                    ->badge(),

                Tables\Columns\TextColumn::make('sla_state')
                    ->label(__('ui.sla_indicator'))
                    ->state(fn (Ticket $record): string => TeamTicketResource::slaLabel($record))
                    ->color(fn (Ticket $record): string => TeamTicketResource::slaColor($record))
                    ->icon('heroicon-o-clock')
                    ->badge(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label(__('ui.created_at'))
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('on_them')
                    ->label(__('ui.employee_request_holder'))
                    ->trueLabel(__('ui.employee_request_on_them'))
                    ->falseLabel(__('ui.employee_request_previously'))
                    ->queries(
                        true: fn (Builder $query) => $query->where('employee_id', $employeeId),
                        false: fn (Builder $query) => $query->where(fn (Builder $q) => $q->whereNull('employee_id')->orWhere('employee_id', '!=', $employeeId)),
                    ),
                Tables\Filters\SelectFilter::make('status')
                    ->label(__('ui.status'))
                    ->multiple()
                    ->options(collect(ListTeamTickets::STATUSES)
                        ->mapWithKeys(fn (TaskStatusEnum $status): array => [$status->value => $status->getLabel()])
                        ->all()),
                Tables\Filters\SelectFilter::make('priority')
                    ->label(__('ui.priority'))
                    ->multiple()
                    ->options(TaskPriorityEnum::class),
                Tables\Filters\Filter::make('active')
                    ->label(__('ui.employee_request_active_only'))
                    ->query(fn (Builder $query) => $query->whereIn('status', TeamPerformanceService::ACTIVE_STATUSES)),
            ])
            ->recordUrl(fn (Ticket $record): string => $record->panelUrl())
            ->emptyStateHeading(__('ui.employee_requests_empty'))
            ->emptyStateIcon('heroicon-o-inbox');
    }
}
