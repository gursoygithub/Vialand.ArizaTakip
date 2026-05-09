<?php

namespace App\Filament\Resources\EmployeeResource\RelationManagers;

use App\Enums\TaskPriorityEnum;
use App\Enums\TaskStatusEnum;
use App\Enums\TaskTypeEnum;
use App\Filament\Resources\TicketResource;
use App\Models\Unit;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class TicketsRelationManager extends RelationManager
{
    protected static string $relationship = 'tickets';

    protected static ?string $icon = 'heroicon-o-wrench-screwdriver';

    public static function getModelLabel(): ?string
    {
        return __('ui.employee_task');
    }

    public static function getPluralModelLabel(): ?string
    {
        return __('ui.employee_tasks');
    }

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('ui.employee_tasks');
    }

    public function form(Form $form): Form
    {
        return $form->schema([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->paginated([5, 10, 25, 50])
            ->columns([
                Tables\Columns\SpatieMediaLibraryImageColumn::make('images')
                    ->label(__('ui.images'))
                    ->collection('task_attachments')
                    ->square()
                    ->size(50),

                // Canonical ticket identifier (TKT-YYYY-NNNNN) — primary
                // human-readable handle for users to recognise rows.
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

                // Live SLA label — handles all lifecycle states (active,
                // paused, resolved, breached). Replaces the old
                // legacy-COMPLETED elapsed_time fallback.
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

                Tables\Columns\TextColumn::make('area.company.name')
                    ->label(__('ui.company'))
                    ->searchable()
                    ->sortable()
                    ->toggleable(),

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

                Tables\Columns\TextColumn::make('unit.name')
                    ->label(__('ui.unit'))
                    ->icon('heroicon-o-building-office')
                    ->badge()
                    ->color('primary')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('task_date')
                    ->label(__('ui.fault_date'))
                    ->icon('heroicon-o-calendar-days')
                    ->date()
                    ->sortable(),

                // Description — escaped via default text rendering. Previously
                // this used ->html() with formatStateUsing wrapping in <strong>,
                // which made it an XSS vector for any user-supplied description.
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
                    ->visible(fn () => auth()->user()?->hasRole('super_admin'))
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

                Tables\Filters\SelectFilter::make('unit_id')
                    ->label(__('ui.unit'))
                    ->multiple()
                    ->relationship('unit', 'name'),

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
            ->actions([
                Tables\Actions\ViewAction::make()
                    ->url(fn ($record) => TicketResource::getUrl('view', ['record' => $record])),
            ])
            // Read-only: this RM is a window into the employee's tickets.
            // Mutations happen through TicketResource directly.
            ->bulkActions([]);
    }

    public function isReadOnly(): bool
    {
        return true;
    }
}
