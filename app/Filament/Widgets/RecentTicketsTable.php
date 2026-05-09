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

    protected static ?string $heading = 'Son Açık Talepler';

    public function getTableHeading(): ?string
    {
        return 'Son Açık Talepler';
    }

    public function getTableDescription(): ?string
    {
        return 'En son oluşturulan açık talepler';
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
            ->emptyStateHeading('Açık talep bulunmuyor')
            ->emptyStateDescription('Şu anda açık, atanmış veya işlemdeki talep yok.')
            ->emptyStateIcon('heroicon-o-inbox')
            ->recordUrl(fn (Ticket $record) => \App\Filament\Resources\TicketResource::getUrl('view', ['record' => $record]))
            ->columns([
                Tables\Columns\TextColumn::make('ticket_no')
                    ->label('Talep No')
                    ->searchable()
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('type_id')
                    ->label('Tür')
                    ->badge(),

                Tables\Columns\TextColumn::make('priority')
                    ->label('Öncelik')
                    ->badge(),

                Tables\Columns\TextColumn::make('status')
                    ->label('Durum')
                    ->badge(),

                Tables\Columns\TextColumn::make('employee.name')
                    ->label('Atanan')
                    ->placeholder('—')
                    ->limit(20),

                Tables\Columns\TextColumn::make('sla_deadline')
                    ->label('SLA')
                    ->formatStateUsing(function (Ticket $record): string {
                        if (!$record->sla_deadline) {
                            return 'SLA Yok';
                        }
                        if (now()->isAfter($record->sla_deadline)) {
                            return '✗ İhlal';
                        }
                        $diff = now()->diff($record->sla_deadline);
                        $hours = (int) $diff->h + ($diff->days * 24);
                        $minutes = (int) $diff->i;
                        return "✓ {$hours}s {$minutes}dk";
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
                    ->label('Oluşturma')
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
