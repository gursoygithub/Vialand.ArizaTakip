<?php

namespace App\Filament\Resources\SlaPolicyResource\RelationManagers;

use App\Filament\Resources\GroupResource;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class GroupsRelationManager extends RelationManager
{
    protected static string $relationship = 'groups';

    protected static ?string $icon = 'heroicon-o-user-group';

    /**
     * @return string|null
     */
    public static function getModelLabel(): ?string
    {
        return __('ui.group');
    }

    /**
     * @return string|null
     */
    public static function getPluralModelLabel(): ?string
    {
        return __('ui.groups');
    }

    protected function getTableHeading(): string|Htmlable|null
    {
        return __('ui.groups');
    }

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('ui.groups_for', ['name' => $ownerRecord->name]);
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
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label(__('ui.name'))
                    ->badge()
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('company.name')
                    ->label(__('ui.company'))
                    ->icon('heroicon-o-building-library')
                    ->badge()
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('area.name')
                    ->label(__('ui.area'))
                    ->icon('heroicon-o-map')
                    ->badge()
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('manager.name')
                    ->label(__('ui.group_manager'))
                    ->icon('heroicon-o-user')
                    ->badge()
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('members_count')
                    ->label(__('ui.members'))
                    ->counts('members')
                    ->icon('heroicon-o-users')
                    ->badge()
                    ->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->label(__('ui.status'))
                    ->badge()
                    ->sortable(),
                Tables\Columns\TextColumn::make('createdBy.name')
                    ->visible(fn () => auth()->user()->hasRole('super_admin') || auth()->user()->can('view_all_groups'))
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
                //
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make(),
            ])
            ->actions([
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\ViewAction::make()
                        ->url(fn ($record) => GroupResource::getUrl('view', ['record' => $record])),
                    Tables\Actions\EditAction::make(),
                    Tables\Actions\DeleteAction::make(),
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

    protected function canCreate(): bool
    {
        return false;
    }
}
