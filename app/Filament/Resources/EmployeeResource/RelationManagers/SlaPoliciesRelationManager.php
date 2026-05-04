<?php

namespace App\Filament\Resources\EmployeeResource\RelationManagers;

use App\Enums\TaskPriorityEnum;
use Carbon\CarbonInterval;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class SlaPoliciesRelationManager extends RelationManager
{
    protected static string $relationship = 'slaPolicies';

    protected static ?string $icon = 'heroicon-o-document-check';

    public static function getModelLabel(): ?string
    {
        return __('ui.employee_sla_policy');
    }

    public static function getPluralModelLabel(): ?string
    {
        return __('ui.employee_sla_policies');
    }

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('ui.employee_sla_policies');
    }

    protected function getTableHeading(): string|Htmlable|null
    {
        return __('ui.employee_sla_policies');
    }

    public function table(Table $table): Table
    {
        return $table
            ->description('Personel yalnızca atanmış SLA politikaları kapsamında görev açabilir. Bir SLA kaldırılsa bile mevcut görevler etkilenmez.')
            ->columns([
                Tables\Columns\TextColumn::make('area.name')
                    ->label(__('ui.area'))
                    ->icon('heroicon-o-map')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('subArea.name')
                    ->label(__('ui.sub_area'))
                    ->icon('heroicon-o-map-pin')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('unit.name')
                    ->label(__('ui.unit'))
                    ->icon('heroicon-o-building-office')
                    ->searchable()
                    ->sortable(),

                // Priority with enum-resolved label and color, matching the
                // way priority is rendered in the Tickets list and on the
                // performance dashboard.
                Tables\Columns\TextColumn::make('priority')
                    ->label(__('ui.priority'))
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state instanceof TaskPriorityEnum ? $state->getLabel() : $state)
                    ->color(fn ($state) => $state instanceof TaskPriorityEnum ? $state->getColor() : 'gray')
                    ->sortable(),

                // Human-readable deadline ("4sa", "1g 6sa") instead of raw
                // minute count. CarbonInterval handles the locale.
                Tables\Columns\TextColumn::make('deadline_minutes')
                    ->label(__('ui.time_in_minutes'))
                    ->icon('heroicon-o-clock')
                    ->badge()
                    ->alignCenter()
                    ->formatStateUsing(fn ($state) =>
                        $state ? CarbonInterval::minutes((int) $state)->cascade()->forHumans(['short' => true]) : '—'
                    ),

                Tables\Columns\TextColumn::make('success_threshold')
                    ->label('Başarı Eşiği')
                    ->formatStateUsing(fn ($state) => $state !== null ? '%' . round($state, 1) : '—')
                    ->alignCenter()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('pivot.created_at')
                    ->label('Atanma')
                    ->icon('heroicon-o-calendar-days')
                    ->dateTime()
                    ->sortable(),

                Tables\Columns\TextColumn::make('pivot.createdBy.name')
                    ->visible(fn () => auth()->user()?->hasRole('super_admin'))
                    ->label(__('ui.created_by'))
                    ->icon('heroicon-o-user')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('pivot.updatedBy.name')
                    ->label(__('ui.updated_by'))
                    ->icon('heroicon-o-user')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('pivot.updated_at')
                    ->label(__('ui.updated_at'))
                    ->icon('heroicon-o-calendar-days')
                    ->dateTime()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([])
            ->headerActions([
                Tables\Actions\AttachAction::make()
                    ->label('Yeni SLA Ata')
                    ->modalHeading(__('ui.attach_employee_sla'))
                    ->color('info')
                    ->preloadRecordSelect()
                    ->recordSelectOptionsQuery(fn (Builder $query) =>
                        $query->with(['area', 'subArea', 'unit'])
                    )
                    ->recordTitle(fn ($record) =>
                        "{$record->area?->name} > {$record->subArea?->name} > {$record->unit?->name} [{$record->priority->getLabel()}]"
                    )
                    ->form(fn (Tables\Actions\AttachAction $action): array => [
                        $action->getRecordSelect()
                            ->searchable()
                            ->getSearchResultsUsing(function (string $search) {
                                return \App\Models\SlaPolicy::query()
                                    ->with(['area', 'subArea', 'unit'])
                                    ->where(function (Builder $q) use ($search) {
                                        $q->whereHas('area', fn ($q) => $q->where('name', 'like', "%{$search}%"))
                                            ->orWhereHas('unit', fn ($q) => $q->where('name', 'like', "%{$search}%"));
                                    })
                                    ->limit(50)
                                    ->get()
                                    ->mapWithKeys(fn ($record) => [
                                        $record->getKey() => "{$record->area?->name} > {$record->unit?->name} [{$record->priority->getLabel()}]",
                                    ])
                                    ->toArray();
                            }),
                    ])
                    ->mutateFormDataUsing(function (array $data): array {
                        $data['created_by'] = auth()->id();
                        return $data;
                    }),
            ])
            ->actions([
                Tables\Actions\DetachAction::make()
                    ->label('Kaldır')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Personelden SLA Kaldır')
                    ->modalDescription('Bu SLA politikasını personelden kaldırmak istediğinize emin misiniz?')
                    ->modalSubmitActionLabel('Onayla'),
            ])
            // Suppress the default DetachBulkAction. Bulk detach has no
            // pre-flight feedback for mixed selections; per-row Detach is
            // the deliberate path. Matches the bulk-action removal pattern
            // applied to TicketResource.
            ->bulkActions([])
            ->modifyQueryUsing(fn (Builder $query) =>
                $query->whereNull('employee_sla_policies.deleted_at')
            );
    }

    // AttachAction + DetachAction need isReadOnly = false to remain wired up.
    // canEdit isn't overridden because there is no EditAction registered.
    public function isReadOnly(): bool
    {
        return false;
    }
}
