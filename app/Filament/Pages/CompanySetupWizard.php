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
use Illuminate\Support\HtmlString;
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
                Wizard::make([
                    $this->stepCompany(),
                    $this->stepAreas(),
                    $this->stepSla(),
                    $this->stepGroups(),
                    $this->stepUsers(),
                    $this->stepSummary(),
                ])
                    ->skippable()
                    ->persistStepInQueryString()
                    ->submitAction(new HtmlString(
                        '<button type="button" wire:click="finish" class="fi-btn fi-btn-color-primary inline-flex items-center justify-center rounded-lg px-4 py-2 text-sm font-semibold text-white bg-primary-600 hover:bg-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500">'
                        . 'Tamamla'
                        . '</button>'
                    )),
            ]);
    }

    public function finish(): void
    {
        Notification::make()
            ->title('Kurulum tamamlandı')
            ->success()
            ->send();

        $this->redirect(filament()->getDefaultPanel()->getUrl());
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
                    ->required(),

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
            ->schema([
                Placeholder::make('sla_empty_company')
                    ->hiddenLabel()
                    ->content('Devam etmek için Adım 1\'de bir şirket seçin.')
                    ->visible(fn (Forms\Get $get) => blank($get('companyId'))),

                Section::make('SLA Matrisi')
                    ->description('Hücreye tıklayarak öncelik bazlı SLA dakikalarını düzenleyin.')
                    ->visible(fn (Forms\Get $get) => filled($get('companyId')))
                    ->schema([
                        ViewField::make('sla_grid')
                            ->hiddenLabel()
                            ->view('filament.pages.company-setup-wizard.partials.sla-grid')
                            ->viewData(fn (Forms\Get $get) => $this->slaGridData(
                                (int) ($get('companyId') ?? 0)
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
                                TextInput::make('name')->label('Grup Adı')->required(),
                                Select::make('area_id')
                                    ->label('Bölge')
                                    ->options(fn () => Area::where('company_id', (int) $get('companyId'))
                                        ->orderBy('name')
                                        ->pluck('name', 'id'))
                                    ->required()
                                    ->searchable(),
                                Select::make('unit_id')
                                    ->label('Birim')
                                    ->options(fn () => Unit::orderBy('name')->pluck('name', 'id'))
                                    ->required()
                                    ->searchable(),
                                Select::make('employee_id')
                                    ->label('Amir')
                                    ->options(fn () => Employee::where('company_id', (int) $get('companyId'))
                                        ->where('status', ActiveStatusEnum::ACTIVE->value)
                                        ->orderBy('name')
                                        ->limit(500)
                                        ->pluck('name', 'id'))
                                    ->required()
                                    ->searchable(),
                            ])
                            ->action(function (array $data, Forms\Get $get) {
                                Group::create([
                                    'name'        => $data['name'],
                                    'company_id'  => (int) $get('companyId'),
                                    'area_id'     => (int) $data['area_id'],
                                    'unit_id'     => (int) $data['unit_id'],
                                    'employee_id' => (int) $data['employee_id'],
                                    'status'      => ActiveStatusEnum::ACTIVE->value,
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
                    'area_id' => $areaId,
                    'name'    => $data['name'],
                    'status'  => ActiveStatusEnum::ACTIVE->value,
                ]);
                Notification::make()->title('Lokasyon eklendi')->success()->send();
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
            ->modalHeading('SLA Politikası')
            ->fillForm(function (array $arguments): array {
                $areaId = (int) ($arguments['area_id'] ?? 0);
                $unitId = (int) ($arguments['unit_id'] ?? 0);
                $rows = SlaPolicy::where('area_id', $areaId)
                    ->where('unit_id', $unitId)
                    ->get()
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
                $areaId = (int) ($arguments['area_id'] ?? 0);
                $unitId = (int) ($arguments['unit_id'] ?? 0);
                if (!$areaId || !$unitId) {
                    return;
                }

                // sla_policies.sub_area_id is NOT NULL — pin to the area's
                // first sub_area. SlaService falls back at area+unit+priority.
                $subAreaId = SubArea::where('area_id', $areaId)
                    ->orderBy('id')
                    ->value('id');
                if (!$subAreaId) {
                    Notification::make()
                        ->title('Önce bu bölgeye en az bir lokasyon ekleyin')
                        ->danger()
                        ->send();
                    return;
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

                    $existing = SlaPolicy::where('area_id', $areaId)
                        ->where('unit_id', $unitId)
                        ->where('priority', $priorityValue)
                        ->first();

                    if (filled($minutes)) {
                        if ($existing) {
                            $existing->update([
                                'deadline_minutes'  => (int) $minutes,
                                'success_threshold' => $threshold,
                                'sub_area_id'       => $existing->sub_area_id ?: $subAreaId,
                            ]);
                        } else {
                            SlaPolicy::create([
                                'area_id'           => $areaId,
                                'sub_area_id'       => $subAreaId,
                                'unit_id'           => $unitId,
                                'priority'          => $priorityValue,
                                'deadline_minutes'  => (int) $minutes,
                                'success_threshold' => $threshold,
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

    protected function slaGridData(int $companyId): array
    {
        if (!$companyId) {
            return ['areas' => [], 'units' => [], 'matrix' => [], 'defined' => 0, 'missing' => 0];
        }

        $areas = Area::where('company_id', $companyId)
            ->orderBy('name')
            ->get(['id', 'name']);

        $units = Unit::orderBy('name')->get(['id', 'name']);

        $policies = SlaPolicy::whereIn('area_id', $areas->pluck('id'))
            ->whereIn('unit_id', $units->pluck('id'))
            ->get();

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
            'areas'   => $areas->all(),
            'units'   => $units->all(),
            'matrix'  => $matrix,
            'defined' => $defined,
            'missing' => $missing,
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
