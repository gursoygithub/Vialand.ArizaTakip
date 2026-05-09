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
        // Only show open/assigned/in_progress in the badge — closed/cancelled are noise.
        $count = Ticket::query()
            ->visibleBy(auth()->user())
            ->whereIn('status', [
                TaskStatusEnum::OPEN->value,
                TaskStatusEnum::ASSIGNED->value,
                TaskStatusEnum::IN_PROGRESS->value,
                TaskStatusEnum::ON_HOLD->value,
            ])
            ->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): string
    {
        $breached = Ticket::query()
            ->visibleBy(auth()->user())
            ->slaBreached()
            ->count();

        return $breached > 0 ? 'danger' : 'primary';
    }

    // --- FORM ---

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                \Filament\Forms\Components\Section::make('Talep Bilgileri')
                    ->icon('heroicon-o-clipboard-document-list')
                    ->columns(2)
                    ->schema([
                        ToggleButtons::make('priority')
                            ->label(__('ui.priority'))
                            ->options(collect(TaskPriorityEnum::cases())
                                ->mapWithKeys(fn ($case) => [$case->value => $case->getLabel()])
                                ->toArray())
                            ->icons(collect(TaskPriorityEnum::cases())
                                ->mapWithKeys(fn ($case) => [$case->value => $case->getIcon()])
                                ->toArray())
                            ->colors(collect(TaskPriorityEnum::cases())
                                ->mapWithKeys(fn ($case) => [$case->value => $case->getColor()])
                                ->toArray())
                            ->inline()
                            ->default(TaskPriorityEnum::Medium->value)
                            ->required()
                            ->live()
                            ->validationMessages(['required' => __('ui.required')])
                            ->columnSpanFull(),

                        // task_date moved into the "Açıklama & Ekler" section so
                        // it sits next to the description in a Grid(2). See the
                        // entry there for placeholder + maxDate + default.

                        // type_id is NOT NULL with no DB default but the Tür field was
                        // removed from the form. Pin it to OPERATION so creates pass
                        // the constraint until/unless the column is dropped.
                        Forms\Components\Hidden::make('type_id')
                            ->default(TaskTypeEnum::OPERATION->value)
                            ->visible(fn ($livewire) => $livewire instanceof \App\Filament\Resources\TicketResource\Pages\CreateTicket),

                        // Edit: read-only display. Status changes happen via the
                        // ViewTicket action buttons (transitions go through
                        // TicketService and write to status history).
                        Forms\Components\ToggleButtons::make('status')
                            ->label(__('ui.status'))
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
                            ->inline()
                            ->disabled()
                            ->dehydrated(false)
                            ->helperText('Durum değişiklikleri talep detay sayfasındaki butonlardan yapılır.')
                            ->hidden(fn ($livewire) => $livewire instanceof \App\Filament\Resources\TicketResource\Pages\CreateTicket)
                            ->columnSpanFull(),
                    ]),

                \Filament\Forms\Components\Section::make('Konum')
                    ->icon('heroicon-o-map-pin')
                    ->columns(2)
                    ->schema([
                        Forms\Components\Select::make('area_id')
                            ->label(__('ui.area'))
                            ->placeholder('Bölge seçiniz')
                            ->prefixIcon('heroicon-o-map')
                            ->options(function (): array {
                                $user       = auth()->user();
                                $companyIds = $user?->scopedCompanyIds() ?? [];
                                $employeeId = $user?->employee?->id;

                                $groupAreaIds = $employeeId
                                    ? \App\Models\Group::whereHas('members',
                                        fn ($q) => $q->where('employee_id', $employeeId))
                                        ->pluck('area_id')
                                        ->toArray()
                                    : [];

                                return Area::query()
                                    ->with('company')
                                    ->where(function ($q) use ($companyIds, $groupAreaIds) {
                                        if (!empty($companyIds)) {
                                            $q->whereIn('company_id', $companyIds);
                                        }
                                        if (!empty($groupAreaIds)) {
                                            $q->orWhereIn('id', $groupAreaIds);
                                        }
                                    })
                                    ->where('status', ActiveStatusEnum::ACTIVE)
                                    ->orderBy('name')
                                    ->get()
                                    ->mapWithKeys(fn ($area) => [
                                        $area->id => $area->name . ($area->company?->name ? " ({$area->company->name})" : ''),
                                    ])
                                    ->toArray();
                            })
                            ->preload()
                            ->searchable()
                            ->required()
                            ->live()
                            ->afterStateUpdated(function (Forms\Set $set) {
                                // Whole downstream chain is invalidated when the area
                                // changes — sub_area, unit (filtered by area's SLA),
                                // group (filtered by area), employee (filtered by group).
                                $set('sub_area_id', null);
                                $set('unit_id', null);
                                $set('group_id', null);
                                $set('employee_id', null);
                            })
                            // Read-only on edit: structural fields must be changed via
                            // the dedicated reassign/transition flows so SLA, status
                            // history, and notifications stay consistent.
                            // ->dehydrated(false) is required in addition to ->disabled()
                            // because Filament 3.x still includes disabled field state in
                            // getState() — a forged Livewire call could write a new value
                            // unless we explicitly exclude these fields from dehydration.
                            ->disabled(fn ($livewire) => $livewire instanceof \App\Filament\Resources\TicketResource\Pages\EditTicket)
                            ->dehydrated(fn ($livewire) => !($livewire instanceof \App\Filament\Resources\TicketResource\Pages\EditTicket))
                            ->validationMessages(['required' => __('ui.required')]),

                        Forms\Components\Select::make('sub_area_id')
                            ->label(__('ui.sub_area'))
                            ->placeholder('Alt bölge seçiniz (opsiyonel)')
                            ->prefixIcon('heroicon-o-map-pin')
                            ->options(fn (Forms\Get $get) => SubArea::where('area_id', $get('area_id'))->pluck('name', 'id'))
                            ->searchable()
                            ->disabled(fn ($livewire) => $livewire instanceof \App\Filament\Resources\TicketResource\Pages\EditTicket)
                            ->dehydrated(fn ($livewire) => !($livewire instanceof \App\Filament\Resources\TicketResource\Pages\EditTicket)),

                        Forms\Components\Select::make('unit_id')
                            ->label(__('ui.unit'))
                            ->placeholder('Önce bölge seçiniz')
                            ->prefixIcon('heroicon-o-building-office')
                            ->options(function (Forms\Get $get) {
                                $areaId = $get('area_id');
                                if (!$areaId) {
                                    return [];
                                }
                                $unitIds = \App\Models\SlaPolicy::where('area_id', $areaId)
                                    ->distinct()
                                    ->pluck('unit_id');
                                if ($unitIds->isEmpty()) {
                                    return [];
                                }
                                return Unit::whereIn('id', $unitIds)
                                    ->orderBy('name')
                                    ->pluck('name', 'id');
                            })
                            ->helperText(function (Forms\Get $get): string {
                                $areaId = $get('area_id');
                                if (!$areaId) {
                                    return 'Önce bölge seçiniz.';
                                }
                                $hasSla = \App\Models\SlaPolicy::where('area_id', $areaId)->exists();
                                return $hasSla
                                    ? 'Sadece bu bölge için SLA tanımlanmış birimler listelenir.'
                                    : 'Bu bölge için henüz SLA tanımlanmamış.';
                            })
                            ->searchable()
                            ->required()
                            ->live()
                            ->afterStateUpdated(function (Forms\Set $set) {
                                $set('group_id', null);
                                $set('employee_id', null);
                            })
                            ->disabled(fn ($livewire) => $livewire instanceof \App\Filament\Resources\TicketResource\Pages\EditTicket)
                            ->dehydrated(fn ($livewire) => !($livewire instanceof \App\Filament\Resources\TicketResource\Pages\EditTicket))
                            ->validationMessages(['required' => __('ui.required')]),

                        \Filament\Forms\Components\Placeholder::make('sla_preview')
                            ->label('Tahmini SLA')
                            ->content(function (callable $get) {
                                $areaId   = $get('area_id');
                                $unitId   = $get('unit_id');
                                $priority = $get('priority');
                                if (!$areaId || !$priority) {
                                    return new \Illuminate\Support\HtmlString(
                                        '<span style="color:#9ca3af;">Bölge ve öncelik seçildiğinde SLA süresi gösterilecektir.</span>'
                                    );
                                }
                                $policy = app(\App\Services\SlaService::class)->resolvePolicy(
                                    (int) $areaId,
                                    $get('sub_area_id') ? (int) $get('sub_area_id') : null,
                                    $unitId ? (int) $unitId : null,
                                    $priority instanceof \BackedEnum ? $priority->value : $priority
                                );
                                if (!$policy) {
                                    return new \Illuminate\Support\HtmlString(
                                        '<span style="color:#dc2626;">Bu kombinasyon için tanımlı SLA yok.</span>'
                                    );
                                }
                                $minutes = (int) $policy->deadline_minutes;
                                $hours = intdiv($minutes, 60);
                                $mins = $minutes % 60;
                                $human = $hours > 0
                                    ? "{$hours} saat" . ($mins > 0 ? " {$mins} dakika" : '')
                                    : "{$mins} dakika";
                                return new \Illuminate\Support\HtmlString(
                                    '<span style="color:#16a34a;font-weight:600;">Bu kombinasyon için SLA: '
                                    . $minutes . ' dakika çözüm süresi (' . $human . ')</span>'
                                );
                            }),
                    ]),

                \Filament\Forms\Components\Section::make('Atama')
                    ->icon('heroicon-o-user-plus')
                    ->columns(2)
                    ->schema([
                        Forms\Components\Select::make('group_id')
                            ->label(__('ui.group'))
                            ->placeholder('Önce bölge seçiniz')
                            ->prefixIcon('heroicon-o-user-group')
                            ->options(function (Forms\Get $get): array {
                                $areaId = $get('area_id');
                                if (!$areaId) {
                                    return [];
                                }
                                $user       = auth()->user();
                                $companyIds = $user?->scopedCompanyIds() ?? [];
                                $employeeId = $user?->employee?->id;

                                $memberGroupIds = $employeeId
                                    ? Group::whereHas('members', fn ($q) => $q->where('employee_id', $employeeId))
                                        ->pluck('id')
                                        ->toArray()
                                    : [];

                                return Group::query()
                                    ->where('area_id', $areaId)
                                    ->where(function ($q) use ($companyIds, $memberGroupIds) {
                                        if (!empty($companyIds)) {
                                            $q->whereIn('company_id', $companyIds);
                                        }
                                        if (!empty($memberGroupIds)) {
                                            $q->orWhereIn('id', $memberGroupIds);
                                        }
                                    })
                                    ->where('status', ActiveStatusEnum::ACTIVE)
                                    ->orderBy('name')
                                    ->pluck('name', 'id')
                                    ->toArray();
                            })
                            ->helperText(fn (Forms\Get $get): ?string =>
                                $get('area_id') ? null : 'Önce bölge seçiniz.')
                            ->searchable()
                            ->live()
                            ->afterStateUpdated(fn (Forms\Set $set) => $set('employee_id', null))
                            ->disabled(fn ($livewire) => $livewire instanceof \App\Filament\Resources\TicketResource\Pages\EditTicket)
                            ->dehydrated(fn ($livewire) => !($livewire instanceof \App\Filament\Resources\TicketResource\Pages\EditTicket)),

                        Forms\Components\Select::make('employee_id')
                            ->label(__('ui.assigned_employee'))
                            ->placeholder('Önce grup seçiniz')
                            ->prefixIcon('heroicon-o-user')
                            ->options(function (Forms\Get $get): array {
                                $groupId = $get('group_id');
                                if (!$groupId) {
                                    // No group selected → no employees. Don't fall back
                                    // to "all employees of company" — assignment must
                                    // resolve through the group → member chain, otherwise
                                    // ticket.assign loses its area/unit context.
                                    return [];
                                }
                                return Employee::query()
                                    ->whereHas('groupMemberships', fn (\Illuminate\Database\Eloquent\Builder $q)
                                        => $q->where('group_id', $groupId))
                                    ->where('status', \App\Enums\ActiveStatusEnum::ACTIVE->value)
                                    ->whereNotNull('email')
                                    ->where('email', '!=', '')
                                    ->when($get('employee_id'), fn ($q, $v) => $q->where('id', '!=', $v))
                                    ->orderBy('name')
                                    ->pluck('name', 'id')
                                    ->toArray();
                            })
                            ->getOptionLabelUsing(fn ($value): string => Employee::find($value)?->name ?? (string) $value)
                            ->helperText(fn (Forms\Get $get): ?string =>
                                $get('group_id') ? null : 'Önce grup seçiniz.')
                            ->searchable()
                            ->disabled(fn ($livewire) => $livewire instanceof \App\Filament\Resources\TicketResource\Pages\EditTicket)
                            ->dehydrated(fn ($livewire) => !($livewire instanceof \App\Filament\Resources\TicketResource\Pages\EditTicket)),
                    ]),

                \Filament\Forms\Components\Section::make('Açıklama & Ekler')
                    ->icon('heroicon-o-document-text')
                    ->schema([
                        Forms\Components\Grid::make(2)->schema([
                            Forms\Components\DatePicker::make('task_date')
                                ->label(__('ui.task_date'))
                                ->placeholder('Arıza tarihini seçiniz')
                                ->maxDate(now()->toDateString())
                                ->default(now()->toDateString())
                                ->required()
                                ->validationMessages(['required' => __('ui.required')]),

                            Forms\Components\Textarea::make('description')
                                ->label(__('ui.description'))
                                ->placeholder('Arıza ile ilgili detayları buraya yazınız. Ne zaman başladı, belirtiler neler, daha önce yaşandı mı?')
                                ->required()
                                ->minLength(10)
                                ->helperText('En az 10 karakter giriniz.')
                                ->rows(4)
                                ->validationMessages(['required' => __('ui.required')]),
                        ]),

                        // Resolution notes don't belong on the form — they're set via
                        // the ViewTicket "Çözüldü" transition action's note field.
                        // Hidden on both create and edit so the form stays focused on
                        // ticket identity, not status-side payloads.
                        Forms\Components\Textarea::make('resolution_notes')
                            ->label(__('ui.resolution_notes'))
                            ->rows(3)
                            ->hidden(fn ($livewire) => $livewire instanceof \App\Filament\Resources\TicketResource\Pages\CreateTicket
                                || $livewire instanceof \App\Filament\Resources\TicketResource\Pages\EditTicket)
                            ->columnSpanFull(),

                        \Filament\Forms\Components\SpatieMediaLibraryFileUpload::make('task_attachments')
                            ->label(__('ui.images'))
                            ->collection('task_attachments')
                            ->multiple()
                            ->maxFiles(5)
                            ->maxSize(10240)
                            ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp', 'application/pdf'])
                            ->helperText('Maksimum 5 dosya, her biri en fazla 10MB.')
                            ->imagePreviewHeight('120')
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

                Tables\Columns\TextColumn::make('area.company.name')
                    ->label(__('ui.company'))
                    ->sortable()
                    ->toggleable()
                    ->searchable(),

                Tables\Columns\TextColumn::make('area.name')
                    ->label(__('ui.area'))
                    ->sortable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('subArea.name')
                    ->label(__('ui.sub_area'))
                    ->sortable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('unit.name')
                    ->label(__('ui.unit'))
                    ->sortable()
                    ->toggleable()
                    ->searchable(),

                Tables\Columns\TextColumn::make('employee.name')
                    ->label(__('ui.assigned_employee'))
                    ->sortable()
                    ->toggleable(),

                // SLA indicator. The Ticket helper carries the full label
                // including terminal-state outcomes (✓ Zamanında / ✗ İhlalle);
                // we just prefix '⚠ '/'✓ ' to in-flight remaining-time strings
                // so the badge color is unambiguous.
                Tables\Columns\TextColumn::make('sla_deadline')
                    ->label(__('ui.sla_indicator'))
                    ->formatStateUsing(function (Ticket $record): string {
                        $label = $record->getSlaStatusLabel();

                        // Prefix only in-flight countdown labels (those ending
                        // in ' kaldı'); terminal/breached/paused labels already
                        // have their own glyph.
                        if (str_ends_with($label, ' kaldı')) {
                            $pct = $record->sla_percent_remaining ?? 100;
                            return ($pct > 50 ? '✓ ' : '⚠ ') . $label;
                        }
                        return $label;
                    })
                    ->color(fn (Ticket $record): string => self::slaColor($record))
                    ->sortable()
                    ->badge(),

                Tables\Columns\TextColumn::make('task_date')
                    ->label(__('ui.task_date'))
                    ->date()
                    ->sortable()
                    ->toggleable(),

                // Oluşturan — visible to group/all/super_admin (a creator-only
                // user already knows who opened their own tickets).
                Tables\Columns\TextColumn::make('createdBy.name')
                    ->label('Oluşturan')
                    ->searchable()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: false)
                    ->visible(fn (): bool => (bool) (auth()->user()?->hasRole('super_admin')
                        || auth()->user()?->hasPermissionTo('ticket.view.group')
                        || auth()->user()?->hasPermissionTo('ticket.view.all'))),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Oluşturma Tarihi')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: false),

                // Audit columns: super_admin only, hidden by default.
                Tables\Columns\TextColumn::make('updatedBy.name')
                    ->label('Güncelleyen')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->visible(fn (): bool => (bool) auth()->user()?->hasRole('super_admin')),

                // Pair Güncelleme Tarihi with Güncelleyen — same super_admin
                // gate, same hidden-by-default toggle. When updated_by is
                // null (e.g. system-touched rows like the SLA breach flip)
                // collapse the timestamp to the placeholder so the column
                // doesn't imply a real edit.
                Tables\Columns\TextColumn::make('updated_at')
                    ->label('Güncelleme Tarihi')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->placeholder('—')
                    ->visible(fn (): bool => (bool) auth()->user()?->hasRole('super_admin'))
                    ->getStateUsing(fn (Ticket $record) => $record->updated_by
                        ? $record->updated_at
                        : null),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('area_id')
                    ->label(__('ui.area'))
                    ->multiple()
                    ->searchable()
                    ->options(function (): array {
                        $user       = auth()->user();
                        $companyIds = $user?->scopedCompanyIds() ?? [];
                        $employeeId = $user?->employee?->id;

                        $groupAreaIds = $employeeId
                            ? Group::whereHas('members', fn ($q) => $q->where('employee_id', $employeeId))
                                ->pluck('area_id')
                                ->toArray()
                            : [];

                        return Area::query()
                            ->where(function ($q) use ($companyIds, $groupAreaIds) {
                                if (!empty($companyIds)) {
                                    $q->whereIn('company_id', $companyIds);
                                }
                                if (!empty($groupAreaIds)) {
                                    $q->orWhereIn('id', $groupAreaIds);
                                }
                            })
                            ->where('status', ActiveStatusEnum::ACTIVE)
                            ->orderBy('name')
                            ->pluck('name', 'id')
                            ->toArray();
                    }),

                Tables\Filters\SelectFilter::make('status')
                    ->label(__('ui.status'))
                    ->multiple()
                    ->options(collect(TaskStatusEnum::cases())
                        ->mapWithKeys(fn ($c) => [$c->value => $c->getLabel()])
                        ->toArray()),

                Tables\Filters\SelectFilter::make('priority')
                    ->label(__('ui.priority'))
                    ->multiple()
                    ->options(collect(TaskPriorityEnum::cases())
                        ->mapWithKeys(fn ($c) => [$c->value => $c->getLabel()])
                        ->toArray()),

                Tables\Filters\SelectFilter::make('employee_id')
                    ->label(__('ui.assigned_employee'))
                    ->relationship('employee', 'name')
                    ->multiple()
                    ->searchable()
                    ->preload(),

                Tables\Filters\Filter::make('created_at')
                    ->form([
                        \Filament\Forms\Components\DatePicker::make('from')->label(__('ui.date_from')),
                        \Filament\Forms\Components\DatePicker::make('to')->label(__('ui.date_to')),
                    ])
                    ->query(fn (Builder $q, array $data) => $q
                        ->when($data['from'] ?? null, fn ($q, $v) => $q->whereDate('created_at', '>=', $v))
                        ->when($data['to'] ?? null, fn ($q, $v) => $q->whereDate('created_at', '<=', $v))
                    ),

                // SLA filter: only column-backed states. The previous
                // 'on_time' and 'warning' options used raw TIMESTAMPDIFF
                // SQL (MySQL-only, broken on SQLite) and ignored the
                // on_hold pause + total_on_hold_minutes credit, leaving
                // them inconsistent with the per-row live label. Use
                // Ticket::getSlaStatusLabel() for live display; this
                // filter is for the persisted breach/no-policy axis only.
                Tables\Filters\SelectFilter::make('sla_status')
                    ->label(__('ui.sla_indicator'))
                    ->options([
                        'breached' => __('ui.sla_breached'),
                        'no_sla'   => 'SLA yok',
                    ])
                    ->query(function (Builder $q, array $data) {
                        return match ($data['value'] ?? null) {
                            'breached' => $q->slaBreached(),
                            'no_sla'   => $q->whereNull('sla_deadline'),
                            default    => $q,
                        };
                    }),
            ])
            ->actions([
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\ViewAction::make(),

                    // Edit/Delete are creator-or-super_admin only AND hidden
                    // on terminal statuses. The TicketPolicy enforces both
                    // rules server-side; ->hidden() prevents the visible
                    // button → click → 403 UX gap on terminal tickets.
                    Tables\Actions\EditAction::make()
                        ->visible(fn (Ticket $record): bool => $record->created_by === auth()->id()
                            || (bool) auth()->user()?->hasRole('super_admin'))
                        ->hidden(fn (Ticket $record) => in_array($record->status, [
                            TaskStatusEnum::RESOLVED,
                            TaskStatusEnum::CLOSED,
                            TaskStatusEnum::CANCELLED,
                        ], true)),

                    Tables\Actions\DeleteAction::make()
                        ->visible(fn (Ticket $record): bool => $record->created_by === auth()->id()
                            || (bool) auth()->user()?->hasRole('super_admin'))
                        ->hidden(fn (Ticket $record) => in_array($record->status, [
                            TaskStatusEnum::RESOLVED,
                            TaskStatusEnum::CLOSED,
                            TaskStatusEnum::CANCELLED,
                        ], true))
                        ->requiresConfirmation()
                        ->modalHeading('Talebi Sil')
                        ->modalDescription('Bu talebi silmek istediğinizden emin misiniz? Bu işlem geri alınamaz.')
                        ->modalSubmitActionLabel('Evet, Sil')
                        ->modalCancelActionLabel('İptal'),
                ])
            ])
            // Bulk actions intentionally removed: bulk_assign bypassed the
            // terminal-state lock (raw $ticket->update on closed tickets),
            // bulk_status was redundant with the per-row transition buttons
            // on the View page, and DeleteBulkAction had no per-record
            // pre-flight feedback for mixed selections. Removing the block
            // also removes the auto-rendered selection checkbox column.
            ->defaultSort('created_at', 'desc')
            ->defaultPaginationPageOption(25)
            ->paginationPageOptions([10, 25, 50, 100])
            ->emptyStateHeading(__('ui.no_tickets_yet'))
            ->emptyStateDescription(__('ui.no_tickets_yet_description'))
            ->emptyStateIcon('heroicon-o-ticket')
            ->emptyStateActions([
                Tables\Actions\Action::make('create_ticket')
                    ->label(__('ui.new_ticket'))
                    ->url(fn () => static::getUrl('create'))
                    ->icon('heroicon-o-plus')
                    ->button(),
            ]);
    }

    public static function getRelations(): array
    {
        return [];
    }

    /**
     * Apply the permission-aware visibility scope. Company and group-membership
     * area filtering is fully handled inside scopeVisibleBy() for all permission
     * branches, so no extra WHERE is needed here.
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
        // on_hold tickets are paused — never red, never warning.
        $statusValue = is_object($record->status) ? $record->status->value : (int) $record->status;
        if ($statusValue === TaskStatusEnum::ON_HOLD->value) {
            return 'gray';
        }

        if (!$record->sla_deadline) {
            return 'gray';
        }

        // Terminal states include RESOLVED — TaskStatusEnum::isClosed()
        // intentionally excludes it (resolved ≠ closed for the lifecycle),
        // but for SLA outcome display they're the same: success/danger by
        // breach result.
        $terminal = in_array($record->status, [
            TaskStatusEnum::RESOLVED,
            TaskStatusEnum::CLOSED,
            TaskStatusEnum::COMPLETED,
            TaskStatusEnum::CANCELLED,
        ], true);
        if ($terminal) {
            return $record->sla_breached ? 'danger' : 'success';
        }

        if (now()->isAfter($record->sla_deadline)) {
            return 'danger';
        }

        $pct = $record->sla_percent_remaining ?? 100;

        return $pct > 50 ? 'success' : 'warning';
    }
}
