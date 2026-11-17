<?php

namespace App\Filament\Resources;

use App\Enums\ActiveStatusEnum;
use App\Enums\FormFieldTypeEnum;
use App\Enums\LocationRequirementEnum;
use App\Enums\TaskPriorityEnum;
use App\Enums\TaskStatusEnum;
use App\Filament\Resources\TeamTicketResource\Pages;
use App\Models\Area;
use App\Models\SubArea;
use App\Models\Team;
use App\Models\Ticket;
use App\Models\TicketType;
use App\Services\TicketWorkflowService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;

/**
 * "Talepler": tickets of the type/team model. Legacy tickets stay in
 * TicketResource; the two never show each other's records.
 */
class TeamTicketResource extends Resource
{
    protected static ?string $model = Ticket::class;

    protected static ?string $slug = 'requests';

    protected static ?string $navigationIcon = 'heroicon-o-inbox-stack';

    protected static ?int $navigationSort = 10;

    protected static ?string $recordTitleAttribute = 'ticket_no';

    public static function getModelLabel(): string
    {
        return __('ui.request');
    }

    public static function getPluralModelLabel(): string
    {
        return __('ui.requests');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('ui.requests');
    }

    public static function getNavigationBadge(): ?string
    {
        $employeeId = auth()->user()?->employee?->id;

        if (! $employeeId) {
            return null;
        }

        $count = static::getEloquentQuery()
            ->where('employee_id', $employeeId)
            ->whereIn('status', [TaskStatusEnum::ASSIGNED, TaskStatusEnum::IN_PROGRESS])
            ->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->visibleInTeamWorkflow(auth()->user());
    }

    // ── Create form ─────────────────────────────────────────────────────

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make(__('ui.request_type_step'))
                    ->icon('heroicon-o-rectangle-stack')
                    ->schema([
                        Forms\Components\ToggleButtons::make('ticket_type_id')
                            ->hiddenLabel()
                            ->options(fn (): array => TicketType::active()->ordered()->pluck('name', 'id')->all())
                            ->icons(fn (): array => TicketType::active()->pluck('icon', 'id')->filter()->all())
                            ->colors(fn (): array => TicketType::active()->pluck('color', 'id')->filter()->all())
                            ->inline()
                            ->required()
                            ->live()
                            ->afterStateUpdated(function (Set $set): void {
                                $set('team_id', null);
                                $set('custom_fields', []);
                                $set('area_id', null);
                                $set('sub_area_id', null);
                            })
                            ->validationMessages(['required' => __('ui.request_type_required')]),
                    ]),

                Forms\Components\Section::make(__('ui.request_team_step'))
                    ->icon('heroicon-o-user-group')
                    ->columns(2)
                    ->visible(fn (Get $get): bool => filled($get('ticket_type_id')))
                    ->schema([
                        Forms\Components\Select::make('team_id')
                            ->label(__('ui.team'))
                            ->options(fn (Get $get): array => Team::query()
                                ->active()
                                ->where('ticket_type_id', $get('ticket_type_id'))
                                ->orderBy('name')
                                ->pluck('name', 'id')
                                ->all())
                            ->helperText(fn (Get $get): ?string => self::teamHint($get('team_id')))
                            ->required()
                            ->live()
                            ->native(false)
                            ->validationMessages(['required' => __('ui.request_team_required')]),

                        Forms\Components\ToggleButtons::make('priority')
                            ->label(__('ui.priority'))
                            ->options(TaskPriorityEnum::class)
                            ->default(TaskPriorityEnum::Medium->value)
                            ->inline()
                            ->required(),

                        Forms\Components\Placeholder::make('sla_preview')
                            ->label(__('ui.sla_targets'))
                            ->visible(fn (Get $get): bool => filled($get('team_id')))
                            ->content(fn (Get $get): ?HtmlString => self::slaPreview($get('team_id'), $get('priority')))
                            ->columnSpanFull(),
                    ]),

                Forms\Components\Section::make(__('ui.location'))
                    ->icon('heroicon-o-map-pin')
                    ->columns(2)
                    ->visible(fn (Get $get): bool => self::locationRequirement($get('ticket_type_id')) !== LocationRequirementEnum::Hidden
                        && filled($get('ticket_type_id')))
                    ->schema([
                        Forms\Components\Select::make('area_id')
                            ->label(__('ui.area'))
                            ->options(fn (): array => Area::query()
                                ->where('status', ActiveStatusEnum::ACTIVE)
                                ->orderBy('name')
                                ->pluck('name', 'id')
                                ->all())
                            ->searchable()
                            ->live()
                            ->afterStateUpdated(fn (Set $set) => $set('sub_area_id', null))
                            ->required(fn (Get $get): bool => self::locationRequirement($get('ticket_type_id')) === LocationRequirementEnum::Required),

                        Forms\Components\Select::make('sub_area_id')
                            ->label(__('ui.sub_area'))
                            ->options(fn (Get $get): array => $get('area_id')
                                ? SubArea::where('area_id', $get('area_id'))->orderBy('name')->pluck('name', 'id')->all()
                                : [])
                            ->searchable()
                            ->required(fn (Get $get): bool => self::locationRequirement($get('ticket_type_id')) === LocationRequirementEnum::Required),
                    ]),

                Forms\Components\Section::make(__('ui.request_details_step'))
                    ->icon('heroicon-o-document-text')
                    ->visible(fn (Get $get): bool => filled($get('ticket_type_id')))
                    ->schema([
                        Forms\Components\Group::make()
                            ->statePath('custom_fields')
                            ->columns(2)
                            ->schema(fn (Get $get): array => self::customFieldComponents($get('ticket_type_id'))),

                        Forms\Components\Textarea::make('description')
                            ->label(__('ui.description'))
                            ->required()
                            ->minLength(10)
                            ->rows(4),

                        Forms\Components\SpatieMediaLibraryFileUpload::make('task_attachments')
                            ->label(__('ui.images'))
                            ->collection('task_attachments')
                            ->multiple()
                            ->maxFiles(5)
                            ->maxSize(10240)
                            ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp', 'application/pdf']),
                    ]),
            ]);
    }

    /**
     * Inputs for the selected type's own fields (stored in custom_fields).
     *
     * @return array<int, Forms\Components\Component>
     */
    public static function customFieldComponents(mixed $ticketTypeId): array
    {
        if (blank($ticketTypeId)) {
            return [];
        }

        $fields = TicketType::find($ticketTypeId)?->form_fields ?? [];

        return collect($fields)
            ->map(function (array $field): ?Forms\Components\Component {
                $type = FormFieldTypeEnum::tryFrom($field['type'] ?? '');

                $component = match ($type) {
                    FormFieldTypeEnum::Text => Forms\Components\TextInput::make($field['key'])->maxLength(255),
                    FormFieldTypeEnum::Textarea => Forms\Components\Textarea::make($field['key'])->rows(3)->columnSpanFull(),
                    FormFieldTypeEnum::Number => Forms\Components\TextInput::make($field['key'])->numeric(),
                    FormFieldTypeEnum::Select => Forms\Components\Select::make($field['key'])
                        ->options(collect($field['options'] ?? [])->mapWithKeys(fn ($option): array => [$option => $option])->all())
                        ->native(false),
                    FormFieldTypeEnum::Date => Forms\Components\DatePicker::make($field['key']),
                    FormFieldTypeEnum::Checkbox => Forms\Components\Toggle::make($field['key'])->inline(false),
                    default => null,
                };

                return $component
                    ?->label($field['label'])
                    ->required((bool) ($field['required'] ?? false) && $type !== FormFieldTypeEnum::Checkbox)
                    ->helperText($field['help'] ?? null);
            })
            ->filter()
            ->values()
            ->all();
    }

    public static function locationRequirement(mixed $ticketTypeId): LocationRequirementEnum
    {
        return TicketType::find($ticketTypeId)?->location_requirement ?? LocationRequirementEnum::Optional;
    }

    private static function teamHint(mixed $teamId): ?string
    {
        $team = $teamId ? Team::with('supervisor.company')->find($teamId) : null;

        return $team ? __('ui.request_team_hint', ['name' => TeamResource::employeeLabel($team->supervisor)]) : null;
    }

    private static function slaPreview(mixed $teamId, mixed $priority): ?HtmlString
    {
        $team = $teamId ? Team::with('slaPolicies', 'workCalendar')->find($teamId) : null;
        $priority = $priority instanceof TaskPriorityEnum ? $priority : TaskPriorityEnum::tryFrom((int) $priority);
        $policy = $team && $priority ? $team->slaPolicyFor($priority) : null;

        if (! $policy) {
            return null;
        }

        return new HtmlString(e(__('ui.request_sla_preview', [
            'response' => self::humanMinutes($policy->response_minutes),
            'resolution' => self::humanMinutes($policy->resolution_minutes),
            'calendar' => $team->workCalendar?->name ?? __('ui.around_the_clock'),
        ])));
    }

    public static function humanMinutes(int $minutes): string
    {
        $days = intdiv($minutes, 1440);
        $hours = intdiv($minutes % 1440, 60);
        $rest = $minutes % 60;

        return collect([
            $days ? $days.' '.__('ui.days') : null,
            $hours ? $hours.' '.__('ui.hours_short') : null,
            $rest ? $rest.' '.__('ui.minutes_short') : null,
        ])->filter()->implode(' ') ?: '0 '.__('ui.minutes_short');
    }

    // ── List ────────────────────────────────────────────────────────────

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with([
                'ticketType', 'team', 'employee', 'createdBy', 'currentCycle.workCalendar',
            ]))
            ->defaultSort('created_at', 'desc')
            ->poll('60s')
            ->columns([
                Tables\Columns\TextColumn::make('ticket_no')
                    ->label(__('ui.ticket_no'))
                    ->weight('bold')
                    ->searchable()
                    ->copyable(),

                Tables\Columns\TextColumn::make('ticketType.name')
                    ->label(__('ui.ticket_type'))
                    ->badge()
                    ->color(fn (Ticket $record): string => $record->ticketType?->color ?: 'gray')
                    ->icon(fn (Ticket $record): ?string => $record->ticketType?->icon),

                Tables\Columns\TextColumn::make('team.name')
                    ->label(__('ui.team'))
                    ->toggleable(),

                Tables\Columns\TextColumn::make('priority')
                    ->label(__('ui.priority'))
                    ->badge()
                    ->sortable(),

                Tables\Columns\TextColumn::make('status')
                    ->label(__('ui.status'))
                    ->badge()
                    ->sortable(),

                Tables\Columns\TextColumn::make('employee.name')
                    ->label(__('ui.assigned_employee'))
                    ->placeholder(__('ui.request_in_pool'))
                    ->toggleable(),

                Tables\Columns\TextColumn::make('createdBy.name')
                    ->label(__('ui.requester'))
                    ->toggleable(),

                Tables\Columns\TextColumn::make('sla_state')
                    ->label(__('ui.sla_indicator'))
                    ->state(fn (Ticket $record): string => self::slaLabel($record))
                    ->color(fn (Ticket $record): string => self::slaColor($record))
                    ->icon('heroicon-o-clock')
                    ->badge(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label(__('ui.created_at'))
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('ticket_type_id')
                    ->label(__('ui.ticket_type'))
                    ->relationship('ticketType', 'name')
                    ->multiple()
                    ->preload(),
                Tables\Filters\SelectFilter::make('team_id')
                    ->label(__('ui.team'))
                    ->relationship('team', 'name')
                    ->multiple()
                    ->preload(),
                Tables\Filters\SelectFilter::make('status')
                    ->label(__('ui.status'))
                    ->multiple()
                    ->options(collect(Pages\ListTeamTickets::STATUSES)
                        ->mapWithKeys(fn (TaskStatusEnum $status): array => [$status->value => $status->getLabel()])
                        ->all()),
                Tables\Filters\SelectFilter::make('priority')
                    ->label(__('ui.priority'))
                    ->multiple()
                    ->options(TaskPriorityEnum::class),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
            ])
            ->emptyStateHeading(__('ui.request_empty_heading'))
            ->emptyStateDescription(__('ui.request_empty_description'))
            ->emptyStateIcon('heroicon-o-inbox');
    }

    /**
     * Short SLA state for the list: remaining time, paused, or the outcome.
     */
    public static function slaLabel(Ticket $ticket): string
    {
        $cycle = $ticket->currentCycle;

        if (! $cycle) {
            return '—';
        }

        if ($cycle->outcome !== null) {
            return $cycle->outcome->getLabel();
        }

        if ($cycle->isPaused()) {
            return '⏸ '.__('ui.sla_paused');
        }

        if ($cycle->resolution_breached || $cycle->resolution_due_at->isPast()) {
            return __('ui.sla_breached');
        }

        return __('ui.sla_due', ['time' => $cycle->resolution_due_at->diffForHumans(['syntax' => \Carbon\CarbonInterface::DIFF_ABSOLUTE, 'parts' => 2, 'short' => true])]);
    }

    public static function slaColor(Ticket $ticket): string
    {
        $cycle = $ticket->currentCycle;

        if (! $cycle) {
            return 'gray';
        }

        if ($cycle->outcome !== null) {
            return $cycle->outcome->countsForPerformance()
                ? ($cycle->resolution_breached ? 'danger' : 'success')
                : 'gray';
        }

        if ($cycle->isPaused()) {
            return 'gray';
        }

        if ($cycle->resolution_breached || $cycle->resolution_due_at->isPast()) {
            return 'danger';
        }

        return app(TicketWorkflowService::class)->elapsedRatio($cycle) >= TicketWorkflowService::WARNING_RATIO
            ? 'warning'
            : 'success';
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTeamTickets::route('/'),
            'create' => Pages\CreateTeamTicket::route('/create'),
            'view' => Pages\ViewTeamTicket::route('/{record}'),
        ];
    }
}
