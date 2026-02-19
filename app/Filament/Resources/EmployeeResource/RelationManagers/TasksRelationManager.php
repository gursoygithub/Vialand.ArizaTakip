<?php

namespace App\Filament\Resources\EmployeeResource\RelationManagers;

use App\Enums\ActiveStatusEnum;
use App\Enums\TaskPriorityEnum;
use App\Enums\TaskStatusEnum;
use App\Enums\TaskTypeEnum;
use App\Filament\Resources\TaskResource;
use App\Models\Employee;
use App\Models\Unit;
use Carbon\CarbonInterval;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class TasksRelationManager extends RelationManager
{
    protected static string $relationship = 'tasks';

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
        return $form
            ->schema([
                //
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('updated_at', 'desc')
            ->paginated([5, 10, 25, 50])
            ->columns([
                Tables\Columns\SpatieMediaLibraryImageColumn::make('images')
                    ->label(__('ui.images'))
                    ->collection('task_attachments')
                    ->square()
                    ->size(50),
                Tables\Columns\TextColumn::make('priority')
                    ->label(__('ui.priority'))
                    ->badge()
                    ->sortable(),
                Tables\Columns\TextColumn::make('type_id')
                    ->hidden()
                    ->label(__('ui.type'))
                    ->badge()
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('area.name')
                    ->hidden()
                    ->label(__('ui.area'))
                    ->icon('heroicon-o-map')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('subArea.name')
                    ->hidden()
                    ->label(__('ui.sub_area'))
                    ->searchable()
                    ->sortable()
                    ->icon('heroicon-o-map-pin'),
                Tables\Columns\TextColumn::make('unit.name')
                    ->hidden()
                    ->label(__('ui.unit'))
                    ->icon('heroicon-o-building-office')
                    ->badge()
                    ->color('primary')
                    ->searchable()
                    ->sortable(),
//                Tables\Columns\TextColumn::make('employee.name')
//                    ->label(__('ui.related_person'))
//                    ->placeholder(__('ui.not_assigned'))
//                    ->alignCenter()
//                    ->icon('heroicon-o-user')
//                    ->badge()
//                    ->color('primary')
//                    ->searchable()
//                    ->sortable(),
                Tables\Columns\TextColumn::make('task_date')
                    ->label(__('ui.fault_date'))
                    ->icon('heroicon-o-calendar-days')
                    ->date()
                    ->badge()
                    ->color('primary')
                    ->sortable(),
                Tables\Columns\TextColumn::make('description')
                    ->label(__('ui.description'))
                    ->limit(30)
                    ->wrap()
                    ->formatStateUsing(fn ($state) => $state ? "<strong>{$state}</strong>" : $state)
                    ->html()
                    ->tooltip(fn ($record) => $record->description)
                    ->searchable()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('unit_description')
                    ->label(__('ui.unit_description'))
                    ->limit(30)
                    ->wrap()
                    ->formatStateUsing(fn ($state) => $state ? "<strong>{$state}</strong>" : $state)
                    ->html()
                    ->tooltip(fn ($record) => $record->unit_description)
                    ->searchable()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('created_at')
                    ->label(__('ui.created_at'))
                    ->icon('heroicon-o-calendar-days')
                    ->dateTime()
                    ->sortable(),
                Tables\Columns\TextColumn::make('due_date')
                    ->label(__('ui.due_date'))
                    ->icon('heroicon-o-calendar-days')
                    ->date()
                    ->badge()
                    ->color('success')
                    ->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->label(__('ui.status'))
                    ->badge()
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('elapsed_time')
                    ->label(__('ui.elapsed_time'))
                    ->icon('heroicon-o-clock')
                    ->badge()
                    ->color(fn ($record) => $record->status->value === TaskStatusEnum::COMPLETED->value ? 'success' : 'warning')
                    ->getStateUsing(function ($record) {
                        // 1. Başlangıç noktası: Kayıt oluşturulma tarihi
                        $start = $record->created_at;

                        if (!$start) return null;

                        // 2. Bitiş noktası: Tamamlandıysa due_date, değilse şu an (now)
                        $end = ($record->status->value === TaskStatusEnum::COMPLETED->value && $record->due_date)
                            ? $record->due_date
                            : now();

                        // 3. Farkı hesapla ve formatla
                        // 'parts' => 2: Sadece en büyük iki birimi gösterir (örn: 10 gün 2 dakika)
                        // 'join' => ' ': Birimler arasına boşluk koyar
                        return $start->diffForHumans($end, [
                            'syntax' => \Carbon\CarbonInterface::DIFF_ABSOLUTE,
                            'parts' => 2,
                            'join' => ' ',
                        ]);
                    })
                    ->description(fn ($record) => $record->status->value === TaskStatusEnum::COMPLETED->value
                        ? __('ui.time_taken_to_complete')
                        : __('ui.waiting_time'))
                    ->sortable(),
                Tables\Columns\TextColumn::make('completedBy.name')
                    ->label(__('ui.closed_by'))
                    ->icon('heroicon-o-user')
                    ->badge()
                    ->color('success')
                    ->searchable()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('createdBy.name')
                    ->visible(fn () => auth()->user()->hasRole('super_admin') || auth()->user()->can('view_all_tasks'))
                    ->label(__('ui.created_by'))
                    ->icon('heroicon-o-user')
                    ->searchable()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('updatedBy.name')
                    ->label(__('ui.updated_by'))
                    ->icon('heroicon-o-user')
                    ->searchable()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('updated_at')
                    ->label(__('ui.updated_at'))
                    ->icon('heroicon-o-calendar-days')
                    ->getStateUsing(fn ($record) => $record->updated_by ? $record->updated_at : null)
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                // filter by priority
                Tables\Filters\SelectFilter::make('priority')
                    ->label(__('ui.priority'))
                    ->options(
                        collect(TaskPriorityEnum::cases())
                            ->mapWithKeys(fn ($case) => [$case->value => $case->getLabel()])
                            ->toArray()
                    ),
                // filter by related person
//                Tables\Filters\SelectFilter::make('employee_id')
//                    ->label(__('ui.related_person'))
//                    ->options(
//                        Employee::all()
//                            ->where('status', ActiveStatusEnum::ACTIVE)
//                            ->pluck('name', 'id')
//                    )
//                    ->preload()
//                    ->searchable(),
                // filter by unit
                Tables\Filters\SelectFilter::make('unit_id')
                    ->label(__('ui.unit'))
                    ->options(
                        Unit::all()
                            ->pluck('name', 'id')
                    ),
                // filter by type
                Tables\Filters\SelectFilter::make('type_id')
                    ->label(__('ui.type'))
                    ->options(
                        collect(TaskTypeEnum::cases())
                            ->mapWithKeys(fn ($case) => [$case->value => $case->getLabel()])
                            ->toArray()
                    ),
            ])
            ->headerActions([
                //Tables\Actions\CreateAction::make(),
            ])
            ->actions([
                Tables\Actions\ViewAction::make()
                    ->url(fn ($record) => TaskResource::getUrl('view', ['record' => $record])),
            ])
            ->bulkActions([
                //
            ]);
    }

    protected function canCreate(): bool
    {
        return false;
    }

    protected function canEdit(Model $record): bool
    {
        return false;
    }

    protected function canDelete(Model $record): bool
    {
        return false;
    }

    public function isReadOnly(): bool
    {
        return false;
    }
}
