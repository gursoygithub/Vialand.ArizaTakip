<?php

namespace App\Filament\Resources\EmployeeResource\RelationManagers;

use App\Enums\ActiveStatusEnum;
use App\Filament\Resources\GroupResource;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class GroupMembershipsRelationManager extends RelationManager
{
    protected static string $relationship = 'groupMemberships';

    protected static ?string $icon = 'heroicon-o-user-group';

    public static function getModelLabel(): ?string
    {
        return __('ui.employee_group_membership');
    }

    public static function getPluralModelLabel(): ?string
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
        // No interactive form; isReadOnly() = true.
        return $form->schema([]);
    }

    public function table(Table $table): Table
    {
        return $table
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
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Üyelik Başlangıcı')
                    ->icon('heroicon-o-calendar-days')
                    ->date()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
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
            ->actions([
                // ViewAction navigates to the underlying Group, not the
                // GroupMember pivot. Passing $record (a GroupMember) directly
                // 404s because GroupResource binds on Group::id.
                Tables\Actions\ViewAction::make()
                    ->url(fn ($record) => GroupResource::getUrl('view', ['record' => $record->group_id])),
            ])
            ->bulkActions([]);
    }

    public function isReadOnly(): bool
    {
        return true;
    }
}
