<?php

namespace App\Filament\Resources\EmployeeResource\RelationManagers;

use App\Enums\ActiveStatusEnum;
use App\Filament\Resources\GroupResource;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class GroupMembershipsRelationManager extends RelationManager
{
    protected static string $relationship = 'groupMemberships';

    protected static ?string $icon = 'heroicon-o-user-group';

    protected static function getModelLabel(): ?string
    {
        return __('ui.employee_group_membership');
    }

    protected static function getPluralModelLabel(): ?string
    {
        return __('ui.employee_group_memberships');
    }

    protected function getTableHeading(): string|Htmlable|null
    {
        return __('ui.employee_group_memberships');
    }

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('ui.employee_group_memberships');
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
            ->recordTitleAttribute('id')
            ->columns([
                Tables\Columns\TextColumn::make('group.name')
                    ->label(__('ui.group'))
                    ->badge()
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('group.company.name')
                    ->label(__('ui.company'))
                    ->icon('heroicon-o-building-office')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('group.area.name')
                    ->label(__('ui.area'))
                    ->icon('heroicon-o-map')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('group.manager.name')
                    ->label(__('ui.group_manager'))
                    ->icon('heroicon-o-user')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('group.status')
                    ->label(__('ui.status'))
                    ->badge()
                    ->alignCenter()
                    ->searchable()
                    ->sortable(),
            ])
            ->filters([
                    Tables\Filters\SelectFilter::make('group_id')
                        ->label(__('ui.group'))
                        ->options(function () {
                            $ownerRecord = $this->getOwnerRecord();
                            return $ownerRecord->groupMemberships()
                                ->with('group')
                                ->get()
                                ->pluck('group.name', 'group_id')
                                ->filter();
                        })
                        ->searchable(),
                    Tables\Filters\SelectFilter::make('group_status')
                        ->label(__('ui.status'))
                        ->options(ActiveStatusEnum::class)
                        ->query(fn (Builder $query, array $data) => $query->when(
                            $data['value'] !== null,
                            fn (Builder $q) => $q->whereHas('group', fn (Builder $q) => $q->where('status', $data['value']))
                        )),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make(),
            ])
            ->actions([
                Tables\Actions\ViewAction::make()
                    ->url(fn ($record) => GroupResource::getUrl('view', ['record' => $record])),
            ])
            ->bulkActions([
                //
            ]);
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    public function canCreate(): bool
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

}
