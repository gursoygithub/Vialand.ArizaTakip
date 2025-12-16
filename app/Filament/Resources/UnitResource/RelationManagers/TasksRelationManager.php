<?php

namespace App\Filament\Resources\UnitResource\RelationManagers;

use App\Enums\TaskStatusEnum;
use App\Enums\TaskTypeEnum;
use App\Filament\Resources\TaskResource;
use App\Models\Task;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Components\Tab;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class TasksRelationManager extends RelationManager
{
    protected static string $relationship = 'tasks';

    protected static function getModelLabel(): ?string
    {
        return __('ui.related_tasks');
    }

    protected static function getPluralModelLabel(): ?string
    {
        return __('ui.related_tasks');
    }

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('ui.related_tasks');
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('id')
                    ->required()
                    ->maxLength(255),
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
                Tables\Columns\TextColumn::make('type_id')
                    ->label(__('ui.type'))
                    ->badge()
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('area.name')
                    ->label(__('ui.area'))
                    ->icon('heroicon-o-map')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('subArea.name')
                    ->label(__('ui.sub_area'))
                    ->searchable()
                    ->sortable()
                    ->icon('heroicon-o-map-pin'),
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
                Tables\Columns\TextColumn::make('status')
                    ->label(__('ui.status'))
                    ->badge()
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('completedBy.name')
                    ->label(__('ui.closed_by'))
                    ->icon('heroicon-o-user')
                    ->badge()
                    ->color('success')
                    ->searchable()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('due_date')
                    ->label(__('ui.due_date'))
                    ->icon('heroicon-o-calendar-days')
                    ->date()
                    ->badge()
                    ->color('success')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('createdBy.name')
                    ->visible(fn () => auth()->user()->hasRole('super_admin') || auth()->user()->can('view_all_tasks'))
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
                Tables\Filters\SelectFilter::make('type_id')
                    ->label(__('ui.type'))
                    ->options(
                        collect(TaskTypeEnum::cases())
                            ->mapWithKeys(fn ($case) => [$case->value => $case->getLabel()])
                            ->toArray()
                    ),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make(),
                Tables\Actions\ExportAction::make()
                    ->exporter(\App\Filament\Exports\TaskExporter::class)
                    ->label(__('ui.export'))
                    ->modalHeading(__('ui.export'))
                    ->icon('heroicon-o-arrow-down-tray')
                    ->visible(fn () => auth()->user()->hasRole('super_admin') || auth()->user()->can('export_tasks')),
            ])
            ->actions([
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\ViewAction::make()
                        ->url(fn ($record) => TaskResource::getUrl('view', ['record' => $record])),
                    Tables\Actions\EditAction::make()
                        ->url(fn ($record) => TaskResource::getUrl('edit', ['record' => $record])),
                    Tables\Actions\DeleteAction::make()
                        ->requiresConfirmation(),
                ]),
            ])
            ->bulkActions([
                //
            ]);
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    public function getTabs(): array
    {
        $tabs = [];

        $allQuery = Task::query()->where('unit_id', $this->ownerRecord->id);

        $tabs['all'] = Tab::make(__('ui.all'))
            ->badge($allQuery->count())
            ->modifyQueryUsing(function ($query) {
                return $query;
            });

        $tabs['pending'] = Tab::make(__('ui.pending'))
            ->badge((clone $allQuery)->where('status', TaskStatusEnum::PENDING)->count())
            ->badgeIcon('heroicon-o-clock')
            ->badgeColor('warning')
            ->modifyQueryUsing(function ($query) {
                return $query->where('status', TaskStatusEnum::PENDING);
            });

        $tabs['completed'] = Tab::make(__('ui.completed'))
            ->badge((clone $allQuery)->where('status', TaskStatusEnum::COMPLETED)->count())
            ->badgeIcon('heroicon-o-check-circle')
            ->badgeColor('success')
            ->modifyQueryUsing(function ($query) {
                return $query->where('status', TaskStatusEnum::COMPLETED);
            });

        $tabs['winter_maintenance'] = Tab::make(__('ui.winter_maintenance'))
            ->badge((clone $allQuery)->where('status', TaskStatusEnum::WINTER_MAINTENANCE)->count())
            ->badgeIcon('heroicon-o-lifebuoy')
            ->badgeColor('info')
            ->modifyQueryUsing(function ($query) {
                return $query->where('status', TaskStatusEnum::WINTER_MAINTENANCE);
            });

        return $tabs;
    }

//    protected function canView(Model $record): bool
//    {
//        return auth()->user()->hasRole('super_admin') || auth()->user()->can('view_all_tasks') || $record->created_by == auth()->id();
//    }

    protected function canCreate(): bool
    {
        return false;
    }

//    protected function canEdit(Model $record): bool
//    {
//        return false;
//    }

    protected function canDelete(Model $record): bool
    {
        return $record->status !== TaskStatusEnum::COMPLETED && (auth()->user()->hasRole('super_admin') || auth()->user()->can('delete_tasks') || $record->created_by == auth()->id());
    }
}
