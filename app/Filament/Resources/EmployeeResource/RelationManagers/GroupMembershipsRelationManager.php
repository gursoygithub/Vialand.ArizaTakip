<?php

namespace App\Filament\Resources\EmployeeResource\RelationManagers;

use App\Enums\ActiveStatusEnum;
use App\Filament\Resources\GroupResource;
use App\Models\Group;
use App\Models\GroupMember;
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

    /**
     * Override the base query to return Group models instead of GroupMember.
     * This lets the table show both member groups AND managed groups in one list.
     */
    protected function getTableQuery(): Builder
    {
        $employee = $this->getOwnerRecord();

        return Group::query()
            ->where(function (Builder $q) use ($employee) {
                $q->whereHas('members', fn (Builder $q) => $q->where('employee_id', $employee->id))
                  ->orWhere('employee_id', $employee->id);
            });
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label(__('ui.group'))
                    ->badge()
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('role_label')
                    ->label('Rol')
                    ->badge()
                    ->getStateUsing(function (Group $record): string {
                        $employee = $this->getOwnerRecord();
                        $isMember = GroupMember::where('group_id', $record->id)
                            ->where('employee_id', $employee->id)
                            ->exists();
                        $isManager = $record->employee_id === $employee->id;

                        if ($isMember && $isManager) {
                            return 'Uye + Yonetici';
                        }
                        if ($isManager) {
                            return 'Yonetici';
                        }

                        return 'Uye';
                    })
                    ->color(function (Group $record): string {
                        $employee = $this->getOwnerRecord();
                        $isMember = GroupMember::where('group_id', $record->id)
                            ->where('employee_id', $employee->id)
                            ->exists();
                        $isManager = $record->employee_id === $employee->id;

                        if ($isMember && $isManager) {
                            return 'success';
                        }
                        if ($isManager) {
                            return 'warning';
                        }

                        return 'info';
                    }),
                Tables\Columns\TextColumn::make('company.name')
                    ->label(__('ui.company'))
                    ->icon('heroicon-o-building-office')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('area.name')
                    ->label(__('ui.area'))
                    ->icon('heroicon-o-map')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('manager.name')
                    ->label(__('ui.group_manager'))
                    ->icon('heroicon-o-user')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->label(__('ui.status'))
                    ->badge()
                    ->alignCenter()
                    ->searchable()
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label(__('ui.status'))
                    ->options(ActiveStatusEnum::class),
            ])
            ->actions([
                Tables\Actions\ViewAction::make()
                    ->url(fn (Group $record) => GroupResource::getUrl('view', ['record' => $record->id])),
            ])
            ->bulkActions([]);
    }

    public function isReadOnly(): bool
    {
        return true;
    }
}
