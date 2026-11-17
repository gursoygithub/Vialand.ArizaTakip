<?php

namespace App\Filament\Resources;

use App\Filament\Resources\WorkCalendarResource\Pages;
use App\Models\WorkCalendar;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class WorkCalendarResource extends Resource
{
    protected static ?string $model = WorkCalendar::class;

    protected static ?string $navigationIcon = 'heroicon-o-calendar-days';

    protected static ?int $navigationSort = -880;

    /**
     * ISO weekday => translation key.
     *
     * @var array<int, string>
     */
    public const WEEKDAYS = [
        1 => 'ui.monday',
        2 => 'ui.tuesday',
        3 => 'ui.wednesday',
        4 => 'ui.thursday',
        5 => 'ui.friday',
        6 => 'ui.saturday',
        7 => 'ui.sunday',
    ];

    public static function getModelLabel(): string
    {
        return __('ui.work_calendar');
    }

    public static function getPluralModelLabel(): string
    {
        return __('ui.work_calendars');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('ui.panel_management');
    }

    /**
     * One row per weekday: label + opening + closing time. Leaving both
     * times empty marks the day closed.
     *
     * @return array<int, Forms\Components\Component>
     */
    private static function weekdayRows(): array
    {
        return collect(self::WEEKDAYS)
            ->map(fn (string $labelKey, int $day): Forms\Components\Component => Forms\Components\Grid::make(3)
                ->schema([
                    Forms\Components\Placeholder::make("day_{$day}")
                        ->hiddenLabel()
                        ->content(__($labelKey)),

                    Forms\Components\TimePicker::make("working_hours.{$day}.start")
                        ->label(__('ui.open_time'))
                        ->hiddenLabel()
                        ->placeholder(__('ui.day_closed'))
                        ->seconds(false)
                        ->requiredWith("working_hours.{$day}.end"),

                    Forms\Components\TimePicker::make("working_hours.{$day}.end")
                        ->label(__('ui.close_time'))
                        ->hiddenLabel()
                        ->placeholder(__('ui.day_closed'))
                        ->seconds(false)
                        ->requiredWith("working_hours.{$day}.start")
                        ->after("working_hours.{$day}.start")
                        ->validationMessages(['after' => __('ui.end_after_start')]),
                ]))
            ->values()
            ->all();
    }

    public static function form(Form $form): Form
    {
        return $form
            ->columns(2)
            ->schema([
                Forms\Components\TextInput::make('name')
                    ->label(__('ui.name'))
                    ->required()
                    ->maxLength(100)
                    ->unique(ignoreRecord: true)
                    ->columnSpanFull(),

                Forms\Components\Toggle::make('is_24_7')
                    ->label(__('ui.around_the_clock'))
                    ->live()
                    ->default(false),

                Forms\Components\Toggle::make('observes_holidays')
                    ->label(__('ui.observes_holidays'))
                    ->default(true),

                Forms\Components\Fieldset::make(__('ui.working_hours'))
                    ->hidden(fn (Get $get): bool => (bool) $get('is_24_7'))
                    ->columns(1)
                    ->schema([
                        Forms\Components\Placeholder::make('working_hours_help')
                            ->hiddenLabel()
                            ->content(__('ui.working_hours_help')),
                        ...self::weekdayRows(),
                    ]),

                Forms\Components\Toggle::make('is_active')
                    ->label(__('ui.active'))
                    ->default(true),
            ]);
    }

    /**
     * Compact "Pzt-Cum 08:00-18:00" style summary for the table.
     */
    public static function summarize(WorkCalendar $calendar): string
    {
        if ($calendar->is_24_7) {
            return __('ui.around_the_clock');
        }

        $open = collect(self::WEEKDAYS)
            ->keys()
            ->map(fn (int $day): ?string => ($hours = $calendar->hoursFor($day))
                ? __(self::WEEKDAYS[$day].'_short')." {$hours['start']}-{$hours['end']}"
                : null)
            ->filter();

        return $open->isEmpty() ? __('ui.day_closed') : $open->implode(', ');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->withCount('teams'))
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label(__('ui.name'))
                    ->weight('bold')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('working_hours')
                    ->label(__('ui.working_hours'))
                    ->state(fn (WorkCalendar $record): string => self::summarize($record))
                    ->wrap(),

                Tables\Columns\IconColumn::make('observes_holidays')
                    ->label(__('ui.holidays'))
                    ->boolean(),

                Tables\Columns\TextColumn::make('teams_count')
                    ->label(__('ui.teams_count'))
                    ->badge(),

                Tables\Columns\IconColumn::make('is_active')
                    ->label(__('ui.active'))
                    ->boolean(),
            ])
            ->actions([
                Tables\Actions\EditAction::make()
                    ->mutateFormDataUsing(fn (array $data): array => self::normalize($data)),
                Tables\Actions\DeleteAction::make(),
            ]);
    }

    /**
     * Drop closed days so working_hours only stores open ones; a 24/7
     * calendar stores no hours at all.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function normalize(array $data): array
    {
        if (! empty($data['is_24_7'])) {
            $data['working_hours'] = null;

            return $data;
        }

        $data['working_hours'] = collect($data['working_hours'] ?? [])
            ->filter(fn ($hours): bool => is_array($hours) && filled($hours['start'] ?? null) && filled($hours['end'] ?? null))
            ->map(fn (array $hours): array => [
                'start' => substr((string) $hours['start'], 0, 5),
                'end' => substr((string) $hours['end'], 0, 5),
            ])
            ->all();

        return $data;
    }

    public static function canDelete(Model $record): bool
    {
        return parent::canDelete($record) && ! $record->teams()->exists();
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ManageWorkCalendars::route('/'),
        ];
    }
}
