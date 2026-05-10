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

/**
 * 5-step setup wizard scoped to a single company. Steps save independently
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
     * Step keys: 'areas', 'sla', 'groups'.
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
        return auth()->user()?->can('page_CompanySetupWizard') ?? false;
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->can('page_CompanySetupWizard') ?? false;
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

                // HARD 1: an area with ZERO policies is unusable — no L1/L2/L3
                // path can match, leaving only the global L4 fallback which
                // may not exist. Block until at least one row is defined.
                $units = Unit::orderBy('name')->get(['id', 'name']);

                $areasWithNoSla = [];
                foreach ($areas as $area) {
                    $any = SlaPolicy::where('area_id', $area->id)
                        ->whereIn('unit_id', $units->pluck('id'))
                        ->exists();

                    if (!$any) {
                        $areasWithNoSla[] = $area->name;
                    }
                }

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

                // HARD 2: for every (area, unit) the user has STARTED defining
                // policies for, Acil and Yüksek must each be defined in AT
                // LEAST ONE scope — default (sub_area_id IS NULL) OR any
                // specific lokasyon. Defining Acil+Yüksek for every lokasyon
                // of an area satisfies the rule even if the default scope is
                // empty. Units the user hasn't touched yet are excluded so
                // the wizard isn't a wall.
                //
                // SOFT (parallel pass): same predicate for Düşük/Orta — a
                // missing critical-tier row in any scope falls through to
                // L3/L4 (area+priority / global), which is acceptable but
                // worth surfacing as a warning.
                $criticalMissing = [];
                $standardMissing = [];

                foreach ($areas as $area) {
                    $touchedUnitIds = SlaPolicy::where('area_id', $area->id)
                        ->whereIn('unit_id', $units->pluck('id'))
                        ->select('unit_id')
                        ->distinct()
                        ->pluck('unit_id')
                        ->all();

                    if (empty($touchedUnitIds)) {
                        continue;
                    }

                    foreach ($touchedUnitIds as $unitId) {
                        $unit = $units->firstWhere('id', $unitId);
                        if (!$unit) {
                            continue;
                        }

                        // Any scope counts — pull every distinct priority
                        // present for this (area, unit) regardless of
                        // sub_area_id. select+distinct+get keeps the query
                        // portable across MySQL/SQLite (count(DISTINCT col1,
                        // col2) is MySQL-only).
                        $coveredPriorities = SlaPolicy::where('area_id', $area->id)
                            ->where('unit_id', $unitId)
                            ->select('priority')
                            ->distinct()
                            ->get()
                            ->pluck('priority')
                            ->map(fn ($p) => (int) (is_object($p) ? $p->value : $p))
                            ->all();

                        $criticalGap = [];
                        if (!in_array(TaskPriorityEnum::Urgent->value, $coveredPriorities, true)) {
                            $criticalGap[] = 'Acil eksik';
                        }
                        if (!in_array(TaskPriorityEnum::High->value, $coveredPriorities, true)) {
                            $criticalGap[] = 'Yüksek eksik';
                        }
                        if (!empty($criticalGap)) {
                            $criticalMissing[] = $area->name . ' / ' . $unit->name . ' (' . implode(', ', $criticalGap) . ')';
                        }

                        $standardGap = [];
                        if (!in_array(TaskPriorityEnum::Medium->value, $coveredPriorities, true)) {
                            $standardGap[] = 'Orta eksik';
                        }
                        if (!in_array(TaskPriorityEnum::Low->value, $coveredPriorities, true)) {
                            $standardGap[] = 'Düşük eksik';
                        }
                        if (!empty($standardGap)) {
                            $standardMissing[] = $area->name . ' / ' . $unit->name . ' (' . implode(', ', $standardGap) . ')';
                        }
                    }
                }

                if (!empty($criticalMissing)) {
                    Notification::make()
                        ->title('Acil ve Yüksek öncelik SLA\'ları eksik')
                        ->body('Aşağıdaki birimler için Acil ve Yüksek öncelik SLA\'ları tanımlanmadan devam edilemez: ' . implode(', ', $criticalMissing))
                        ->danger()
                        ->persistent()
                        ->send();
                    throw new \Filament\Support\Exceptions\Halt;
                }

                if (!empty($standardMissing)) {
                    Notification::make()
                        ->title('Düşük/Orta öncelik SLA\'ları eksik')
                        ->body('Bazı birimler için Düşük/Orta öncelik SLA\'ları eksik. Bu kombinasyonlarda genel SLA politikası uygulanacaktır. Eksikler: ' . implode(', ', $standardMissing))
                        ->warning()
                        ->send();
                }
            })
            ->schema([
                Placeholder::make('sla_empty_company')
                    ->hiddenLabel()
                    ->content('Devam etmek için Adım 1\'de bir şirket seçin.')
                    ->visible(fn (Forms\Get $get) => blank($get('companyId'))),

                Section::make('SLA Matrisi')
                    ->description('Lokasyon kapsamını seçin, ardından hücrelere tıklayarak öncelik bazlı SLA dakikalarını düzenleyin. Varsayılan kapsamdaki ayarlar tüm lokasyonlar için geçerli olur; belirli bir lokasyon seçerseniz o lokasyon için ayrı SLA tanımlayabilirsiniz.')
                    ->visible(fn (Forms\Get $get) => filled($get('companyId')))
                    ->schema([
                        // Scope selector: empty value = "default for the area"
                        // (matches L2 in SlaService::resolvePolicy). Selecting a
                        // specific lokasyon filters the matrix to that area and
                        // edits L1 (location-specific) rows.
                        Select::make('slaScopeSubAreaId')
                            ->label('Lokasyon Kapsamı')
                            ->placeholder('Varsayılan (tüm lokasyonlar)')
                            ->helperText('Boş bırakırsanız tüm lokasyonlar için geçerli varsayılan SLA tanımlanır. Bir lokasyon seçerseniz o lokasyon için ayrı SLA tanımlanır ve matris yalnızca ilgili bölgeyi gösterir.')
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
    // STEP 5 — Özet
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

    public function editGroupAction(): Action
    {
        return Action::make('editGroup')
            ->label('Düzenle')
            ->icon('heroicon-o-pencil')
            ->modalHeading('Grup Düzenle')
            ->fillForm(function (array $arguments): array {
                $group = Group::find($arguments['group_id'] ?? null);
                if (!$group) {
                    return [];
                }
                return [
                    'name'        => $group->name,
                    'area_id'     => $group->area_id,
                    'unit_id'     => $group->unit_id,
                    'employee_id' => $group->employee_id,
                ];
            })
            ->form(fn (array $arguments) => [
                TextInput::make('name')
                    ->label('Grup Adı')
                    ->required()
                    ->validationMessages(['required' => 'Grup adı zorunludur.']),

                // Mirror the addGroup constraint (only SLA-bearing areas), but
                // union the group's CURRENT area into the option list even if
                // it no longer satisfies the predicate — otherwise the field
                // would render blank and the user would lose track of where
                // the group lives. The fallback option is suffixed
                // "(kapsam dışı)" so the user sees the constraint mismatch
                // before saving.
                Select::make('area_id')
                    ->label('Bölge')
                    ->helperText('Sadece SLA politikası tanımlanmış bölgeler listelenmektedir.')
                    ->options(function () use ($arguments) {
                        $companyId = (int) ($this->data['companyId'] ?? 0);

                        $areas = Area::query()
                            ->where('company_id', $companyId)
                            ->where('status', ActiveStatusEnum::ACTIVE->value)
                            ->whereHas('slaPolicies')
                            ->orderBy('name')
                            ->pluck('name', 'id')
                            ->toArray();

                        $group = Group::with('area')->find($arguments['group_id'] ?? null);
                        if ($group && $group->area && !isset($areas[$group->area_id])) {
                            $areas[$group->area_id] = $group->area->name . ' (kapsam dışı)';
                        }

                        return $areas;
                    })
                    ->required()
                    ->searchable()
                    ->live()
                    ->afterStateUpdated(fn (Forms\Set $set) => $set('unit_id', null))
                    ->validationMessages(['required' => 'Bölge alanı zorunludur.']),

                Select::make('unit_id')
                    ->label('Birim')
                    ->options(function (Forms\Get $get) use ($arguments) {
                        $areaId = $get('area_id');

                        if (!$areaId) {
                            return Unit::orderBy('name')->pluck('name', 'id')->toArray();
                        }

                        $unitIds = SlaPolicy::where('area_id', $areaId)
                            ->distinct()
                            ->pluck('unit_id');

                        $units = $unitIds->isEmpty()
                            ? []
                            : Unit::whereIn('id', $unitIds)
                                ->orderBy('name')
                                ->pluck('name', 'id')
                                ->toArray();

                        // Same defensive fallback as area_id above.
                        $group = Group::with('unit')->find($arguments['group_id'] ?? null);
                        if ($group && $group->unit && (int) $group->area_id === (int) $areaId
                            && !isset($units[$group->unit_id])) {
                            $units[$group->unit_id] = $group->unit->name . ' (kapsam dışı)';
                        }

                        return $units;
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
                        ->where('company_id', (int) ($this->data['companyId'] ?? 0))
                        ->where('status', ActiveStatusEnum::ACTIVE->value)
                        ->orderBy('name')
                        ->limit(500)
                        ->pluck('name', 'id'))
                    ->required()
                    ->searchable()
                    ->validationMessages(['required' => 'Amir alanı zorunludur.']),
            ])
            ->action(function (array $arguments, array $data) {
                $group = Group::find($arguments['group_id'] ?? null);
                if (!$group) {
                    return;
                }
                $group->update([
                    'name'        => $data['name'],
                    'area_id'     => (int) $data['area_id'],
                    'unit_id'     => (int) $data['unit_id'],
                    'employee_id' => (int) $data['employee_id'],
                ]);
                Notification::make()->title('Grup güncellendi')->success()->send();
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
        $emptyShape = [
            'areas'               => [], 'units' => [], 'matrix' => [],
            'critical_count'      => 0, // Acil + Yüksek in current scope
            'standard_count'      => 0, // Orta + Düşük in current scope
            'location_count'      => 0, // total override rows in this company
            'scope_sub_area_id'   => $selectedSubAreaId,
            'scope_sub_area_name' => null,
        ];

        if (!$companyId) {
            return $emptyShape;
        }

        $areaQuery = Area::where('company_id', $companyId);
        $scopeSubAreaName = null;

        if ($selectedSubAreaId) {
            // Restrict to the area owning the chosen sub_area. If the chosen
            // sub_area no longer exists (e.g. just deleted), return an empty
            // matrix instead of falling open to all areas.
            $sub = SubArea::find($selectedSubAreaId);
            if (!$sub) {
                return $emptyShape;
            }
            $areaQuery->where('id', $sub->area_id);
            $scopeSubAreaName = $sub->name;
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

        // Three counters surfaced under the matrix:
        //   - critical:  Acil + Yüksek in the currently-edited scope.
        //   - standard:  Orta + Düşük in the currently-edited scope.
        //   - location:  total location-specific rows across the whole
        //                company (NOT scoped to the current view), so the
        //                user sees the override surface even while editing
        //                the default scope.
        $criticalPriorities = [TaskPriorityEnum::Urgent->value, TaskPriorityEnum::High->value];
        $standardPriorities = [TaskPriorityEnum::Medium->value, TaskPriorityEnum::Low->value];

        $criticalCount = $policies->filter(fn ($p) => in_array((int) $p->getRawOriginal('priority'), $criticalPriorities, true))->count();
        $standardCount = $policies->filter(fn ($p) => in_array((int) $p->getRawOriginal('priority'), $standardPriorities, true))->count();

        $companyAreaIds = Area::where('company_id', $companyId)->pluck('id');
        $locationCount = SlaPolicy::whereIn('area_id', $companyAreaIds)
            ->whereNotNull('sub_area_id')
            ->count();

        return [
            'areas'               => $areas->all(),
            'units'               => $units->all(),
            'matrix'              => $matrix,
            'critical_count'      => $criticalCount,
            'standard_count'      => $standardCount,
            'location_count'      => $locationCount,
            'scope_sub_area_id'   => $selectedSubAreaId,
            'scope_sub_area_name' => $scopeSubAreaName,
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

    protected function summaryStats(int $companyId): array
    {
        $base = $this->companyStats($companyId);

        if (!$companyId || empty($base)) {
            return [];
        }

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
            'missing_list'      => array_slice($missingList, 0, 30),
            'missing_total'     => count($missingList),
        ]);
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
