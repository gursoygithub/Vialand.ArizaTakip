<?php

namespace App\Filament\Resources;

use App\Enums\ActiveStatusEnum;
use App\Enums\TaskPriorityEnum;
use App\Filament\Resources\TeamResource\Pages;
use App\Models\Employee;
use App\Models\Team;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class TeamResource extends Resource
{
    protected static ?string $model = Team::class;

    protected static ?string $navigationIcon = 'heroicon-o-user-group';

    protected static ?int $navigationSort = -890;

    public static function getModelLabel(): string
    {
        return __('ui.team');
    }

    public static function getPluralModelLabel(): string
    {
        return __('ui.teams');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('ui.panel_management');
    }

    /**
     * "Ad Soyad (Şirket)" — teams mix people from several companies, so the
     * company is shown next to every name.
     */
    public static function employeeLabel(Employee $employee): string
    {
        $company = $employee->company?->name ?? $employee->company_name;

        return $company ? "{$employee->name} ({$company})" : $employee->name;
    }

    /**
     * Default rows for the SLA repeater: one per priority, pre-filled with
     * targets the admin can adjust.
     *
     * @return array<int, array{priority: int, response_minutes: int, resolution_minutes: int, success_threshold: int}>
     */
    public static function defaultSlaRows(): array
    {
        return collect(TaskPriorityEnum::cases())
            ->map(fn (TaskPriorityEnum $priority): array => [
                'priority' => $priority->value,
                'response_minutes' => Team::DEFAULT_SLA_MINUTES[$priority->value][0],
                'resolution_minutes' => Team::DEFAULT_SLA_MINUTES[$priority->value][1],
                'success_threshold' => 80,
            ])
            ->all();
    }

    private static function activeEmployees(Builder $query): Builder
    {
        return $query->with('company')->where('status', ActiveStatusEnum::ACTIVE);
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make(__('ui.team_information'))
                    ->icon('heroicon-o-user-group')
                    ->columns(2)
                    ->schema([
                        Forms\Components\Select::make('ticket_type_id')
                            ->label(__('ui.ticket_type'))
                            ->relationship(
                                'ticketType',
                                'name',
                                fn (Builder $query) => $query->where('is_active', true)->orderBy('sort_order'),
                            )
                            ->required()
                            ->preload()
                            ->native(false)
                            ->validationMessages(['required' => __('ui.required')]),

                        Forms\Components\TextInput::make('name')
                            ->label(__('ui.name'))
                            ->required()
                            ->maxLength(100)
                            ->validationMessages(['required' => __('ui.required')]),

                        Forms\Components\Select::make('supervisor_employee_id')
                            ->label(__('ui.supervisor'))
                            ->relationship(
                                'supervisor',
                                'name',
                                fn (Builder $query) => self::activeEmployees($query),
                            )
                            ->getOptionLabelFromRecordUsing(fn (Employee $record): string => self::employeeLabel($record))
                            ->searchable(['name', 'email'])
                            ->required()
                            ->validationMessages(['required' => __('ui.required')]),

                        Forms\Components\Select::make('work_calendar_id')
                            ->label(__('ui.work_calendar'))
                            ->relationship(
                                'workCalendar',
                                'name',
                                fn (Builder $query) => $query->where('is_active', true),
                            )
                            ->placeholder(__('ui.work_calendar_none'))
                            ->preload()
                            ->native(false),

                        Forms\Components\Select::make('members')
                            ->label(__('ui.team_members'))
                            ->relationship(
                                'members',
                                'name',
                                fn (Builder $query) => self::activeEmployees($query),
                            )
                            ->getOptionLabelFromRecordUsing(fn (Employee $record): string => self::employeeLabel($record))
                            ->multiple()
                            ->searchable(['name', 'email'])
                            ->columnSpanFull(),

                        Forms\Components\Textarea::make('description')
                            ->label(__('ui.description'))
                            ->rows(2)
                            ->maxLength(500)
                            ->columnSpanFull(),

                        Forms\Components\Toggle::make('allow_self_assign')
                            ->label(__('ui.allow_self_assign'))
                            ->default(true)
                            ->inline(false),

                        Forms\Components\Toggle::make('is_active')
                            ->label(__('ui.active'))
                            ->default(true)
                            ->inline(false),
                    ]),

                Forms\Components\Section::make(__('ui.sla_targets'))
                    ->description(__('ui.sla_targets_help'))
                    ->icon('heroicon-o-clock')
                    ->schema([
                        Forms\Components\Repeater::make('slaPolicies')
                            ->hiddenLabel()
                            ->relationship()
                            ->default(self::defaultSlaRows())
                            ->minItems(count(TaskPriorityEnum::cases()))
                            ->maxItems(count(TaskPriorityEnum::cases()))
                            ->addable(false)
                            ->deletable(false)
                            ->reorderable(false)
                            ->columns(4)
                            ->schema([
                                Forms\Components\Select::make('priority')
                                    ->label(__('ui.priority'))
                                    ->options(TaskPriorityEnum::class)
                                    ->disabled()
                                    ->dehydrated()
                                    ->required(),

                                Forms\Components\TextInput::make('response_minutes')
                                    ->label(__('ui.response_minutes'))
                                    ->numeric()
                                    ->integer()
                                    ->minValue(1)
                                    ->required(),

                                Forms\Components\TextInput::make('resolution_minutes')
                                    ->label(__('ui.resolution_minutes'))
                                    ->numeric()
                                    ->integer()
                                    ->minValue(1)
                                    ->gte('response_minutes')
                                    ->validationMessages(['gte' => __('ui.resolution_must_exceed_response')])
                                    ->required(),

                                Forms\Components\TextInput::make('success_threshold')
                                    ->label(__('ui.success_threshold'))
                                    ->numeric()
                                    ->integer()
                                    ->minValue(0)
                                    ->maxValue(100)
                                    ->suffix('%')
                                    ->default(80)
                                    ->required(),
                            ]),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->with(['ticketType', 'supervisor.company', 'workCalendar'])
                ->withCount('members'))
            ->defaultSort('name')
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label(__('ui.name'))
                    ->weight('bold')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('ticketType.name')
                    ->label(__('ui.ticket_type'))
                    ->badge()
                    ->sortable(),

                Tables\Columns\TextColumn::make('supervisor.name')
                    ->label(__('ui.supervisor'))
                    ->description(fn (Team $record): ?string => $record->supervisor?->company?->name)
                    ->searchable(),

                Tables\Columns\TextColumn::make('members_count')
                    ->label(__('ui.members_count'))
                    ->badge()
                    ->sortable(),

                Tables\Columns\TextColumn::make('workCalendar.name')
                    ->label(__('ui.work_calendar'))
                    ->placeholder(__('ui.work_calendar_none')),

                Tables\Columns\IconColumn::make('is_active')
                    ->label(__('ui.active'))
                    ->boolean(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('ticket_type_id')
                    ->label(__('ui.ticket_type'))
                    ->relationship('ticketType', 'name')
                    ->preload(),
                Tables\Filters\TernaryFilter::make('is_active')
                    ->label(__('ui.active')),
            ])
            ->actions([
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\ViewAction::make(),
                    Tables\Actions\EditAction::make(),
                    Tables\Actions\DeleteAction::make(),
                ]),
            ]);
    }

    /**
     * A team that already received tickets is deactivated, never deleted,
     * so its history stays intact for performance reports.
     */
    public static function canDelete(Model $record): bool
    {
        return parent::canDelete($record) && ! $record->tickets()->exists();
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTeams::route('/'),
            'create' => Pages\CreateTeam::route('/create'),
            'view' => Pages\ViewTeam::route('/{record}'),
            'edit' => Pages\EditTeam::route('/{record}/edit'),
        ];
    }
}
