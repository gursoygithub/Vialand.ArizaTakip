<?php

namespace App\Filament\Widgets;

use App\Enums\TaskStatusEnum;
use App\Models\Ticket;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

class RecentTicketsTable extends BaseWidget
{
    protected static ?int $sort = 5;

    protected int|string|array $columnSpan = 'full';

    // Heading resolved dynamically via getTableHeading() so __() can be used.
    protected static ?string $heading = null;

    public function getTableHeading(): ?string
    {
        return __('ui.widget_recent_tickets_heading');
    }

    public function getTableDescription(): ?string
    {
        return __('ui.widget_recent_tickets_desc');
    }

    public function table(Table $table): Table
    {
        $openStatuses = [
            TaskStatusEnum::OPEN->value,
            TaskStatusEnum::ASSIGNED->value,
            TaskStatusEnum::IN_PROGRESS->value,
        ];

        return $table
            ->query(
                Ticket::query()
                    ->visibleBy(auth()->user())
                    ->whereIn('status', $openStatuses)
                    ->latest('created_at')
                    ->limit(10)
            )
            ->paginated(false)
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading(__('ui.widget_recent_tickets_empty_heading'))
            ->emptyStateDescription(__('ui.widget_recent_tickets_empty_desc'))
            ->emptyStateIcon('heroicon-o-inbox')
            ->recordUrl(fn (Ticket $record) => \App\Filament\Resources\TicketResource::getUrl('view', ['record' => $record]))
            ->columns([
                Tables\Columns\TextColumn::make('ticket_no')
                    ->label(__('ui.ticket_no'))
                    ->searchable()
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('type_id')
                    ->label(__('ui.widget_recent_tickets_col_type'))
                    ->badge(),

                Tables\Columns\TextColumn::make('priority')
                    ->label(__('ui.priority'))
                    ->badge(),

                Tables\Columns\TextColumn::make('status')
                    ->label(__('ui.status'))
                    ->badge(),

                Tables\Columns\TextColumn::make('employee.name')
                    ->label(__('ui.widget_recent_tickets_col_assignee'))
                    ->placeholder('—')
                    ->limit(20),

                Tables\Columns\TextColumn::make('sla_deadline')
                    ->label(__('ui.sla'))
                    ->formatStateUsing(function (Ticket $record): string {
                        if (!$record->sla_deadline) {
                            return __('ui.sla_no_policy');
                        }
                        if (now()->isAfter($record->sla_deadline)) {
                            return __('ui.widget_recent_tickets_sla_breached');
                        }
                        $diff    = now()->diff($record->sla_deadline);
                        $hours   = (int) $diff->h + ($diff->days * 24);
                        $minutes = (int) $diff->i;
                        return __('ui.widget_recent_tickets_sla_ok', [
                            'hours'   => $hours,
                            'minutes' => $minutes,
                        ]);
                    })
                    ->badge()
                    ->color(function (Ticket $record): string {
                        if (!$record->sla_deadline) {
                            return 'gray';
                        }
                        if (now()->isAfter($record->sla_deadline)) {
                            return 'danger';
                        }
                        // Use SlaService so on_hold pause credits baked into
                        // sla_deadline are accounted for correctly.
                        $elapsed = app(\App\Services\SlaService::class)->getElapsedPercentage($record);
                        if ($elapsed === null) {
                            return 'gray';
                        }
                        return $elapsed >= 0.5 ? 'warning' : 'success';
                    }),

                Tables\Columns\TextColumn::make('created_at')
                    ->label(__('ui.widget_recent_tickets_col_created'))
                    ->dateTime('d.m.Y H:i')
                    ->sortable(),
            ]);
    }

    public static function canView(): bool
    {
        $user = auth()->user();
        if (!$user) {
            return false;
        }
        if ($user->hasRole('super_admin')) {
            return true;
        }
        return $user->can('ticket.view.all') || $user->can('ticket.view.group');
    }
}
