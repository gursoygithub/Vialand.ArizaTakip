<?php

namespace App\Filament\Pages;

use App\Enums\ActiveStatusEnum;
use App\Enums\TaskPriorityEnum;
use App\Models\Area;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\SlaPolicy;
use App\Models\SubArea;
use App\Models\Unit;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms;
use Filament\Forms\Components\Actions as FormActions;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\View as ViewField;
use Filament\Forms\Components\Wizard;
use Filament\Forms\Components\Wizard\Step;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Enums\MaxWidth;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

/**
 * 6-step setup wizard scoped to a single company. Steps save independently
 * via Filament action modals — the wizard's "submit" simply redirects.
 *
 * Schema quirk: sla_policies.sub_area_id is NOT NULL (see database/CLAUDE.md),
 * so wizard-level SLA rows pin to the area's first sub_area. SlaService falls
 * back at area+unit+priority level for tickets created against other sub_areas.
 */
class CompanySetupWizard extends Page implements HasForms, HasActions
{
    use InteractsWithForms;
    use InteractsWithActions;

    protected static ?string $navigationIcon = 'heroicon-o-building-office';

    protected static ?int $navigationSort = 5;

    protected static string $view = 'filament.pages.company-setup-wizard';

    protected ?string $maxContentWidth = null; // full width

    public ?array $data = [];

    /**
     * Soft-warning steps the user has explicitly clicked through.
     * Cleared whenever companyId changes (different company → different state).
     * Step keys: 'areas', 'sla', 'groups', 'users'.
     */
    public array $softConfirmedSteps = [];

    public static function getNavigationLabel(): string
    {
        return 'Şirket Kurulumu';
    }

    public function getTitle(): string
    {
        return 'Şirket Kurulumu';
    }

    public static function getNavigationGroup(): ?string
    {
        return __('ui.system');
    }

    public static function shouldRegisterNavigation(): bool
    {
        return (bool) auth()->user()?->can('manage_settings');
    }

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->can('manage_settings');
    }

    public function mount(): void
    {
        $this->form->fill([
            'companyId' => null,
        ]);
    }

    public function getMaxContentWidth(): MaxWidth|string|null
    {
        return MaxWidth::Full;
    }

    public function form(Form $form): Form
    {
        return $form
            ->statePath('data')
            ->schema([
                // Tamamla rides in the wizard's submit slot via an Htmlable
                // (the wizard's submitAction signature is `string|Htmlable|null`).
                // We can't pass an Action object directly because the wizard's
                // blade calls requiresConfirmation() handling at render-time
                // and silently drops the modal — that path only wires up if
                // the action lives as a page action and is mounted via
                // mountAction(). So the slot gets a tiny button that calls
                // mountAction('submit'), which in turn opens the page-level
                // submitAction() defined below (with the confirmation modal).
                // Bonus: the wizard already wraps its submit slot in
                // x-bind:class="{ hidden: ! isLastStep(), block: isLastStep() }"
                // so the button is auto-hidden until the last step — no
                // server-side step counter needed.
                Wizard::make([
                    $this->stepCompany(),
                    $this->stepAreas(),
                    $this->stepSla(),
                    $this->stepGroups(),
                    $this->stepUsers(),
                    $this->stepSummary(),
                ])
                    ->persistStepInQueryString()
                    ->submitAction(new \Illuminate\Support\HtmlString(<<<'HTML'
                        <button
                            type="button"
                            wire:click="mountAction('submit')"
                            class="fi-btn fi-btn-color-primary fi-btn-size-md inline-flex items-center justify-center gap-1.5 rounded-lg px-4 py-2 text-sm font-semibold outline-none transition duration-75 focus-visible:ring-2 bg-primary-600 text-white hover:bg-primary-500 focus-visible:ring-primary-500/50"
                        >
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="h-5 w-5">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                            </svg>
                            Tamamla
                        </button>
                    HTML)),
            ]);
    }

    /**
     * "Tamamla" page action — registered by HasActions because the method
     * name ends with "Action". Mountable from the blade via
     * wire:click="mountAction('submit')". Confirmation modal mounts through
     * Filament's standard page-action lifecycle, where requiresConfirmation()
     * actually triggers a modal (it's silently dropped if the action object
     * is passed to Wizard::submitAction()).
     */
    public function submitAction(): Action
    {
        return Action::make('submit')
            ->label('Tamamla')
            ->icon('heroicon-o-check-circle')
            ->color('primary')
            ->requiresConfirmation()
            ->modalHeading('Kurulumu tamamlamak istediğinizden emin misiniz?')
            ->modalDescription(function (): string {
                $companyId = $this->data['companyId'] ?? null;
                if (!$companyId) {
                    return 'Kurulum tamamlanacak.';
                }

                $company   = Company::find($companyId);
                $areaIds   = Area::where('company_id', $companyId)->pluck('id');
                $unitIds   = Unit::pluck('id');
                $totalCombos   = $areaIds->count() * $unitIds->count() * 4;
                $definedCombos = SlaPolicy::whereIn('area_id', $areaIds)
                    ->whereIn('unit_id', $unitIds)
                    ->count();
                $missingCombos = max(0, $totalCombos - $definedCombos);

                $lines = [
                    "Şirket: " . ($company?->name ?? '—'),
                    "Bölgeler: " . $areaIds->count(),
                    "SLA: {$definedCombos}/{$totalCombos} kombinasyon tanımlı",
                ];

                if ($missingCombos > 0) {
                    $lines[] = "⚠️ {$missingCombos} eksik SLA kombinasyonu var.";
                    $lines[] = "Eksik kombinasyonlar için ticket'lar SLA'sız açılacaktır.";
                }

                return implode("\n", $lines);
            })
            ->modalSubmitActionLabel('Evet, kurulumu tamamla')
            ->modalCancelActionLabel('Geri dön')
            ->action(fn () => $this->finish());
    }

    protected function finish(): void
    {
        $this->redirect(filament()->getHomeUrl());
    }

    /**
     * Two-click soft-gate: if the step hasn't been acknowledged yet, send a
     * persistent confirmation notification with "Evet, devam et" / "Hayır,
     * tamamlayayım" buttons and Halt the wizard. After the user clicks
     * "Evet" (acknowledgeSoftWarning), they re-click "İleri" and this gate
     * becomes a no-op for that step.
     */
    protected function softGate(string $stepKey, string $title, string $body): void
    {
        if (in_array($stepKey, $this->softConfirmedSteps, true)) {
            return; // already acknowledged this run
        }

        Notification::make()
            ->title($title)
            ->body($body)
            ->warning()
            ->persistent()
            ->actions([
                \Filament\Notifications\Actions\Action::make('confirm_' . $stepKey)
                    ->label('Evet, devam et')
                    ->color('warning')
                    ->dispatch('acknowledgeSoftWarning', ['step' => $stepKey]),
                \Filament\Notifications\Actions\Action::make('cancel_' . $stepKey)
                    ->label('Hayır, tamamlayayım')
                    ->color('gray')
                    ->close(),
            ])
            ->send();

        throw new \Filament\Support\Exceptions\Halt;
    }

    /**
     * Marks a soft-validated step as acknowledged. The user then re-clicks
     * "İleri" — the second click sees the step in $softConfirmedSteps and
     * the gate is bypassed.
     */
    #[\Livewire\Attributes\On('acknowledgeSoftWarning')]
    public function acknowledgeSoftWarning(string $step): void
    {
        if (!in_array($step, $this->softConfirmedSteps, true)) {
            $this->softConfirmedSteps[] = $step;
        }

        Notification::make()
            ->title('Onaylandı')
            ->body('Devam etmek için "İleri" butonuna tekrar tıklayın.')
            ->success()
            ->send();
    }

    public function resetWizard(): void
    {
        $this->form->fill(['companyId' => null]);
        $this->dispatch('reset-wizard');

        Notification::make()
            ->title('Sihirbaz sıfırlandı')
            ->info()
            ->send();
    }

    // ─────────────────────────────────────────────────────────────────────
    // STEP 1 — Şirket Seç
    // ─────────────────────────────────────────────────────────────────────

    protected function stepCompany(): Step
    {
        return Step::make('Şirket Seç')
            ->icon('heroicon-o-building-office-2')
            ->description('Kurulum yapılacak şirketi seçin')
            ->schema([
                Select::make('companyId')
                    ->label('Şirket')
                    ->placeholder('Şirket seçiniz')
                    ->options(function (): array {
                        return Company::query()
                            ->orderBy('name')
                            ->pluck('name', 'id')
                            ->toArray();
                    })
                    ->getOptionLabelFromRecordUsing(fn (Company $c) => $c->name)
                    ->searchable()
                    ->live()
                    ->required()
                    ->validationMessages([
                        'required' => 'Devam etmek için bir şirket seçiniz.',
                    ])
                    // Different company = different state. Drop any soft
                    // confirmations the user already clicked through, otherwise
                    // a "no SLAs" warning skipped on company A would silently
                    // skip the same warning on company B. Also reset the SLA
                    // scope so a sub_area from the previous company doesn't
                    // leak into the matrix (which would silently filter it
                    // down to zero rows).
                    ->afterStateUpdated(function (Forms\Set $set) {
                        $this->softConfirmedSteps = [];
                        $set('slaScopeSubAreaId', null);
                    }),

                ViewField::make('summary_card')
                    ->view('filament.pages.company-setup-wizard.partials.summary-card')
                    ->visible(fn (Forms\Get $get) => filled($get('companyId')))
                    ->viewData(fn (Forms\Get $get) => [
                        'stats' => $this->companyStats((int) $get('companyId')),
                    ]),
            ]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // STEP 2 — Bölge & Lokasyonlar
    // ─────────────────────────────────────────────────────────────────────

    protected function stepAreas(): Step
    {
        return Step::make('Bölge & Lokasyonlar')
            ->icon('heroicon-o-map')
            ->description('Bölgeleri ve lokasyonları yönetin')
            ->afterValidation(function () {
                $companyId = (int) ($this->data['companyId'] ?? 0);
                if (!$companyId) {
                    return;
                }

                // HARD: at least one area is required. Without an area the
                // SLA grid (step 3) and group form (step 4) have nothing to
                // bind to, and tickets can't be created against this company
                // at all — so this is a hard block, no soft override.
                $areas = Area::where('company_id', $companyId)
                    ->withCount('subAreas')
                    ->orderBy('name')
                    ->get();

                if ($areas->isEmpty()) {
                    Notification::make()
                        ->title('Bölge tanımlı değil')
                        ->body('Devam etmek için en az bir bölge tanımlamanız gerekmektedir.')
                        ->danger()
                        ->send();
                    throw new \Filament\Support\Exceptions\Halt;
                }

                // HARD: every area must have at least one sub_area. Tickets
                // require a sub_area to be selected, so an empty area is a
                // dead end for ticket creation — block here so the user
                // can't reach step 3+ in a half-configured state.
                $emptyAreas = $areas->filter(fn ($area) => (int) $area->sub_areas_count === 0)
                    ->pluck('name')
                    ->all();

                if (!empty($emptyAreas)) {
                    $list = implode(', ', $emptyAreas);
                    Notification::make()
                        ->title('Eksik lokasyonlar')
                        ->body('Tüm bölgelerin en az bir lokasyonu olmalıdır. Lütfen eksik lokasyonları ekleyiniz: ' . $list)
                        ->danger()
                        ->persistent()
                        ->send();
                    throw new \Filament\Support\Exceptions\Halt;
                }
            })
            ->schema([
                Placeholder::make('areas_empty_company')
                    ->hiddenLabel()
                    ->content('Devam etmek için Adım 1\'de bir şirket seçin.')
                    ->visible(fn (Forms\Get $get) => blank($get('companyId'))),

                Section::make('Bölgeler')
                    ->visible(fn (Forms\Get $get) => filled($get('companyId')))
                    ->headerActions([
                        FormActions\Action::make('addArea')
                            ->label('Yeni Bölge Ekle')
                            ->icon('heroicon-o-plus')
                            ->modalHeading('Yeni Bölge')
                            ->form([
                                TextInput::make('name')->label('İsim')->required(),
                                Select::make('status')
                                    ->label('Durum')
                                    ->options([
                                        ActiveStatusEnum::ACTIVE->value => 'Aktif',
                                        ActiveStatusEnum::INACTIVE->value => 'Pasif',
                                    ])
                                    ->default(ActiveStatusEnum::ACTIVE->value)
                                    ->required(),
                            ])
                            ->action(function (array $data, Forms\Get $get) {
                                $companyId = (int) $get('companyId');
                                if (!$companyId) {
                                    return;
                                }
                                Area::create([
                                    'name'       => $data['name'],
                                    'company_id' => $companyId,
                                    'status'     => (int) $data['status'],
                                    'created_by' => auth()->id(),
                                ]);
                                Notification::make()->title('Bölge eklendi')->success()->send();
                            }),
                    ])
                    ->schema([
                        ViewField::make('areas_list')
                            ->hiddenLabel()
                            ->view('filament.pages.company-setup-wizard.partials.areas-list')
                            ->viewData(fn (Forms\Get $get) => [
                                'areas' => $this->areasFor((int) ($get('companyId') ?? 0)),
                            ]),
                    ]),
            ]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // STEP 3 — SLA Politikaları
    // ─────────────────────────────────────────────────────────────────────

    protected function stepSla(): Step
    {
        return Step::make('SLA Politikaları')
            ->icon('heroicon-o-clock')
            ->description('Bölge × Birim × Öncelik için SLA tanımlayın')
            ->afterValidation(function () {
                $companyId = (int) ($this->data['companyId'] ?? 0);
                if (!$companyId) {
                    return;
                }

                // HARD: areas must exist before SLA can be defined. Step 2
                // already enforces this, but if the user navigates back to
                // step 1, picks a different (areas-less) company, and tries
                // to advance into step 3 by URL or back-button, this is the
                // backstop.
                $areas = Area::where('company_id', $companyId)
                    ->orderBy('name')
                    ->get(['id', 'name']);

                if ($areas->isEmpty()) {
                    Notification::make()
                        ->title('Bölge gerekli')
                        ->body('Bölge tanımlanmadan SLA politikası oluşturulamaz. Lütfen önce bölge ekleyiniz.')
                        ->danger()
                        ->send();
                    throw new \Filament\Support\Exceptions\Halt;
                }

                // Per-area SLA presence check. An area with ZERO policies is
                // unusable for tickets — SlaService L3 fallback (area+priority)
                // would miss, leaving only L4 (priority only) which depends on
                // global rows that may not exist. That's a hard block.
                //
                // For partial coverage we count DISTINCT (unit, priority)
                // tuples covered by any row in the area — a default row
                // (sub_area_id IS NULL) and a location-specific override row
                // both count as covering the same tuple at L2/L1, so we don't
                // over-count override stacks. Pre-Reform code counted raw rows
                // here, which double-counted overrides and silently masked
                // partial coverage when a popular tuple had many overrides.
                $units = Unit::pluck('id');
                $unitCount = $units->count();
                $expectedPerArea = $unitCount * 4;

                $areasWithNoSla = [];
                $areasWithPartialSla = [];

                foreach ($areas as $area) {
                    $distinctTuples = SlaPolicy::where('area_id', $area->id)
                        ->whereIn('unit_id', $units)
                        ->select('unit_id', 'priority')
                        ->distinct()
                        ->get()
                        ->count();

                    if ($distinctTuples === 0) {
                        $areasWithNoSla[] = $area->name;
                    } elseif ($distinctTuples < $expectedPerArea) {
                        $areasWithPartialSla[] = $area->name;
                    }
                }

                // HARD: any area with zero SLA → block.
                if (!empty($areasWithNoSla)) {
                    $list = implode(', ', $areasWithNoSla);
                    Notification::make()
                        ->title('SLA tanımlı olmayan bölgeler')
                        ->body("Bazı bölgeler için hiç SLA politikası tanımlanmamış: {$list}. En az bir birim için SLA tanımlamanız gerekmektedir.")
                        ->danger()
                        ->persistent()
                        ->send();
                    throw new \Filament\Support\Exceptions\Halt;
                }

                // SOFT: areas with partial coverage. Surface the count so the
                // user sees how big the gap is before clicking through.
                if (!empty($areasWithPartialSla)) {
                    $stats = $this->companyStats($companyId);
                    $missing = (int) ($stats['missing_sla_combos'] ?? 0);

                    $this->softGate(
                        'sla',
                        'Eksik SLA Kombinasyonları',
                        "$missing kombinasyon eksik. Eksik kombinasyonlar için SLA bulunamazsa ticket'lar SLA'sız açılacaktır. Devam etmek istiyor musunuz?",
                    );
                }
            })
            ->schema([
                Placeholder::make('sla_empty_company')
                    ->hiddenLabel()
                    ->content('Devam etmek için Adım 1\'de bir şirket seçin.')
                    ->visible(fn (Forms\Get $get) => blank($get('companyId'))),

                Section::make('SLA Matrisi')
                    ->description('Lokasyon kapsamını seçin, ardından hücrelere tıklayarak öncelik bazlı SLA dakikalarını düzenleyin. "Varsayılan" kapsam tüm lokasyonlar için geçerli olur; spesifik lokasyon seçilirse o lokasyon için override yazılır.')
                    ->visible(fn (Forms\Get $get) => filled($get('companyId')))
                    ->schema([
                        // Scope selector: null sub_area = "default for the area"
                        // (matches L2 in SlaService::resolvePolicy). Selecting a
                        // specific sub_area filters the matrix to that area and
                        // edits L1 (location-specific override) rows.
                        Select::make('slaScopeSubAreaId')
                            ->label('Lokasyon Kapsamı')
                            ->placeholder('Varsayılan (tüm lokasyonlar)')
                            ->helperText('Boş bırakırsanız bölge geneli (sub_area_id = NULL) kayıt yazılır. Bir lokasyon seçilirse o lokasyon için override yazılır ve matris yalnızca o lokasyonun bölgesini gösterir.')
                            ->live()
                            ->options(function (Forms\Get $get) {
                                $companyId = (int) ($get('companyId') ?? 0);
                                if (!$companyId) {
                                    return [];
                                }

                                $areaIds = Area::where('company_id', $companyId)->pluck('id');

                                return SubArea::whereIn('area_id', $areaIds)
                                    ->with('area:id,name')
                                    ->orderBy('area_id')
                                    ->orderBy('name')
                                    ->get()
                                    ->mapWithKeys(fn (SubArea $sa) => [
                                        $sa->id => ($sa->area?->name ?? '—') . ' → ' . $sa->name,
                                    ])
                                    ->all();
                            }),

                        ViewField::make('sla_grid')
                            ->hiddenLabel()
                            ->view('filament.pages.company-setup-wizard.partials.sla-grid')
                            ->viewData(fn (Forms\Get $get) => $this->slaGridData(
                                (int) ($get('companyId') ?? 0),
                                $get('slaScopeSubAreaId') ? (int) $get('slaScopeSubAreaId') : null,
                            )),
                    ]),
            ]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // STEP 4 — Gruplar & Üyeler
    // ─────────────────────────────────────────────────────────────────────

    protected function stepGroups(): Step
    {
        return Step::make('Gruplar & Üyeler')
            ->icon('heroicon-o-user-group')
            ->description('Ekipleri ve üyeleri yönetin')
            ->afterValidation(function () {
                $companyId = (int) ($this->data['companyId'] ?? 0);
                if (!$companyId) {
                    return;
                }

                // Three hard blocks, evaluated in dependency order so the
                // user fixes the most fundamental issue first. No soft path —
                // a group without supervisor or members can't actually carry
                // tickets, so there's no useful "proceed anyway" semantic.

                // HARD 1: at least one group exists for this company.
                $groups = Group::where('company_id', $companyId)
                    ->withCount('members')
                    ->orderBy('name')
                    ->get();

                if ($groups->isEmpty()) {
                    Notification::make()
                        ->title('Grup tanımlı değil')
                        ->body('Devam etmek için en az bir grup tanımlamanız gerekmektedir.')
                        ->danger()
                        ->send();
                    throw new \Filament\Support\Exceptions\Halt;
                }

                // HARD 2: every group must have a supervisor (amir).
                // groups.employee_id is NOT NULL at the schema level, but
                // legacy/fixture rows may still appear with 0 — guard with
                // an explicit emptiness check.
                $noSupervisor = $groups
                    ->filter(fn ($g) => empty($g->employee_id))
                    ->pluck('name')
                    ->all();

                if (!empty($noSupervisor)) {
                    Notification::make()
                        ->title('Amiri olmayan gruplar')
                        ->body('Bazı grupların amiri bulunmuyor: ' . implode(', ', $noSupervisor) . '. Her grubun bir amiri olmalıdır.')
                        ->danger()
                        ->persistent()
                        ->send();
                    throw new \Filament\Support\Exceptions\Halt;
                }

                // HARD 3: every group must have at least one member.
                $emptyGroups = $groups
                    ->filter(fn ($g) => (int) $g->members_count === 0)
                    ->pluck('name')
                    ->all();

                if (!empty($emptyGroups)) {
                    Notification::make()
                        ->title('Üyesi olmayan gruplar')
                        ->body('Bazı grupların üyesi bulunmuyor: ' . implode(', ', $emptyGroups) . '. Her grubun en az bir üyesi olmalıdır.')
                        ->danger()
                        ->persistent()
                        ->send();
                    throw new \Filament\Support\Exceptions\Halt;
                }
            })
            ->schema([
                Placeholder::make('groups_empty_company')
                    ->hiddenLabel()
                    ->content('Devam etmek için Adım 1\'de bir şirket seçin.')
                    ->visible(fn (Forms\Get $get) => blank($get('companyId'))),

                Section::make('Gruplar')
                    ->visible(fn (Forms\Get $get) => filled($get('companyId')))
                    ->headerActions([
                        FormActions\Action::make('addGroup')
                            ->label('Yeni Grup Ekle')
                            ->icon('heroicon-o-plus')
                            ->modalHeading('Yeni Grup')
                            ->form(fn (Forms\Get $get) => [
                                TextInput::make('name')
                                    ->label('Grup Adı')
                                    ->required()
                                    ->validationMessages(['required' => 'Grup adı zorunludur.']),

                                Select::make('area_id')
                                    ->label('Bölge')
                                    ->helperText('Sadece SLA politikası tanımlanmış bölgeler listelenmektedir.')
                                    ->options(fn () => Area::query()
                                        ->where('company_id', (int) $get('companyId'))
                                        ->where('status', ActiveStatusEnum::ACTIVE->value)
                                        ->whereHas('slaPolicies')
                                        ->orderBy('name')
                                        ->pluck('name', 'id'))
                                    ->required()
                                    ->searchable()
                                    ->live()
                                    ->afterStateUpdated(fn (Forms\Set $set) => $set('unit_id', null))
                                    ->validationMessages(['required' => 'Bölge alanı zorunludur.']),

                                Select::make('unit_id')
                                    ->label('Birim')
                                    ->options(function (Forms\Get $get) {
                                        $areaId = $get('area_id');

                                        if (!$areaId) {
                                            return Unit::orderBy('name')->pluck('name', 'id');
                                        }

                                        $unitIds = SlaPolicy::where('area_id', $areaId)
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

                                        $hasSla = SlaPolicy::where('area_id', $areaId)->exists();

                                        if (!$hasSla) {
                                            return 'Bu bölge için henüz SLA tanımlanmamış. SLA adımına dönünüz.';
                                        }

                                        return 'Sadece SLA tanımlı birimler listelenmektedir.';
                                    })
                                    ->required()
                                    ->searchable()
                                    ->validationMessages(['required' => 'Birim alanı zorunludur.']),

                                Select::make('employee_id')
                                    ->label('Amir')
                                    ->options(fn () => Employee::query()
                                        ->where('company_id', (int) $get('companyId'))
                                        ->where('status', ActiveStatusEnum::ACTIVE->value)
                                        ->orderBy('name')
                                        ->limit(500)
                                        ->pluck('name', 'id'))
                                    ->required()
                                    ->searchable()
                                    ->validationMessages(['required' => 'Amir alanı zorunludur.']),
                            ])
                            ->action(function (array $data, Forms\Get $get) {
                                Group::create([
                                    'name'        => $data['name'],
                                    'company_id'  => (int) $get('companyId'),
                                    'area_id'     => (int) $data['area_id'],
                                    'unit_id'     => (int) $data['unit_id'],
                                    'employee_id' => (int) $data['employee_id'],
                                    'status'      => ActiveStatusEnum::ACTIVE->value,
                                    'created_by'  => auth()->id(),
                                ]);
                                Notification::make()->title('Grup oluşturuldu')->success()->send();
                            }),
                    ])
                    ->schema([
                        ViewField::make('groups_list')
                            ->hiddenLabel()
                            ->view('filament.pages.company-setup-wizard.partials.groups-list')
                            ->viewData(fn (Forms\Get $get) => [
                                'groups' => $this->groupsFor((int) ($get('companyId') ?? 0)),
                            ]),
                    ]),
            ]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // STEP 5 — Kullanıcı Rolleri
    // ─────────────────────────────────────────────────────────────────────

    protected function stepUsers(): Step
    {
        return Step::make('Kullanıcı Rolleri')
            ->icon('heroicon-o-user-circle')
            ->description('Şirket kullanıcılarının rollerini ayarlayın')
            ->afterValidation(function () {
                $companyId = (int) ($this->data['companyId'] ?? 0);
                if (!$companyId) {
                    return;
                }

                $defaultUsers = $this->defaultUsersFor($companyId);
                $count = count($defaultUsers);
                if ($count === 0) {
                    return;
                }

                // SOFT: still some users on default role.
                $this->softGate(
                    'users',
                    'Default rolünde kullanıcılar var',
                    "$count kullanıcı hâlâ default rolünde. Eksik tanımlamalarla devam etmek istediğinizden emin misiniz?",
                );
            })
            ->schema([
                Placeholder::make('users_empty_company')
                    ->hiddenLabel()
                    ->content('Devam etmek için Adım 1\'de bir şirket seçin.')
                    ->visible(fn (Forms\Get $get) => blank($get('companyId'))),

                Section::make('Rol Atanmamış Kullanıcılar')
                    ->description('Sadece "default" rolüne sahip, henüz yetki atanmamış kullanıcılar.')
                    ->visible(fn (Forms\Get $get) => filled($get('companyId')))
                    ->schema([
                        ViewField::make('default_users')
                            ->hiddenLabel()
                            ->view('filament.pages.company-setup-wizard.partials.users-default')
                            ->viewData(fn (Forms\Get $get) => [
                                'users' => $this->defaultUsersFor((int) ($get('companyId') ?? 0)),
                                'roles' => $this->assignableRoles(),
                            ]),
                    ]),

                Section::make('Mevcut Rol Atamaları')
                    ->description('Bu şirkete bağlı kullanıcılar ve mevcut rolleri.')
                    ->visible(fn (Forms\Get $get) => filled($get('companyId')))
                    ->schema([
                        ViewField::make('roled_users')
                            ->hiddenLabel()
                            ->view('filament.pages.company-setup-wizard.partials.users-roled')
                            ->viewData(fn (Forms\Get $get) => [
                                'users' => $this->roledUsersFor((int) ($get('companyId') ?? 0)),
                                'roles' => $this->assignableRoles(),
                            ]),
                    ]),
            ]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // STEP 6 — Özet
    // ─────────────────────────────────────────────────────────────────────

    protected function stepSummary(): Step
    {
        return Step::make('Özet')
            ->icon('heroicon-o-check-circle')
            ->description('Kurulum özeti')
            ->schema([
                ViewField::make('summary')
                    ->hiddenLabel()
                    ->view('filament.pages.company-setup-wizard.partials.summary')
                    ->viewData(fn (Forms\Get $get) => [
                        'companyId' => (int) ($get('companyId') ?? 0),
                        'stats'     => $this->summaryStats((int) ($get('companyId') ?? 0)),
                    ]),
            ]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // ACTIONS — invoked from blade partials via wire:click
    // ─────────────────────────────────────────────────────────────────────

    public function editAreaAction(): Action
    {
        return Action::make('editArea')
            ->label('Düzenle')
            ->icon('heroicon-o-pencil')
            ->modalHeading('Bölge Düzenle')
            ->fillForm(function (array $arguments): array {
                $area = Area::find($arguments['area_id'] ?? null);
                return $area ? ['name' => $area->name, 'status' => $area->status] : [];
            })
            ->form([
                TextInput::make('name')->label('İsim')->required(),
                Select::make('status')
                    ->label('Durum')
                    ->options([
                        ActiveStatusEnum::ACTIVE->value => 'Aktif',
                        ActiveStatusEnum::INACTIVE->value => 'Pasif',
                    ])
                    ->required(),
            ])
            ->action(function (array $arguments, array $data) {
                $area = Area::find($arguments['area_id'] ?? null);
                if (!$area) {
                    return;
                }
                $area->update([
                    'name'   => $data['name'],
                    'status' => (int) $data['status'],
                ]);
                Notification::make()->title('Bölge güncellendi')->success()->send();
            });
    }

    public function deleteAreaAction(): Action
    {
        return Action::make('deleteArea')
            ->label('Sil')
            ->icon('heroicon-o-trash')
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading('Bölgeyi sil?')
            ->modalDescription('Bu bölge ve altındaki lokasyonlar silinecektir.')
            ->action(function (array $arguments) {
                $area = Area::find($arguments['area_id'] ?? null);
                if (!$area) {
                    return;
                }
                $area->subAreas()->delete();
                $area->delete();
                Notification::make()->title('Bölge silindi')->success()->send();
            });
    }

    public function addSubAreaAction(): Action
    {
        return Action::make('addSubArea')
            ->label('Lokasyon Ekle')
            ->icon('heroicon-o-plus')
            ->modalHeading('Yeni Lokasyon')
            ->form([
                TextInput::make('name')->label('Lokasyon Adı')->required(),
            ])
            ->action(function (array $arguments, array $data) {
                $areaId = (int) ($arguments['area_id'] ?? 0);
                if (!$areaId) {
                    return;
                }
                SubArea::create([
                    'area_id'    => $areaId,
                    'name'       => $data['name'],
                    'created_by' => auth()->id(),
                ]);
                Notification::make()->title('Lokasyon eklendi')->success()->send();
            });
    }

    public function editSubAreaAction(): Action
    {
        return Action::make('editSubArea')
            ->label('Yeniden adlandır')
            ->icon('heroicon-o-pencil')
            ->modalHeading('Lokasyon Adını Değiştir')
            ->fillForm(function (array $arguments): array {
                $sub = SubArea::find($arguments['sub_area_id'] ?? null);
                return $sub ? ['name' => $sub->name] : [];
            })
            ->form([
                TextInput::make('name')->label('Lokasyon Adı')->required(),
            ])
            ->action(function (array $arguments, array $data) {
                $sub = SubArea::find($arguments['sub_area_id'] ?? null);
                if (!$sub) {
                    return;
                }
                $sub->update(['name' => $data['name']]);
                Notification::make()->title('Lokasyon güncellendi')->success()->send();
            });
    }

    public function deleteSubAreaAction(): Action
    {
        return Action::make('deleteSubArea')
            ->label('Sil')
            ->icon('heroicon-o-trash')
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading('Lokasyonu sil?')
            ->action(function (array $arguments) {
                $sub = SubArea::find($arguments['sub_area_id'] ?? null);
                if (!$sub) {
                    return;
                }
                $sub->delete();
                Notification::make()->title('Lokasyon silindi')->success()->send();
            });
    }

    public function setSlaAction(): Action
    {
        return Action::make('setSla')
            ->label('SLA Ayarla')
            ->icon('heroicon-o-clock')
            ->modalHeading(function (array $arguments): string {
                $subAreaId = $arguments['sub_area_id'] ?? null;
                if ($subAreaId) {
                    $sub = SubArea::find((int) $subAreaId);
                    return 'SLA Politikası — Lokasyon: ' . ($sub?->name ?? '—');
                }
                return 'SLA Politikası — Varsayılan (Bölge Geneli)';
            })
            ->fillForm(function (array $arguments): array {
                $areaId    = (int) ($arguments['area_id'] ?? 0);
                $unitId    = (int) ($arguments['unit_id'] ?? 0);
                $subAreaId = $arguments['sub_area_id'] ?? null;
                $subAreaId = $subAreaId ? (int) $subAreaId : null;

                $query = SlaPolicy::where('area_id', $areaId)
                    ->where('unit_id', $unitId);

                if ($subAreaId === null) {
                    $query->whereNull('sub_area_id');
                } else {
                    $query->where('sub_area_id', $subAreaId);
                }

                $rows = $query->get()
                    ->keyBy(fn ($p) => $this->priorityKey((int) $p->getRawOriginal('priority')));

                return [
                    'low_min'      => $rows->get('low')?->deadline_minutes,
                    'medium_min'   => $rows->get('medium')?->deadline_minutes,
                    'high_min'     => $rows->get('high')?->deadline_minutes,
                    'urgent_min'   => $rows->get('urgent')?->deadline_minutes,
                    'success_pct'  => $rows->first()?->success_threshold ?? 80,
                ];
            })
            ->form([
                Grid::make(2)->schema([
                    TextInput::make('low_min')
                        ->label('Düşük (dk)')
                        ->numeric()->minValue(1)->placeholder('Boş = silinir'),
                    TextInput::make('medium_min')
                        ->label('Orta (dk)')
                        ->numeric()->minValue(1)->placeholder('Boş = silinir'),
                    TextInput::make('high_min')
                        ->label('Yüksek (dk)')
                        ->numeric()->minValue(1)->placeholder('Boş = silinir'),
                    TextInput::make('urgent_min')
                        ->label('Acil (dk)')
                        ->numeric()->minValue(1)->placeholder('Boş = silinir'),
                ]),
                TextInput::make('success_pct')
                    ->label('Başarı Eşiği (%)')
                    ->numeric()->minValue(0)->maxValue(100)->default(80),
            ])
            ->action(function (array $arguments, array $data) {
                $areaId    = (int) ($arguments['area_id'] ?? 0);
                $unitId    = (int) ($arguments['unit_id'] ?? 0);
                $subAreaId = $arguments['sub_area_id'] ?? null;
                $subAreaId = $subAreaId ? (int) $subAreaId : null;

                if (!$areaId || !$unitId) {
                    return;
                }

                // Defensive: if a sub_area was specified, make sure it actually
                // belongs to the target area. Without this check, a stale or
                // forged argument could write a row whose area_id and
                // sub_area_id point at unrelated rows — which would still
                // resolve at L1 but represent a logical inconsistency.
                if ($subAreaId !== null) {
                    $belongs = SubArea::where('id', $subAreaId)
                        ->where('area_id', $areaId)
                        ->exists();
                    if (!$belongs) {
                        Notification::make()
                            ->title('Geçersiz lokasyon kapsamı')
                            ->body('Seçilen lokasyon bu bölgeye ait değil.')
                            ->danger()
                            ->send();
                        return;
                    }
                }

                $threshold = (int) ($data['success_pct'] ?? 80);

                $priorityFields = [
                    TaskPriorityEnum::Low->value     => 'low_min',
                    TaskPriorityEnum::Medium->value  => 'medium_min',
                    TaskPriorityEnum::High->value    => 'high_min',
                    TaskPriorityEnum::Urgent->value  => 'urgent_min',
                ];

                foreach ($priorityFields as $priorityValue => $field) {
                    $minutes = $data[$field] ?? null;

                    // The schema-level UNIQUE on (area, sub_area, unit, priority,
                    // deleted_at) treats NULL as distinct in MySQL/SQLite, so the
                    // upsert key must be enforced application-side here via
                    // whereNull / where on the same tuple the user is editing.
                    $existingQuery = SlaPolicy::where('area_id', $areaId)
                        ->where('unit_id', $unitId)
                        ->where('priority', $priorityValue);

                    if ($subAreaId === null) {
                        $existingQuery->whereNull('sub_area_id');
                    } else {
                        $existingQuery->where('sub_area_id', $subAreaId);
                    }

                    $existing = $existingQuery->first();

                    if (filled($minutes)) {
                        if ($existing) {
                            $existing->update([
                                'deadline_minutes'  => (int) $minutes,
                                'success_threshold' => $threshold,
                            ]);
                        } else {
                            SlaPolicy::create([
                                'area_id'           => $areaId,
                                'sub_area_id'       => $subAreaId,
                                'unit_id'           => $unitId,
                                'priority'          => $priorityValue,
                                'deadline_minutes'  => (int) $minutes,
                                'success_threshold' => $threshold,
                                'created_by'        => auth()->id(),
                            ]);
                        }
                    } elseif ($existing) {
                        $existing->delete();
                    }
                }

                Notification::make()->title('SLA güncellendi')->success()->send();
            });
    }

    public function deleteGroupAction(): Action
    {
        return Action::make('deleteGroup')
            ->label('Sil')
            ->icon('heroicon-o-trash')
            ->color('danger')
            ->requiresConfirmation()
            ->action(function (array $arguments) {
                $group = Group::find($arguments['group_id'] ?? null);
                if (!$group) {
                    return;
                }
                $group->members()->delete();
                $group->delete();
                Notification::make()->title('Grup silindi')->success()->send();
            });
    }

    public function addMembersAction(): Action
    {
        return Action::make('addMembers')
            ->label('Üye Ekle')
            ->icon('heroicon-o-user-plus')
            ->modalHeading('Üye Ekle')
            ->form(fn (array $arguments) => [
                Select::make('employee_ids')
                    ->label('Personel')
                    ->multiple()
                    ->searchable()
                    ->options(function () use ($arguments) {
                        $group = Group::find($arguments['group_id'] ?? null);
                        if (!$group) {
                            return [];
                        }
                        $existing = GroupMember::where('group_id', $group->id)
                            ->pluck('employee_id')
                            ->toArray();
                        return Employee::where('company_id', $group->company_id)
                            ->where('status', ActiveStatusEnum::ACTIVE->value)
                            ->whereNotIn('id', $existing)
                            ->orderBy('name')
                            ->limit(500)
                            ->pluck('name', 'id')
                            ->toArray();
                    })
                    ->required(),
            ])
            ->action(function (array $arguments, array $data) {
                $groupId = (int) ($arguments['group_id'] ?? 0);
                if (!$groupId || empty($data['employee_ids'])) {
                    return;
                }
                foreach ($data['employee_ids'] as $employeeId) {
                    GroupMember::firstOrCreate([
                        'group_id'    => $groupId,
                        'employee_id' => (int) $employeeId,
                    ]);
                }
                Notification::make()
                    ->title(count($data['employee_ids']) . ' üye eklendi')
                    ->success()
                    ->send();
            });
    }

    public function removeMemberAction(): Action
    {
        return Action::make('removeMember')
            ->label('Çıkar')
            ->icon('heroicon-o-x-mark')
            ->color('danger')
            ->requiresConfirmation()
            ->action(function (array $arguments) {
                GroupMember::where('id', (int) ($arguments['member_id'] ?? 0))->delete();
                Notification::make()->title('Üye çıkarıldı')->success()->send();
            });
    }

    public function assignRoleAction(): Action
    {
        return Action::make('assignRole')
            ->label('Rol Ata')
            ->icon('heroicon-o-shield-check')
            ->modalHeading('Rol Ata')
            ->form([
                Select::make('role')
                    ->label('Rol')
                    ->options(fn () => $this->assignableRoles())
                    ->required(),
            ])
            ->action(function (array $arguments, array $data) {
                $user = User::find($arguments['user_id'] ?? null);
                if (!$user || empty($data['role'])) {
                    return;
                }
                $user->syncRoles([$data['role']]);
                Notification::make()
                    ->title($user->name . ' → ' . $data['role'])
                    ->success()
                    ->send();
            });
    }

    public function grantExtraCompanyAction(): Action
    {
        return Action::make('grantExtraCompany')
            ->label('Ekstra Şirket Erişimi')
            ->icon('heroicon-o-building-office-2')
            ->modalHeading('Ekstra Şirket Erişimi Ver')
            ->form(fn (array $arguments) => [
                Select::make('company_ids')
                    ->label('Şirketler')
                    ->multiple()
                    ->searchable()
                    ->options(function () use ($arguments) {
                        $user = User::find($arguments['user_id'] ?? null);
                        $own = $user?->employee?->company_id;
                        return Company::query()
                            ->when($own, fn (Builder $q) => $q->where('id', '!=', $own))
                            ->orderBy('name')
                            ->pluck('name', 'id')
                            ->toArray();
                    })
                    ->required(),
            ])
            ->action(function (array $arguments, array $data) {
                $userId = (int) ($arguments['user_id'] ?? 0);
                if (!$userId || empty($data['company_ids'])) {
                    return;
                }
                foreach ($data['company_ids'] as $companyId) {
                    DB::table('user_company_access')->updateOrInsert(
                        ['user_id' => $userId, 'company_id' => (int) $companyId],
                        ['granted_by' => auth()->id(), 'updated_at' => now(), 'created_at' => now()]
                    );
                }
                Notification::make()
                    ->title(count($data['company_ids']) . ' şirket erişimi verildi')
                    ->success()
                    ->send();
            });
    }

    // ─────────────────────────────────────────────────────────────────────
    // DATA HELPERS
    // ─────────────────────────────────────────────────────────────────────

    protected function companyStats(int $companyId): array
    {
        if (!$companyId) {
            return [];
        }

        $company = Company::find($companyId);
        if (!$company) {
            return [];
        }

        $employeeCount = Employee::where('company_id', $companyId)->count();
        $areaCount     = Area::where('company_id', $companyId)->count();
        $unitCount     = Unit::count();
        $groupCount    = Group::where('company_id', $companyId)->count();

        $areaIds = Area::where('company_id', $companyId)->pluck('id')->toArray();
        $unitIds = Unit::pluck('id')->toArray();

        $expectedCombos = count($areaIds) * count($unitIds) * 4;
        $existingCombos = SlaPolicy::whereIn('area_id', $areaIds)
            ->whereIn('unit_id', $unitIds)
            ->select('area_id', 'unit_id', 'priority')
            ->distinct()
            ->count();
        $missingCombos = max(0, $expectedCombos - $existingCombos);

        return [
            'company_name'       => $company->name,
            'employee_count'     => $employeeCount,
            'area_count'         => $areaCount,
            'unit_count'         => $unitCount,
            'sla_count'          => $existingCombos,
            'group_count'        => $groupCount,
            'missing_sla_combos' => $missingCombos,
        ];
    }

    protected function areasFor(int $companyId): array
    {
        if (!$companyId) {
            return [];
        }
        return Area::where('company_id', $companyId)
            ->with(['subAreas' => fn ($q) => $q->orderBy('name')])
            ->orderBy('name')
            ->get()
            ->map(fn (Area $area) => [
                'id'        => $area->id,
                'name'      => $area->name,
                'status'    => (int) ($area->getRawOriginal('status') ?? 1),
                'sub_areas' => $area->subAreas->map(fn (SubArea $sa) => [
                    'id'   => $sa->id,
                    'name' => $sa->name,
                ])->values()->all(),
            ])->values()->all();
    }

    /**
     * Build the matrix data for the SLA editor scoped to a sub_area.
     *
     *  - $selectedSubAreaId === null  → editing area-wide defaults
     *    (sub_area_id IS NULL rows). All areas of the company are shown.
     *  - $selectedSubAreaId is set    → editing location-specific overrides
     *    (sub_area_id = $selectedSubAreaId). Only the area owning that
     *    sub_area is shown — other areas would be nonsensical for that
     *    sub_area and listing them would invite the user to write rows
     *    against an unrelated area.
     */
    protected function slaGridData(int $companyId, ?int $selectedSubAreaId): array
    {
        if (!$companyId) {
            return [
                'areas' => [], 'units' => [], 'matrix' => [],
                'defined' => 0, 'missing' => 0,
                'scope_sub_area_id' => $selectedSubAreaId,
            ];
        }

        $areaQuery = Area::where('company_id', $companyId);

        if ($selectedSubAreaId) {
            // Restrict to the area owning the chosen sub_area. If the chosen
            // sub_area no longer exists (e.g. just deleted), return an empty
            // matrix instead of falling open to all areas.
            $sub = SubArea::find($selectedSubAreaId);
            if (!$sub) {
                return [
                    'areas' => [], 'units' => [], 'matrix' => [],
                    'defined' => 0, 'missing' => 0,
                    'scope_sub_area_id' => $selectedSubAreaId,
                ];
            }
            $areaQuery->where('id', $sub->area_id);
        }

        $areas = $areaQuery->orderBy('name')->get(['id', 'name']);
        $units = Unit::orderBy('name')->get(['id', 'name']);

        $policyQuery = SlaPolicy::whereIn('area_id', $areas->pluck('id'))
            ->whereIn('unit_id', $units->pluck('id'));

        if ($selectedSubAreaId) {
            $policyQuery->where('sub_area_id', $selectedSubAreaId);
        } else {
            $policyQuery->whereNull('sub_area_id');
        }

        $policies = $policyQuery->get();

        $matrix = [];
        foreach ($areas as $area) {
            foreach ($units as $unit) {
                $cellPolicies = $policies->where('area_id', $area->id)->where('unit_id', $unit->id);
                $byPriority = [];
                foreach ([
                    TaskPriorityEnum::Low,
                    TaskPriorityEnum::Medium,
                    TaskPriorityEnum::High,
                    TaskPriorityEnum::Urgent,
                ] as $priority) {
                    $rawValue = $priority->value;
                    $row = $cellPolicies->first(fn ($p) => (int) $p->getRawOriginal('priority') === $rawValue);
                    $byPriority[$priority->value] = [
                        'label'   => $priority->getLabel(),
                        'color'   => $priority->getColor(),
                        'minutes' => $row?->deadline_minutes,
                    ];
                }
                $matrix[$area->id][$unit->id] = [
                    'priorities' => $byPriority,
                    'is_empty'   => $cellPolicies->isEmpty(),
                ];
            }
        }

        $expected = count($areas) * count($units) * 4;
        $defined  = $policies->count();
        $missing  = max(0, $expected - $defined);

        return [
            'areas'             => $areas->all(),
            'units'             => $units->all(),
            'matrix'            => $matrix,
            'defined'           => $defined,
            'missing'           => $missing,
            'scope_sub_area_id' => $selectedSubAreaId,
        ];
    }

    protected function groupsFor(int $companyId): array
    {
        if (!$companyId) {
            return [];
        }
        return Group::where('company_id', $companyId)
            ->with([
                'area:id,name',
                'unit:id,name',
                'manager:id,name',
                'members.employee:id,name',
            ])
            ->orderBy('name')
            ->get()
            ->map(fn (Group $group) => [
                'id'          => $group->id,
                'name'        => $group->name,
                'area'        => $group->area?->name ?? '—',
                'unit'        => $group->unit?->name ?? '—',
                'supervisor'  => $group->manager?->name ?? '—',
                'members'     => $group->members->map(fn (GroupMember $m) => [
                    'member_id' => $m->id,
                    'name'      => $m->employee?->name ?? '—',
                ])->values()->all(),
                'member_count' => $group->members->count(),
            ])->values()->all();
    }

    protected function defaultUsersFor(int $companyId): array
    {
        if (!$companyId) {
            return [];
        }

        $emails = Employee::where('company_id', $companyId)->pluck('email')->filter()->all();

        return User::query()
            ->whereIn('email', $emails)
            ->whereDoesntHave('roles', fn ($q) => $q->where('name', '!=', 'default'))
            ->with('employee:id,email,title,company_id')
            ->orderBy('name')
            ->limit(200)
            ->get()
            ->map(fn (User $user) => [
                'id'             => $user->id,
                'name'           => $user->name,
                'email'          => $user->email,
                'title'          => $user->employee?->title ?? '—',
                'last_ldap_sync' => $user->last_ldap_sync?->format('d.m.Y H:i') ?? '—',
            ])->values()->all();
    }

    protected function roledUsersFor(int $companyId): array
    {
        if (!$companyId) {
            return [];
        }

        $emails = Employee::where('company_id', $companyId)->pluck('email')->filter()->all();

        return User::query()
            ->whereIn('email', $emails)
            ->whereHas('roles', fn ($q) => $q->where('name', '!=', 'default'))
            ->with(['roles', 'employee:id,email,company_id'])
            ->orderBy('name')
            ->limit(200)
            ->get()
            ->map(fn (User $user) => [
                'id'              => $user->id,
                'name'            => $user->name,
                'email'           => $user->email,
                'role'            => $user->roles->first()?->name ?? '—',
                'extra_companies' => DB::table('user_company_access')
                    ->where('user_id', $user->id)
                    ->count(),
            ])->values()->all();
    }

    protected function summaryStats(int $companyId): array
    {
        $base = $this->companyStats($companyId);

        if (!$companyId || empty($base)) {
            return [];
        }

        $emails = Employee::where('company_id', $companyId)->pluck('email')->filter()->all();

        $usersWithRoles = User::whereIn('email', $emails)
            ->whereHas('roles', fn ($q) => $q->where('name', '!=', 'default'))
            ->count();

        $usersDefault = User::whereIn('email', $emails)
            ->whereDoesntHave('roles', fn ($q) => $q->where('name', '!=', 'default'))
            ->count();

        $subAreaCount = SubArea::whereIn(
            'area_id',
            Area::where('company_id', $companyId)->pluck('id')
        )->count();

        $totalMembers = GroupMember::whereIn(
            'group_id',
            Group::where('company_id', $companyId)->pluck('id')
        )->count();

        // Compute missing combinations as (area, unit, priority) tuples
        $areas = Area::where('company_id', $companyId)->get(['id', 'name']);
        $units = Unit::get(['id', 'name']);
        $existing = SlaPolicy::whereIn('area_id', $areas->pluck('id'))
            ->whereIn('unit_id', $units->pluck('id'))
            ->get(['area_id', 'unit_id', 'priority']);

        $missingList = [];
        $priorities = [
            TaskPriorityEnum::Low,
            TaskPriorityEnum::Medium,
            TaskPriorityEnum::High,
            TaskPriorityEnum::Urgent,
        ];
        foreach ($areas as $area) {
            foreach ($units as $unit) {
                foreach ($priorities as $priority) {
                    $hit = $existing->first(fn ($p) =>
                        $p->area_id === $area->id
                        && $p->unit_id === $unit->id
                        && (int) $p->getRawOriginal('priority') === $priority->value
                    );
                    if (!$hit) {
                        $missingList[] = $area->name . ' / ' . $unit->name . ' / ' . $priority->getLabel();
                    }
                }
            }
        }

        return array_merge($base, [
            'sub_area_count'    => $subAreaCount,
            'group_member_count' => $totalMembers,
            'users_with_roles'  => $usersWithRoles,
            'users_default'     => $usersDefault,
            'missing_list'      => array_slice($missingList, 0, 30),
            'missing_total'     => count($missingList),
        ]);
    }

    protected function assignableRoles(): array
    {
        return Role::query()
            ->where('name', '!=', 'super_admin')
            ->orderBy('name')
            ->pluck('name', 'name')
            ->toArray();
    }

    /**
     * Map raw priority int → keyword used for grouping policy rows in the
     * SLA grid editor. Centralised so future enum additions only touch here.
     */
    protected function priorityKey(int $value): string
    {
        return match ($value) {
            TaskPriorityEnum::Low->value     => 'low',
            TaskPriorityEnum::Medium->value  => 'medium',
            TaskPriorityEnum::High->value    => 'high',
            TaskPriorityEnum::Urgent->value  => 'urgent',
            default => 'unknown',
        };
    }
}
