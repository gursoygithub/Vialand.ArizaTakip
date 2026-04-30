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
                TaskStatusEnum::PENDING->value,
            ])
            ->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): string
    {
        $breached = Ticket::query()
            ->visibleBy(auth()->user())
            ->where('sla_breached', true)
            ->whereIn('status', [
                TaskStatusEnum::OPEN->value,
                TaskStatusEnum::ASSIGNED->value,
                TaskStatusEnum::IN_PROGRESS->value,
                TaskStatusEnum::PENDING->value,
            ])
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

                        Forms\Components\DatePicker::make('task_date')
                            ->label(__('ui.task_date'))
                            ->placeholder('Arıza tarihini seçiniz')
                            ->default(now())
                            ->required()
                            ->validationMessages(['required' => __('ui.required')]),

                        // Hidden default on create so new tickets always start as OPEN.
                        // The DB default is PENDING (legacy) — explicit injection here
                        // overrides that for the ticket lifecycle.
                        Forms\Components\Hidden::make('status')
                            ->default(TaskStatusEnum::OPEN->value)
                            ->visible(fn ($livewire) => $livewire instanceof \App\Filament\Resources\TicketResource\Pages\CreateTicket),

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
                                $companyIds = auth()->user()?->scopedCompanyIds() ?? [];
                                return Area::query()
                                    ->with('company')
                                    ->when(!empty($companyIds), fn (\Illuminate\Database\Eloquent\Builder $query)
                                        => $query->whereIn('company_id', $companyIds))
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
                            ->validationMessages(['required' => __('ui.required')]),

                        Forms\Components\Select::make('sub_area_id')
                            ->label(__('ui.sub_area'))
                            ->placeholder('Alt bölge seçiniz (opsiyonel)')
                            ->prefixIcon('heroicon-o-map-pin')
                            ->options(fn (Forms\Get $get) => SubArea::where('area_id', $get('area_id'))->pluck('name', 'id'))
                            ->searchable(),

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
                                    $priority
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
                                $companyIds = auth()->user()?->scopedCompanyIds() ?? [];
                                return Group::query()
                                    ->where('area_id', $areaId)
                                    ->when(!empty($companyIds), fn (\Illuminate\Database\Eloquent\Builder $query)
                                        => $query->whereIn('company_id', $companyIds))
                                    ->where('status', ActiveStatusEnum::ACTIVE)
                                    ->orderBy('name')
                                    ->pluck('name', 'id')
                                    ->toArray();
                            })
                            ->helperText(fn (Forms\Get $get): ?string =>
                                $get('area_id') ? null : 'Önce bölge seçiniz.')
                            ->searchable()
                            ->live()
                            ->afterStateUpdated(fn (Forms\Set $set) => $set('employee_id', null)),

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
                                    ->orderBy('name')
                                    ->pluck('name', 'id')
                                    ->toArray();
                            })
                            ->helperText(fn (Forms\Get $get): ?string =>
                                $get('group_id') ? null : 'Önce grup seçiniz.')
                            ->searchable(),
                    ]),

                \Filament\Forms\Components\Section::make('Açıklama & Ekler')
                    ->icon('heroicon-o-document-text')
                    ->schema([
                        Forms\Components\Textarea::make('description')
                            ->label(__('ui.description'))
                            ->placeholder('Arıza ile ilgili detayları buraya yazınız...')
                            ->rows(4)
                            ->columnSpanFull(),

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

                Tables\Columns\TextColumn::make('subArea.name')
                    ->label(__('ui.sub_area'))
                    ->sortable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('employee.name')
                    ->label(__('ui.assigned_employee'))
                    ->sortable()
                    ->toggleable(),

                // SLA indicator with color coding.
                // Priority order (spec):
                //   1. status = on_hold        → gray   "Duraklatıldı"
                //   2. no sla_deadline         → gray   "SLA Yok"
                //   3. closed/completed        → green/red based on outcome
                //   4. sla_breached = true     → red    "SLA İhlal Edildi"
                //   5. <50% remaining          → yellow "Uyarı"
                //   6. otherwise               → green  "Zamanında"
                Tables\Columns\TextColumn::make('sla_deadline')
                    ->label(__('ui.sla_indicator'))
                    ->formatStateUsing(function (Ticket $record): string {
                        // 1. on_hold — clock paused, NEVER calculate or render countdown/breach.
                        //    Compare by value so this works whether status is the cast enum or a raw int.
                        $statusValue = is_object($record->status) ? $record->status->value : (int) $record->status;
                        if ($statusValue === TaskStatusEnum::ON_HOLD->value) {
                            return '⏸ Duraklatıldı';
                        }

                        // 2. no policy
                        if (!$record->sla_deadline) {
                            return '— SLA Yok';
                        }

                        // 3. closed: show outcome
                        if ($record->status?->isClosed()) {
                            return $record->sla_breached
                                ? '✗ ' . __('ui.sla_breached')
                                : '✓ ' . __('ui.on_time');
                        }

                        // 4. breached
                        if ($record->sla_breached || now()->isAfter($record->sla_deadline)) {
                            $diff = now()->diff($record->sla_deadline);
                            $h    = (int) $diff->h + ($diff->days * 24);
                            $m    = (int) $diff->i;
                            return "🔴 {$h}s {$m}d gecikmiş";
                        }

                        // 5. countdown — yellow if <50% remaining, else green
                        $remaining = now()->diff($record->sla_deadline);
                        $hours     = (int) $remaining->h + ($remaining->days * 24);
                        $minutes   = (int) $remaining->i;

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
                    ->relationship('area', 'name')
                    ->searchable()
                    ->preload(),

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

                Tables\Filters\SelectFilter::make('sla_status')
                    ->label(__('ui.sla_indicator'))
                    ->options([
                        'on_time'  => __('ui.on_time'),
                        'warning'  => __('ui.warning_threshold'),
                        'breached' => __('ui.sla_breached'),
                        'no_sla'   => 'SLA yok',
                    ])
                    ->query(function (Builder $q, array $data) {
                        return match ($data['value'] ?? null) {
                            'breached' => $q->where('sla_breached', true),
                            'no_sla'   => $q->whereNull('sla_deadline'),
                            'warning'  => $q->whereNotNull('sla_deadline')
                                ->where('sla_breached', false)
                                ->whereRaw('TIMESTAMPDIFF(SECOND, created_at, NOW()) / GREATEST(TIMESTAMPDIFF(SECOND, created_at, sla_deadline), 1) >= 0.5'),
                            'on_time'  => $q->whereNotNull('sla_deadline')
                                ->where('sla_breached', false)
                                ->whereRaw('TIMESTAMPDIFF(SECOND, created_at, NOW()) / GREATEST(TIMESTAMPDIFF(SECOND, created_at, sla_deadline), 1) < 0.5'),
                            default    => $q,
                        };
                    }),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    // Bulk assign — supervisor/admin only
                    Tables\Actions\BulkAction::make('bulk_assign')
                        ->label('Toplu Ata')
                        ->icon('heroicon-o-user-plus')
                        ->color('warning')
                        ->visible(fn () => auth()->user()?->can('ticket.assign'))
                        ->form([
                            \Filament\Forms\Components\Select::make('employee_id')
                                ->label(__('ui.assigned_employee'))
                                ->relationship('employee', 'name')
                                ->searchable()
                                ->required(),
                        ])
                        ->action(function (\Illuminate\Database\Eloquent\Collection $records, array $data) {
                            $service = app(\App\Services\TicketService::class);
                            foreach ($records as $ticket) {
                                $ticket->update(['employee_id' => $data['employee_id']]);
                                if ($ticket->status === TaskStatusEnum::OPEN) {
                                    try {
                                        $service->transition($ticket, TaskStatusEnum::ASSIGNED, auth()->user(), 'Bulk assign');
                                    } catch (\Throwable $e) {
                                        // skip invalid transitions silently
                                    }
                                }
                            }
                            \Filament\Notifications\Notification::make()
                                ->title($records->count() . ' bilet atandı')
                                ->success()->send();
                        }),

                    // Bulk status change — supervisor/admin only
                    Tables\Actions\BulkAction::make('bulk_status')
                        ->label('Toplu Durum Değiştir')
                        ->icon('heroicon-o-arrow-path')
                        ->color('primary')
                        ->visible(fn () => auth()->user()?->can('ticket.assign'))
                        ->form([
                            \Filament\Forms\Components\Select::make('status')
                                ->label(__('ui.to_status'))
                                ->options([
                                    TaskStatusEnum::IN_PROGRESS->value => TaskStatusEnum::IN_PROGRESS->getLabel(),
                                    TaskStatusEnum::ON_HOLD->value     => TaskStatusEnum::ON_HOLD->getLabel(),
                                    TaskStatusEnum::CANCELLED->value   => TaskStatusEnum::CANCELLED->getLabel(),
                                ])
                                ->required(),
                            \Filament\Forms\Components\Textarea::make('note')->label(__('ui.note'))->rows(2),
                        ])
                        ->action(function (\Illuminate\Database\Eloquent\Collection $records, array $data) {
                            $service = app(\App\Services\TicketService::class);
                            $to = TaskStatusEnum::from((int) $data['status']);
                            $ok = 0; $skip = 0;
                            foreach ($records as $ticket) {
                                try {
                                    $service->transition($ticket, $to, auth()->user(), $data['note'] ?? null);
                                    $ok++;
                                } catch (\Throwable $e) {
                                    $skip++;
                                }
                            }
                            \Filament\Notifications\Notification::make()
                                ->title("Güncellendi: {$ok}, atlandı: {$skip}")
                                ->success()->send();
                        }),

                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
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
     * Apply the permission-aware visibility scope so the table only shows
     * tickets the current user is allowed to see (own / group / all), then
     * additionally restrict by the user's accessible companies. The company
     * filter is a no-op for admins (scopedCompanyIds returns []).
     */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->visibleBy(auth()->user());

        $companyIds = auth()->user()?->scopedCompanyIds() ?? [];
        if (!empty($companyIds)) {
            $query->whereHas('area', fn (Builder $q) => $q->whereIn('company_id', $companyIds));
        }

        return $query;
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

        if ($record->status?->isClosed()) {
            return $record->sla_breached ? 'danger' : 'success';
        }

        if ($record->sla_breached || now()->isAfter($record->sla_deadline)) {
            return 'danger';
        }

        $pct = $record->sla_percent_remaining ?? 100;

        return $pct > 50 ? 'success' : 'warning';
    }
}
