<?php

namespace App\Filament\Resources\EmployeeResource\RelationManagers;

use App\Enums\ActiveStatusEnum;
use Filament\Forms;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class SlaPoliciesRelationManager extends RelationManager
{
    protected static string $relationship = 'slaPolicies';

    protected static ?string $icon = 'heroicon-o-document-check';

    /**
     * @return string|null
     */
    public static function getModelLabel(): ?string
    {
        return __('ui.employee_sla_policy');
    }

    protected static function getPluralModelLabel(): ?string
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

                Tables\Columns\TextColumn::make('priority')
                    ->label(__('ui.priority'))
                    ->sortable(),

                Tables\Columns\TextColumn::make('deadline_minutes')
                    ->label(__('ui.time_in_minutes'))
                    ->icon('heroicon-o-clock')
                    ->badge()
                    ->alignCenter(),

//                Tables\Columns\TextColumn::make('pivot.status')
//                    ->label('Durum')
//                    ->badge(),

                Tables\Columns\TextColumn::make('pivot.created_at')
                    ->label('Atanma')
                    ->dateTime(),


                Tables\Columns\TextColumn::make('pivot.createdBy.name')
                    ->visible(fn () => auth()->user()->hasRole('super_admin') || auth()->user()->can('view_all_sla_policies'))
                    ->label(__('ui.created_by'))
                    ->icon('heroicon-o-user')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('pivot.created_at')
                    ->label(__('ui.created_at'))
                    ->icon('heroicon-o-calendar-days')
                    ->dateTime(),

                Tables\Columns\TextColumn::make('pivot.updatedBy.name')
                    ->label(__('ui.updated_by'))
                    ->icon('heroicon-o-user')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('pivot.updated_at')
                    ->label(__('ui.updated_at'))
                    ->icon('heroicon-o-calendar-days')
                    ->getStateUsing(fn ($record) => $record->updated_by ? $record->updated_at : null)
                    ->dateTime()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //Tables\Filters\TrashedFilter::make(),
            ])
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
                    ->recordSelectSearchColumns([])
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
                        //$data['status'] = ActiveStatusEnum::ACTIVE->value;
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
            ->modifyQueryUsing(fn (Builder $query) =>
            $query->whereNull('employee_sla_policies.deleted_at')
            );
    }

    public function isReadOnly(): bool
    {
        return false;
    }
}