<?php

namespace App\Filament\Resources;

use App\Filament\Resources\HolidayResource\Pages;
use App\Models\Holiday;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class HolidayResource extends Resource
{
    protected static ?string $model = Holiday::class;

    protected static ?string $navigationIcon = 'heroicon-o-sun';

    protected static ?int $navigationSort = -870;

    public static function getModelLabel(): string
    {
        return __('ui.holiday');
    }

    public static function getPluralModelLabel(): string
    {
        return __('ui.holidays');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('ui.panel_management');
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\DatePicker::make('date')
                    ->label(__('ui.date'))
                    ->required()
                    ->unique(ignoreRecord: true)
                    ->native(false),

                Forms\Components\TextInput::make('name')
                    ->label(__('ui.name'))
                    ->required()
                    ->maxLength(100),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('date')
            ->columns([
                Tables\Columns\TextColumn::make('date')
                    ->label(__('ui.date'))
                    ->date()
                    ->sortable(),

                Tables\Columns\TextColumn::make('name')
                    ->label(__('ui.name'))
                    ->searchable(),
            ])
            ->filters([
                Tables\Filters\Filter::make('upcoming')
                    ->label('Yaklaşanlar')
                    ->default()
                    ->query(fn (Builder $query): Builder => $query->whereDate('date', '>=', today())),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ManageHolidays::route('/'),
        ];
    }
}
