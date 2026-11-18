<?php

namespace App\Filament\Resources\EmployeeResource\RelationManagers;

use App\Enums\TaskPriorityEnum;
use App\Enums\TaskStatusEnum;
use App\Filament\Resources\TeamTicketResource;
use App\Filament\Resources\TeamTicketResource\Pages\ListTeamTickets;
use App\Models\Team;
use App\Models\Ticket;
use App\Models\User;
use App\Services\TeamPerformanceService;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Team-model requests the employee is involved in (holds or held, opened,
 * supervises the team of, or is kept informed on), limited to what the
 * viewer may see in the request screens.
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

    public const INVOLVEMENTS = ['holder', 'previously', 'requester', 'supervisor', 'watcher'];

    protected function getTableQuery(): Builder
    {
        return Ticket::query()
            ->visibleInTeamWorkflow(auth()->user())
            ->where(function (Builder $query): void {
                foreach (self::INVOLVEMENTS as $involvement) {
                    $query->orWhere(fn (Builder $q) => $this->applyInvolvement($q, $involvement));
                }
            });
    }

    private function applyInvolvement(Builder $query, string $involvement): Builder
    {
        $employeeId = $this->getOwnerRecord()->getKey();

        return match ($involvement) {
            'holder' => $query->where('employee_id', $employeeId),
            'previously' => $query
                ->whereHas('assignments', fn (Builder $a) => $a->where('employee_id', $employeeId))
                ->where(fn (Builder $q) => $q->whereNull('employee_id')->orWhere('employee_id', '!=', $employeeId)),
            'requester' => $query->whereIn('created_by', $this->requesterUserIds()),
            'supervisor' => $query->whereIn('team_id', Team::query()->where('supervisor_employee_id', $employeeId)->select('id')),
            'watcher' => $query->whereIn('id', DB::table('ticket_watchers')->where('employee_id', $employeeId)->select('ticket_id')),
        };
    }

    /**
     * Panel accounts of this person, matched by e-mail like User::employee().
     *
     * @return array<int, int>
     */
    private function requesterUserIds(): array
    {
        $email = $this->getOwnerRecord()->email;

        return filled($email) ? User::query()->where('email', $email)->pluck('id')->all() : [];
    }

    /**
     * @return array<int, string>
     */
    private function involvementsOf(Ticket $record): array
    {
        $employeeId = (int) $this->getOwnerRecord()->getKey();
        $holds = (int) $record->employee_id === $employeeId;

        return array_values(array_filter([
            $holds ? __('ui.employee_request_on_them') : null,
            ! $holds && $record->assignments->isNotEmpty() ? __('ui.employee_request_previously') : null,
            in_array((int) $record->created_by, $this->requesterUserIds(), true) ? __('ui.employee_involvement_requester') : null,
            (int) $record->team?->supervisor_employee_id === $employeeId ? __('ui.employee_involvement_supervisor') : null,
            $record->watchers->isNotEmpty() ? __('ui.employee_involvement_watcher') : null,
        ]));
    }

    public function table(Table $table): Table
    {
        $employeeId = $this->getOwnerRecord()->getKey();

        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with([
                'ticketType', 'team', 'employee', 'currentCycle.workCalendar',
                'assignments' => fn ($a) => $a->where('employee_id', $employeeId),
                'watchers' => fn ($w) => $w->whereKey($employeeId),
            ]))
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

                Tables\Columns\TextColumn::make('involvement')
                    ->label(__('ui.employee_involvement'))
                    ->state(fn (Ticket $record): array => $this->involvementsOf($record))
                    ->badge()
                    ->color('info'),

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
                Tables\Filters\SelectFilter::make('involvement')
                    ->label(__('ui.employee_involvement'))
                    ->options([
                        'holder' => __('ui.employee_request_on_them'),
                        'previously' => __('ui.employee_request_previously'),
                        'requester' => __('ui.employee_involvement_requester'),
                        'supervisor' => __('ui.employee_involvement_supervisor'),
                        'watcher' => __('ui.employee_involvement_watcher'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null)
                        ? $this->applyInvolvement($query, $data['value'])
                        : $query),
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
