<?php

namespace App\Filament\Resources;

use App\Enums\ActiveStatusEnum;
use App\Enums\TaskPriorityEnum;
use App\Filament\Resources\TeamResource\Pages;
use App\Models\Employee;
use App\Models\Team;
use App\Support\CompanyEmail;
use Closure;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

class TeamResource extends Resource
{
    protected static ?string $model = Team::class;

    protected static ?string $navigationIcon = 'heroicon-o-user-group';

    protected static ?int $navigationSort = 20;

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
        return __('ui.request_settings');
    }

    /**
     * "Ad Soyad (Şirket)" — teams mix people from several companies, so the
     * company is shown next to every name.
     */
    public static function employeeLabel(Employee $employee): string
    {
        $company = $employee->company?->name ?? $employee->company_name;
        $label = $company ? "{$employee->name} ({$company})" : $employee->name;

        if ($employee->status === ActiveStatusEnum::INACTIVE) {
            return $label.' ⚠ '.__('ui.employee_inactive_short');
        }

        return CompanyEmail::isCorporate($employee->email) ? $label : $label.' ⚠ '.__('ui.no_company_email');
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

    /**
     * Pickers offer active people; people already on the team stay listed
     * (marked "pasif") so a leaver is visible and can be removed.
     *
     * @param  array<int, int>  $keep
     */
    private static function activeEmployees(Builder $query, array $keep = []): Builder
    {
        return $query->with('company')->where(fn (Builder $q) => $q
            ->where('status', ActiveStatusEnum::ACTIVE)
            ->when($keep !== [], fn (Builder $w) => $w->orWhereIn('employees.id', $keep)));
    }

    /**
     * Team people are reached by e-mail and log in through the account that
     * shares their e-mail, so someone without a company address would get
     * nothing (mail only goes to the domains on Sistem Ayarları).
     */
    private static function requireEmail(): Closure
    {
        return fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
            $names = Employee::query()
                ->whereKey((array) $value)
                ->get(['id', 'name', 'email'])
                ->reject(fn (Employee $employee): bool => CompanyEmail::isCorporate($employee->email))
                ->pluck('name');

            if ($names->isNotEmpty()) {
                $fail(__('ui.team_people_need_email', ['names' => $names->implode(', ')]));
            }
        };
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
                                fn (Builder $query, ?Team $record) => self::activeEmployees($query, array_filter([$record?->supervisor_employee_id])),
                            )
                            ->getOptionLabelFromRecordUsing(fn (Employee $record): string => self::employeeLabel($record))
                            ->searchable(['name', 'email'])
                            ->required()
                            ->rule(self::requireEmail())
                            ->hint(fn (?Team $record): ?string => self::peopleProblem($record?->supervisor ? collect([$record->supervisor]) : collect()))
                            ->hintColor('danger')
                            ->hintIcon(fn (?Team $record): ?string => self::peopleProblem($record?->supervisor ? collect([$record->supervisor]) : collect()) ? 'heroicon-m-exclamation-triangle' : null)
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
                                fn (Builder $query, ?Team $record) => self::activeEmployees($query, $record?->members()->pluck('employees.id')->all() ?? []),
                            )
                            ->getOptionLabelFromRecordUsing(fn (Employee $record): string => self::employeeLabel($record))
                            ->multiple()
                            ->searchable(['name', 'email'])
                            ->rule(self::requireEmail())
                            ->hint(fn (?Team $record): ?string => self::peopleProblem($record?->members ?? collect()))
                            ->hintColor('danger')
                            ->hintIcon(fn (?Team $record): ?string => self::peopleProblem($record?->members ?? collect()) ? 'heroicon-m-exclamation-triangle' : null)
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

                        Forms\Components\TextInput::make('hold_alert_minutes')
                            ->label(__('ui.hold_alert_hours'))
                            ->helperText(__('ui.hold_alert_hours_help'))
                            ->numeric()
                            ->minValue(0)
                            ->step(0.5)
                            ->suffix(__('ui.hours_short'))
                            ->default(8)
                            ->required()
                            ->formatStateUsing(fn ($state): float|int => $state === null ? 8 : $state / 60)
                            ->dehydrateStateUsing(fn ($state): int => (int) round(((float) $state) * 60)),

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
                ->with(['ticketType', 'supervisor.company', 'workCalendar', 'members:id,name,email,status'])
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
                    ->color(fn (Team $record): string => self::peopleWithoutEmail($record) > 0 ? 'danger' : 'primary')
                    ->icon(fn (Team $record): ?string => self::peopleWithoutEmail($record) > 0 ? 'heroicon-m-exclamation-triangle' : null)
                    ->tooltip(fn (Team $record): ?string => self::peopleProblem(self::teamPeople($record)))
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
     * Supervisor plus members without a company e-mail address.
     */
    private static function peopleWithoutEmail(Team $team): int
    {
        return self::teamPeople($team)
            ->filter(fn (Employee $employee): bool => $employee->status === ActiveStatusEnum::INACTIVE || ! CompanyEmail::isCorporate($employee->email))
            ->count();
    }

    /**
     * @return Collection<int, Employee>
     */
    private static function teamPeople(Team $team): Collection
    {
        return collect([$team->supervisor])->filter()->merge($team->members);
    }

    /**
     * One line naming who has left HR ("pasif") and who has no company
     * e-mail, or null when everyone is fine.
     *
     * @param  Collection<int, Employee>  $people
     */
    private static function peopleProblem(Collection $people): ?string
    {
        $inactive = $people->filter(fn (Employee $employee): bool => $employee->status === ActiveStatusEnum::INACTIVE)->pluck('name');
        $noEmail = $people->reject(fn (Employee $employee): bool => $employee->status === ActiveStatusEnum::INACTIVE || CompanyEmail::isCorporate($employee->email))->pluck('name');

        return collect([
            $inactive->isNotEmpty() ? __('ui.team_people_inactive_named', ['names' => $inactive->implode(', ')]) : null,
            $noEmail->isNotEmpty() ? __('ui.team_people_without_email_named', ['names' => $noEmail->implode(', ')]) : null,
        ])->filter()->implode(' · ') ?: null;
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
