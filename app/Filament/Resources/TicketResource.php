<?php

namespace App\Filament\Resources;

use App\Enums\ActiveStatusEnum;
use App\Enums\TaskPriorityEnum;
use App\Enums\TaskStatusEnum;
use App\Enums\TaskTypeEnum;
use App\Filament\Resources\TicketResource\Pages;
use App\Models\Area;
use App\Models\Employee;
use App\Models\Group;
use App\Models\SubArea;
use App\Models\Ticket;
use App\Models\Unit;
use App\Models\User;
use App\Services\TicketService;
use Filament\Forms;
use Filament\Forms\Components\Fieldset;
use Filament\Forms\Components\ToggleButtons;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

class TicketResource extends Resource
{
    protected static ?string $model = Ticket::class;

    protected static ?string $navigationIcon = 'heroicon-o-ticket';

    protected static ?int $navigationSort = -1000;

    public static function getModelLabel(): string
    {
        return __('ui.ticket');
    }

    public static function getPluralModelLabel(): string
    {
        return __('ui.tickets');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('ui.ticket_management');
    }

    public static function getNavigationBadge(): ?string
    {
        return (string) Ticket::query()->count();
    }

    public static function getNavigationBadgeColor(): string
    {
        $breached = Ticket::query()->where('sla_breached', true)->count();
        return $breached > 0 ? 'danger' : 'primary';
    }

    // --- FORM ---

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                \Filament\Forms\Components\Card::make()
                    ->schema([
                        Fieldset::make(__('ui.ticket_information'))
                            ->columns(3)
                            ->schema([
                                Fieldset::make(__('ui.priority_level_and_status'))
                                    ->columns(2)
                                    ->schema([
                                        ToggleButtons::make('priority')
                                            ->hiddenLabel()
                                            ->options(collect(TaskPriorityEnum::cases())
                                                ->mapWithKeys(fn ($c) => [$c->value => $c->getLabel()])
                                                ->toArray())
                                            ->icons(collect(TaskPriorityEnum::cases())
                                                ->mapWithKeys(fn ($c) => [$c->value => $c->getIcon()])
                                                ->toArray())
                                            ->colors(collect(TaskPriorityEnum::cases())
                                                ->mapWithKeys(fn ($c) => [$c->value => $c->getColor()])
                                                ->toArray())
                                            ->inline()
                                            ->default(TaskPriorityEnum::Medium->value)
                                            ->required()
                                            ->live()
                                            ->validationMessages(['required' => __('ui.required')]),

                                        Forms\Components\ToggleButtons::make('status')
                                            ->hiddenLabel(__('ui.status'))
                                            ->options([
                                                TaskStatusEnum::OPEN->value => TaskStatusEnum::OPEN->getLabel(),
                                                TaskStatusEnum::ASSIGNED->value => TaskStatusEnum::ASSIGNED->getLabel(),
                                                TaskStatusEnum::IN_PROGRESS->value => TaskStatusEnum::IN_PROGRESS->getLabel(),
                                                TaskStatusEnum::ON_HOLD->value => TaskStatusEnum::ON_HOLD->getLabel(),
                                                TaskStatusEnum::RESOLVED->value => TaskStatusEnum::RESOLVED->getLabel(),
                                                TaskStatusEnum::CLOSED->value => TaskStatusEnum::CLOSED->getLabel(),
                                                TaskStatusEnum::CANCELLED->value => TaskStatusEnum::CANCELLED->getLabel(),
                                            ])
                                            ->icons([
                                                TaskStatusEnum::OPEN->value => TaskStatusEnum::OPEN->getIcon(),
                                                TaskStatusEnum::ASSIGNED->value => TaskStatusEnum::ASSIGNED->getIcon(),
                                                TaskStatusEnum::IN_PROGRESS->value => TaskStatusEnum::IN_PROGRESS->getIcon(),
                                                TaskStatusEnum::ON_HOLD->value => TaskStatusEnum::ON_HOLD->getIcon(),
                                                TaskStatusEnum::RESOLVED->value => TaskStatusEnum::RESOLVED->getIcon(),
                                                TaskStatusEnum::CLOSED->value => TaskStatusEnum::CLOSED->getIcon(),
                                                TaskStatusEnum::CANCELLED->value => TaskStatusEnum::CANCELLED->getIcon(),
                                            ])
                                            ->colors([
                                                TaskStatusEnum::OPEN->value => TaskStatusEnum::OPEN->getColor(),
                                                TaskStatusEnum::ASSIGNED->value => TaskStatusEnum::ASSIGNED->getColor(),
                                                TaskStatusEnum::IN_PROGRESS->value => TaskStatusEnum::IN_PROGRESS->getColor(),
                                                TaskStatusEnum::ON_HOLD->value => TaskStatusEnum::ON_HOLD->getColor(),
                                                TaskStatusEnum::RESOLVED->value => TaskStatusEnum::RESOLVED->getColor(),
                                                TaskStatusEnum::CLOSED->value => TaskStatusEnum::CLOSED->getColor(),
                                                TaskStatusEnum::CANCELLED->value => TaskStatusEnum::CANCELLED->getColor(),
                                            ])
                                            ->default(TaskStatusEnum::OPEN->value)
                                            ->inline(),
                                    ]),

                                Fieldset::make(__('ui.fault_location_and_date_information'))
                                    ->columns(2)
                                    ->schema([
                                        Forms\Components\Select::make('type_id')
                                            ->label(__('ui.type'))
                                            ->options(collect(TaskTypeEnum::cases())
                                                ->mapWithKeys(fn ($c) => [$c->value => $c->getLabel()])
                                                ->toArray())
                                            ->required()
                                            ->validationMessages(['required' => __('ui.required')]),

                                        Forms\Components\Select::make('area_id')
                                            ->label(__('ui.area'))
                                            ->prefixIcon('heroicon-o-map')
                                            ->options(Area::with('company')
                                                ->where('status', ActiveStatusEnum::ACTIVE)
                                                ->get()
                                                ->mapWithKeys(fn ($a) => [
                                                    $a->id => $a->name . ($a->company?->name ? " ({$a->company->name})" : ''),
                                                ])
                                                ->toArray())
                                            ->preload()
                                            ->searchable()
                                            ->required()
                                            ->live()
                                            ->afterStateUpdated(function (callable $set) {
                                                $set('sub_area_id', null);
                                                $set('group_id', null);
                                                $set('employee_id', null);
                                            })
                                            ->validationMessages(['required' => __('ui.required')]),

                                        Forms\Components\Select::make('sub_area_id')
                                            ->label(__('ui.sub_area'))
                                            ->prefixIcon('heroicon-o-map-pin')
                                            ->options(fn (callable $get) => SubArea::where('area_id', $get('area_id'))->pluck('name', 'id'))
                                            ->searchable()
                                            ->live()
                                            ->afterStateUpdated(fn (callable $set) => $set('employee_id', null)),

                                        Forms\Components\Select::make('unit_id')
                                            ->label(__('ui.unit'))
                                            ->options(Unit::pluck('name', 'id'))
                                            ->searchable()
                                            ->required()
                                            ->live()
                                            ->validationMessages(['required' => __('ui.required')]),

                                        Forms\Components\DatePicker::make('task_date')
                                            ->label(__('ui.task_date'))
                                            ->required()
                                            ->validationMessages(['required' => __('ui.required')]),
                                    ]),

                                Fieldset::make(__('ui.related_person_assignment'))
                                    ->columns(2)
                                    ->schema([
                                        Forms\Components\Select::make('group_id')
                                            ->label(__('ui.group'))
                                            ->options(fn (callable $get) =>
                                                Group::where('area_id', $get('area_id'))
                                                    ->where('status', ActiveStatusEnum::ACTIVE)
                                                    ->pluck('name', 'id'))
                                            ->searchable()
                                            ->live()
                                            ->afterStateUpdated(fn (callable $set) => $set('employee_id', null)),

                                        Forms\Components\Select::make('employee_id')
                                            ->label(__('ui.assigned_employee'))
                                            ->options(fn (callable $get) =>
                                                Employee::whereHas('groupMemberships', fn ($q) =>
                                                    $q->where('group_id', $get('group_id'))
                                                )->pluck('name', 'id'))
                                            ->searchable(),
                                    ]),
                            ]),

                        Fieldset::make(__('ui.description_and_notes'))
                            ->schema([
                                Forms\Components\Textarea::make('description')
                                    ->label(__('ui.description'))
                                    ->rows(4)
                                    ->columnSpanFull(),

                                Forms\Components\Textarea::make('resolution_notes')
                                    ->label(__('ui.resolution_notes'))
                                    ->rows(3)
                                    ->columnSpanFull(),
                            ]),

                        \Filament\Forms\Components\SpatieMediaLibraryFileUpload::make('task_attachments')
                            ->label(__('ui.images'))
                            ->collection('task_attachments')
                            ->image()
                            ->imagePreviewHeight('120')
                            ->multiple(false)
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    // --- TABLE ---

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('ticket_no')
                    ->label(__('ui.ticket_no'))
                    ->searchable()
                    ->sortable()
                    ->weight('bold')
                    ->copyable(),

                Tables\Columns\TextColumn::make('type_id')
                    ->label(__('ui.type'))
                    ->badge()
                    ->sortable(),

                Tables\Columns\TextColumn::make('priority')
                    ->label(__('ui.priority'))
                    ->badge()
                    ->color(fn (TaskPriorityEnum $state) => $state->getColor())
                    ->icon(fn (TaskPriorityEnum $state) => $state->getIcon())
                    ->sortable(),

                Tables\Columns\TextColumn::make('status')
                    ->label(__('ui.status'))
                    ->badge()
                    ->color(fn (TaskStatusEnum $state) => $state->getColor())
                    ->icon(fn (TaskStatusEnum $state) => $state->getIcon())
                    ->sortable(),

                Tables\Columns\TextColumn::make('area.name')
                    ->label(__('ui.area'))
                    ->sortable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('employee.name')
                    ->label(__('ui.assigned_employee'))
                    ->sortable()
                    ->toggleable(),

                // SLA indicator with color coding
                Tables\Columns\TextColumn::make('sla_deadline')
                    ->label(__('ui.sla_indicator'))
                    ->formatStateUsing(function (Ticket $record): string {
                        if (!$record->sla_deadline) {
                            return '—';
                        }

                        if ($record->status?->isClosed()) {
                            return $record->sla_breached ? '✗ ' . __('ui.sla_breached') : '✓ ' . __('ui.on_time');
                        }

                        if (now()->isAfter($record->sla_deadline)) {
                            return '🔴 ' . __('ui.sla_breached');
                        }

                        $remaining = now()->diff($record->sla_deadline);
                        $hours   = (int) $remaining->h + ($remaining->days * 24);
                        $minutes = (int) $remaining->i;

                        return "{$hours}s {$minutes}d " . __('ui.countdown');
                    })
                    ->color(fn (Ticket $record): string => self::slaColor($record))
                    ->sortable()
                    ->badge(),

                Tables\Columns\TextColumn::make('task_date')
                    ->label(__('ui.task_date'))
                    ->date()
                    ->sortable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label(__('ui.created_at'))
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('area_id')
                    ->label(__('ui.area'))
                    ->relationship('area', 'name'),

                Tables\Filters\SelectFilter::make('status')
                    ->label(__('ui.status'))
                    ->options(collect(TaskStatusEnum::cases())
                        ->mapWithKeys(fn ($c) => [$c->value => $c->getLabel()])
                        ->toArray()),

                Tables\Filters\SelectFilter::make('priority')
                    ->label(__('ui.priority'))
                    ->options(collect(TaskPriorityEnum::cases())
                        ->mapWithKeys(fn ($c) => [$c->value => $c->getLabel()])
                        ->toArray()),

                Tables\Filters\Filter::make('sla_breached')
                    ->label(__('ui.sla_breached'))
                    ->query(fn (Builder $q) => $q->where('sla_breached', true)),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getRelations(): array
    {
        return [];
    }

    /**
     * Apply the permission-aware visibility scope so the table only shows
     * tickets the current user is allowed to see (own / group / all).
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->visibleBy(auth()->user());
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListTickets::route('/'),
            'create' => Pages\CreateTicket::route('/create'),
            'edit'   => Pages\EditTicket::route('/{record}/edit'),
            'view'   => Pages\ViewTicket::route('/{record}'),
        ];
    }

    private static function slaColor(Ticket $record): string
    {
        if (!$record->sla_deadline) {
            return 'gray';
        }

        if ($record->sla_breached || now()->isAfter($record->sla_deadline)) {
            return 'danger';
        }

        $pct = $record->sla_percent_remaining ?? 100;

        return $pct > 50 ? 'success' : 'warning';
    }
}
