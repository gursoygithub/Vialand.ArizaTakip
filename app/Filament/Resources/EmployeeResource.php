<?php

namespace App\Filament\Resources;

use App\Filament\Resources\EmployeeResource\Pages;
use App\Filament\Resources\EmployeeResource\RelationManagers;
use App\Models\Employee;
use App\Models\SlaPolicy;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class EmployeeResource extends Resource
{
    protected static ?string $model = Employee::class;

    protected static ?string $navigationIcon = 'heroicon-o-users';

    public static function getModelLabel(): string
    {
        return __('ui.employee');
    }

    public static function getPluralModelLabel(): string
    {
        return __('ui.employees');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('ui.reports');
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                //
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('updated_at', 'desc')
            ->paginated([5, 10, 25, 50])
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label(__('ui.name'))
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('email')
                    ->label(__('ui.email'))
                    ->searchable()
                    ->sortable(),

                // Toplam Görev Sayısı
                Tables\Columns\TextColumn::make('tasks_count')
                    ->label('Toplam Görev')
                    ->counts('tasks') // Doğrudan ilişkiyi sayar
                    ->badge()
                    ->sortable(),

                // Bekleyen (Pending) İşler
                Tables\Columns\TextColumn::make('pending_tasks_count')
                    ->label(__('ui.pending'))
                    ->counts([
                        'tasks as pending_tasks_count' => fn (Builder $query) => $query->where('status', \App\Enums\TaskStatusEnum::PENDING)
                    ])
                    ->badge()
                    ->color('warning')
                    ->icon('heroicon-o-clock')
                    ->sortable(),

                // Tamamlanan (Completed) İşler
                Tables\Columns\TextColumn::make('completed_tasks_count')
                    ->label(__('ui.completed'))
                    ->counts([
                        'tasks as completed_tasks_count' => fn (Builder $query) => $query->where('status', \App\Enums\TaskStatusEnum::COMPLETED)
                    ])
                    ->badge()
                    ->color('success')
                    ->icon('heroicon-o-check-circle')
                    ->sortable(),

                // Kış Bakım (Winter Maintenance) İşler
                Tables\Columns\TextColumn::make('winter_tasks_count')
                    ->label(__('ui.winter_maintenance'))
                    ->counts([
                        'tasks as winter_tasks_count' => fn (Builder $query) => $query->where('status', \App\Enums\TaskStatusEnum::WINTER_MAINTENANCE)
                    ])
                    ->badge()
                    ->color('info')
                    ->icon('heroicon-o-lifebuoy')
                    ->sortable(),

                // Personelin reel başarısı
                Tables\Columns\TextColumn::make('performance_score')
                    ->label('SLA Başarısı')
                    ->suffix('%')
                    ->badge()
                    ->sortable() // En başarılıları sıralamak için
                    ->color(fn ($record) => $record->performance_score >= $record->current_threshold ? 'success' : 'danger'),

                // Birimlerin (SlaPolicy) başarı eşiklerinin ortalaması
                Tables\Columns\TextColumn::make('current_threshold')
                    ->label(__('ui.sla_target_threshold'))
                    ->formatStateUsing(fn($state) => "%" . round($state, 1))
                    ->description('Yönetici Hedefi')
                    ->sortable(),

                // Eşiği aşıp aşmadığını gösteren ikon
                Tables\Columns\IconColumn::make('is_competent')
                    ->label('Yeterlilik')
                    ->state(fn($record) => $record->performance_score >= $record->current_threshold)
                    ->boolean()
                    ->trueIcon('heroicon-o-check-badge')
                    ->falseIcon('heroicon-o-exclamation-triangle')
                    ->trueColor('success')
                    ->falseColor('danger'),
            ])
            ->filters([
                //
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
            ])
            ->bulkActions([
                //
            ]);
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\TasksRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListEmployees::route('/'),
            'create' => Pages\CreateEmployee::route('/create'),
            'edit' => Pages\EditEmployee::route('/{record}/edit'),
            'view' => Pages\ViewEmployee::route('/{record}'),
        ];
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }
}
