<?php

namespace App\Filament\Resources\UnitResource\RelationManagers;

use App\Enums\TaskPriorityEnum;
use App\Enums\TaskStatusEnum;
use App\Enums\TaskTypeEnum;
use App\Filament\Resources\TicketResource;
use Filament\Forms\Form;
use Filament\Resources\Components\Tab;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;

class TicketsRelationManager extends RelationManager
{
    protected static string $relationship = 'tickets';

    public static function getModelLabel(): ?string
    {
        return __('ui.related_tasks');
    }

    public static function getPluralModelLabel(): ?string
    {
        return __('ui.related_tasks');
    }

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('ui.related_tasks');
    }

    public function form(Form $form): Form
    {
        return $form->schema([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $this->applyTaskPermissionFilter($query))
            ->defaultSort('created_at', 'desc')
            ->paginated([5, 10, 25, 50])
            ->columns([
                Tables\Columns\SpatieMediaLibraryImageColumn::make('images')
                    ->label(__('ui.images'))
                    ->collection('task_attachments')
                    ->square()
                    ->size(50),

                // Canonical ticket identifier — primary search handle.
                Tables\Columns\TextColumn::make('ticket_no')
                    ->label(__('ui.ticket_no') ?: 'Talep No')
                    ->weight('bold')
                    ->copyable()
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('priority')
                    ->label(__('ui.priority'))
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state instanceof TaskPriorityEnum ? $state->getLabel() : $state)
                    ->color(fn ($state) => $state instanceof TaskPriorityEnum ? $state->getColor() : 'gray')
                    ->sortable(),

                Tables\Columns\TextColumn::make('status')
                    ->label(__('ui.status'))
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state instanceof TaskStatusEnum ? $state->getLabel() : $state)
                    ->color(fn ($state) => $state instanceof TaskStatusEnum ? $state->getColor() : 'gray')
                    ->sortable(),

                // Live SLA label (handles paused / breached / on-time / etc.)
                // — same helper the Tickets list and dashboard use.
                Tables\Columns\TextColumn::make('sla_status_label')
                    ->label('SLA')
                    ->badge()
                    ->getStateUsing(fn ($record) => $record->getSlaStatusLabel())
                    ->color(fn ($record) =>
                        $record->sla_breached ? 'danger'
                        : ($record->status === TaskStatusEnum::ON_HOLD ? 'warning' : 'success')
                    ),

                Tables\Columns\TextColumn::make('type_id')
                    ->label(__('ui.type'))
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state instanceof TaskTypeEnum ? $state->getLabel() : $state)
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('area.name')
                    ->label(__('ui.area'))
                    ->icon('heroicon-o-map')
                    ->searchable()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('subArea.name')
                    ->label(__('ui.sub_area'))
                    ->icon('heroicon-o-map-pin')
                    ->searchable()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                // The assignee — relevant in unit context: who owns this
                // unit's tickets right now.
                Tables\Columns\TextColumn::make('employee.name')
                    ->label(__('ui.related_person'))
                    ->placeholder(__('ui.not_assigned'))
                    ->alignCenter()
                    ->icon('heroicon-o-user')
                    ->badge()
                    ->color('primary')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('task_date')
                    ->label(__('ui.fault_date'))
                    ->icon('heroicon-o-calendar-days')
                    ->date()
                    ->sortable(),

                // Description: default text rendering (escaped). The previous
                // ->html() + formatStateUsing wrap in <strong> was an XSS
                // vector for any user-supplied description.
                Tables\Columns\TextColumn::make('description')
                    ->label(__('ui.description'))
                    ->limit(30)
                    ->wrap()
                    ->tooltip(fn ($record) => $record->description)
                    ->searchable()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('sla_deadline')
                    ->label('SLA Son Tarih')
                    ->icon('heroicon-o-clock')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('assigned_at')
                    ->label('Atanma')
                    ->icon('heroicon-o-user-plus')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('closed_at')
                    ->label('Kapanma')
                    ->icon('heroicon-o-check-circle')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('closedBy.name')
                    ->label(__('ui.closed_by'))
                    ->icon('heroicon-o-user')
                    ->badge()
                    ->color('success')
                    ->searchable()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('createdBy.name')
                    ->visible(fn () => auth()->user()?->hasRole('super_admin') || auth()->user()?->can('view_all_tasks'))
                    ->label(__('ui.created_by'))
                    ->icon('heroicon-o-user')
                    ->searchable()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('created_at')
                    ->label(__('ui.created_at'))
                    ->icon('heroicon-o-calendar-days')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label(__('ui.status'))
                    ->multiple()
                    ->options(collect(TaskStatusEnum::cases())
                        ->mapWithKeys(fn ($c) => [$c->value => $c->getLabel()])
                        ->toArray()),

                Tables\Filters\SelectFilter::make('priority')
                    ->label(__('ui.priority'))
                    ->multiple()
                    ->options(collect(TaskPriorityEnum::cases())
                        ->mapWithKeys(fn ($c) => [$c->value => $c->getLabel()])
                        ->toArray()),

                Tables\Filters\SelectFilter::make('type_id')
                    ->label(__('ui.type'))
                    ->multiple()
                    ->options(collect(TaskTypeEnum::cases())
                        ->mapWithKeys(fn ($c) => [$c->value => $c->getLabel()])
                        ->toArray()),

                // Active SLA breach surface — currently breached, not yet
                // closed. Mirrors the dashboard's "İhlal" semantic.
                Tables\Filters\Filter::make('breached')
                    ->label('SLA İhlali')
                    ->query(fn (Builder $q) => $q
                        ->where('sla_breached', true)
                        ->whereNotIn('status', [
                            TaskStatusEnum::RESOLVED,
                            TaskStatusEnum::CLOSED,
                            TaskStatusEnum::CANCELLED,
                        ])),
            ])
            ->headerActions([
                Tables\Actions\ExportAction::make()
                    ->exporter(\App\Filament\Exports\TaskExporter::class)
                    ->label(__('ui.export'))
                    ->modalHeading(__('ui.export'))
                    ->icon('heroicon-o-arrow-down-tray')
                    ->visible(fn () => auth()->user()?->hasRole('super_admin') || auth()->user()?->can('export_tasks')),
            ])
            ->actions([
                Tables\Actions\ViewAction::make()
                    ->url(fn ($record) => TicketResource::getUrl('view', ['record' => $record])),
            ])
            ->bulkActions([]);
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    /**
     * Restrict the relation query to tickets the viewer is allowed to see.
     * super_admin and `view_all_tasks` see everything; everyone else is
     * scoped to their own creations + tickets assigned to them.
     */
    protected function applyTaskPermissionFilter(Builder|Relation $query): Builder
    {
        if ($query instanceof Relation) {
            $query = $query->getQuery();
        }

        $user = auth()->user();

        $hasPermission =
            $user->hasRole('super_admin') ||
            $user->can('view_all_tasks');

        if (!$hasPermission) {
            $query->where(function ($query) use ($user) {
                $query
                    ->where('created_by', $user->id)
                    ->orWhere('employee_id', function ($subQuery) use ($user) {
                        $subQuery->select('id')
                            ->from('employees')
                            ->where('email', $user->email);
                    });
            });
        }

        return $query;
    }

    public function getTabs(): array
    {
        $owner = $this->getOwnerRecord();

        $count = fn (Builder $query) => $this->applyTaskPermissionFilter($query)->count();

        return [
            'all' => Tab::make(__('ui.all'))
                ->badge(fn () => $count($owner->tickets()->getQuery())),

            'active' => Tab::make('Aktif')
                ->badge(fn () => $count($owner->tickets()
                    ->whereIn('status', [
                        TaskStatusEnum::OPEN,
                        TaskStatusEnum::ASSIGNED,
                        TaskStatusEnum::IN_PROGRESS,
                    ])
                    ->getQuery()))
                ->badgeColor('warning')
                ->modifyQueryUsing(fn (Builder $query) => $query->whereIn('status', [
                    TaskStatusEnum::OPEN,
                    TaskStatusEnum::ASSIGNED,
                    TaskStatusEnum::IN_PROGRESS,
                ])),

            'on_hold' => Tab::make('Beklemede')
                ->badge(fn () => $count($owner->tickets()->where('status', TaskStatusEnum::ON_HOLD)->getQuery()))
                ->badgeColor('warning')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', TaskStatusEnum::ON_HOLD)),

            'resolved' => Tab::make('Çözüldü')
                ->badge(fn () => $count($owner->tickets()->where('status', TaskStatusEnum::RESOLVED)->getQuery()))
                ->badgeColor('success')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', TaskStatusEnum::RESOLVED)),

            'closed' => Tab::make('Kapatıldı')
                ->badge(fn () => $count($owner->tickets()->where('status', TaskStatusEnum::CLOSED)->getQuery()))
                ->badgeColor('gray')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', TaskStatusEnum::CLOSED)),

            'breached' => Tab::make('İhlal')
                ->badge(fn () => $count($owner->tickets()->where('sla_breached', true)->getQuery()))
                ->badgeColor('danger')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('sla_breached', true)),
        ];
    }
}
