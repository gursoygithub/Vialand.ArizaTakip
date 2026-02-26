<?php

namespace App\Filament\Resources\GroupResource\RelationManagers;

use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class MembersRelationManager extends RelationManager
{
    protected static string $relationship = 'members';

    /**
     * @return string|null
     */
    public static function getModelLabel(): ?string
    {
        return __('ui.group_member');
    }

    protected static function getPluralModelLabel(): ?string
    {
        return __('ui.group_members');
    }

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('ui.group_members');
    }

    public function form(Form $form): Form
    {
        return $form
            ->columns(1)
            ->schema([
                Forms\Components\Select::make('employee_id')
                    ->label(__('ui.employee'))
//                    ->options(
//                        \App\Models\Employee::query()
//                            ->where('status', \App\Enums\ActiveStatusEnum::ACTIVE)
//                            ->whereDoesntHave('groupMemberships', function (Builder $query) {
//                                $query->where('group_id', $this->ownerRecord->id)
//                                    ->whereNull('deleted_at');
//                            })
//                            ->pluck('name', 'id')
//                    )
                    ->options(function (?Model $record) {
                        $query = \App\Models\Employee::query()
                            ->where('status', \App\Enums\ActiveStatusEnum::ACTIVE);

                        // Güncelleme modundaysa, mevcut çalışanı da ekle
                        if ($record && $record->employee_id) {
                            $query->orWhere('id', $record->employee_id);
                        }

                        return $query
                            ->whereDoesntHave('groupMemberships', function (Builder $q) use ($record) {
                                $q->where('group_id', $this->ownerRecord->id)
                                    ->when($record, fn($query) => $query->where('employee_id', '!=', $record->employee_id))
                                    ->whereNull('deleted_at');
                            })
                            ->pluck('name', 'id');
                    })
                    ->getOptionLabelFromRecordUsing(fn (Model $record) => $record->employee?->name)
                    ->unique(
                        'group_members',
                        'employee_id',
                        ignoreRecord: true,
                        modifyRuleUsing: function (Builder $query) {
                            $query->whereNull('deleted_at');
                        }
                    )
                    ->searchable()
                    ->required()
                    ->validationMessages([
                        'required' => __('ui.required'),
                        'unique' => __('ui.employee_already_in_group'),
                    ]),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->columns([
                Tables\Columns\TextColumn::make('employee.name')
                    ->label(__('ui.employee'))
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('createdBy.name')
                    ->visible(fn () => auth()->user()->hasRole('super_admin') || auth()->user()->can('view_all_group_members'))
                    ->label(__('ui.created_by'))
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->label(__('ui.created_at'))
                    ->dateTime()
                    ->sortable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('updatedBy.name')
                    ->label(__('ui.updated_by'))
                    ->searchable()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('updated_at')
                    ->label(__('ui.updated_at'))
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
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                //
            ]);
    }

    public function isReadOnly(): bool
    {
        return false;
    }
}
