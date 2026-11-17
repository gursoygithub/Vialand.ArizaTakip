<?php

namespace App\Filament\Resources;

use App\Enums\ExemptionReasonEnum;
use App\Enums\ExemptionStatusEnum;
use App\Exceptions\TicketWorkflowException;
use App\Filament\Resources\SlaExemptionResource\Pages;
use App\Models\SlaExemption;
use App\Services\SlaExemptionService;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * "SLA Muafiyetleri": approvers see every request and decide the ones they
 * are independent of; everybody else sees the requests they raised.
 * Requests are created from the ticket page, never here.
 */
class SlaExemptionResource extends Resource
{
    protected static ?string $model = SlaExemption::class;

    protected static ?string $slug = 'sla-exemptions';

    protected static ?string $navigationIcon = 'heroicon-o-scale';

    protected static ?int $navigationSort = 20;

    public static function getModelLabel(): string
    {
        return __('ui.exemption');
    }

    public static function getPluralModelLabel(): string
    {
        return __('ui.exemptions');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('ui.reports');
    }

    private static function isApprover(): bool
    {
        $user = auth()->user();

        return (bool) ($user?->hasRole('super_admin') || $user?->can(SlaExemptionService::PERMISSION_APPROVE));
    }

    public static function canViewAny(): bool
    {
        return self::isApprover()
            || SlaExemption::query()->where('requested_by', auth()->id())->exists();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function getNavigationBadge(): ?string
    {
        if (! self::isApprover()) {
            return null;
        }

        $pending = SlaExemption::query()->where('status', ExemptionStatusEnum::Pending)->count();

        return $pending > 0 ? (string) $pending : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with([
            'ticket', 'requestedBy', 'decidedBy', 'cycle.team', 'cycle.breachEmployee',
        ]);

        return self::isApprover() ? $query : $query->where('requested_by', auth()->id());
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('ticket.ticket_no')
                    ->label(__('ui.ticket_no'))
                    ->weight('bold')
                    ->url(fn (SlaExemption $record): string => TeamTicketResource::getUrl('view', ['record' => $record->ticket_id]))
                    ->searchable(),
                Tables\Columns\TextColumn::make('cycle.team.name')
                    ->label(__('ui.team')),
                Tables\Columns\TextColumn::make('cycle.breach_attribution')
                    ->label(__('ui.breach_owner'))
                    ->badge()
                    ->description(fn (SlaExemption $record): ?string => $record->cycle?->breachEmployee?->name),
                Tables\Columns\TextColumn::make('reason')
                    ->label(__('ui.exemption_reason'))
                    ->badge()
                    ->color('gray'),
                Tables\Columns\TextColumn::make('note')
                    ->label(__('ui.note'))
                    ->limit(60)
                    ->tooltip(fn (SlaExemption $record): string => $record->note),
                Tables\Columns\TextColumn::make('requestedBy.name')
                    ->label(__('ui.exemption_requested_by')),
                Tables\Columns\TextColumn::make('status')
                    ->label(__('ui.status'))
                    ->badge(),
                Tables\Columns\TextColumn::make('decidedBy.name')
                    ->label(__('ui.exemption_decided_by'))
                    ->description(fn (SlaExemption $record): ?string => $record->decision_note)
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('created_at')
                    ->label(__('ui.created_at'))
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label(__('ui.status'))
                    ->options(ExemptionStatusEnum::class)
                    ->default(ExemptionStatusEnum::Pending->value),
                Tables\Filters\SelectFilter::make('reason')
                    ->label(__('ui.exemption_reason'))
                    ->options(ExemptionReasonEnum::class),
            ])
            ->actions([
                Tables\Actions\Action::make('approve')
                    ->label(__('ui.exemption_approve'))
                    ->icon('heroicon-o-check')
                    ->color('success')
                    ->visible(fn (SlaExemption $record): bool => app(SlaExemptionService::class)->canDecide($record, auth()->user()))
                    ->form([Forms\Components\Textarea::make('note')->label(__('ui.note'))->rows(2)])
                    ->action(fn (SlaExemption $record, array $data) => self::decide(
                        fn (SlaExemptionService $service) => $service->approve($record, auth()->user(), $data['note'] ?? null),
                    )),
                Tables\Actions\Action::make('reject')
                    ->label(__('ui.exemption_reject'))
                    ->icon('heroicon-o-x-mark')
                    ->color('danger')
                    ->visible(fn (SlaExemption $record): bool => app(SlaExemptionService::class)->canDecide($record, auth()->user()))
                    ->form([Forms\Components\Textarea::make('note')->label(__('ui.note'))->rows(2)->required()])
                    ->action(fn (SlaExemption $record, array $data) => self::decide(
                        fn (SlaExemptionService $service) => $service->reject($record, auth()->user(), $data['note']),
                    )),
            ])
            ->emptyStateHeading(__('ui.exemption_empty'));
    }

    private static function decide(callable $step): void
    {
        try {
            $step(app(SlaExemptionService::class));
            Notification::make()->title(__('ui.action_done'))->success()->send();
        } catch (TicketWorkflowException $exception) {
            Notification::make()->title($exception->getMessage())->danger()->send();
        }
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSlaExemptions::route('/'),
        ];
    }
}
