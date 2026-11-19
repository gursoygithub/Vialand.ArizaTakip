<?php

namespace App\Filament\Pages;

use App\Models\Team;
use App\Models\UserNotificationPreference;
use App\Services\TeamTicketNotifier;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

class NotificationPreferences extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-bell';

    protected static ?int $navigationSort = 20;

    protected static string $view = 'filament.pages.notification-preferences';

    public ?array $data = [];

    /**
     * Display order + Turkish labels for each notification type.
     * Kept in one place so the UI grid and the save-handler agree.
     */
    public const ROWS = [
        'ticket_assigned'    => 'Bana atanan talepler',
        'ticket_participant' => 'Takip ettiğim talepler',
        'ticket_closed'      => 'Talep kapatıldı',
        'ticket_reopened'    => 'Talep yeniden açıldı',
        'ticket_reassigned'  => 'Personel değişikliği',
        'sla_warning'        => 'SLA Uyarısı',
        'sla_breach'         => 'SLA İhlali',
    ];

    /**
     * The team model only honours these switches: the bell for steps of
     * requests the person follows (never mailed), and the supervisor's hold
     * notices. Action notices and SLA alerts cannot be switched off.
     *
     * @return array<string, array{label: string, channels: array<int, string>}>
     */
    public static function rows(): array
    {
        if (config('legacy.enabled')) {
            return collect(self::ROWS)
                ->map(fn (string $label): array => ['label' => $label, 'channels' => ['database', 'mail']])
                ->all();
        }

        $rows = [
            'ticket_participant' => ['label' => __('ui.pref_ticket_participant'), 'channels' => ['database']],
        ];

        $employeeId = auth()->user()?->employee?->id;

        if ($employeeId && Team::query()->where('supervisor_employee_id', $employeeId)->exists()) {
            $rows[TeamTicketNotifier::PREFERENCE_TEAM_HOLD] = ['label' => __('ui.pref_team_hold'), 'channels' => ['database', 'mail']];
            $rows[TeamTicketNotifier::PREFERENCE_TEAM_HOLD_OVERDUE] = ['label' => __('ui.pref_team_hold_overdue'), 'channels' => ['database', 'mail']];
        }

        return $rows;
    }

    public static function getNavigationLabel(): string
    {
        return 'Bildirim Tercihlerim';
    }

    public function getTitle(): string
    {
        return 'Bildirim Tercihlerim';
    }

    public static function getNavigationGroup(): ?string
    {
        return __('ui.system');
    }

    public static function shouldRegisterNavigation(): bool
    {
        return (bool) auth()->user();
    }

    public static function canAccess(): bool
    {
        return (bool) auth()->user();
    }

    public function mount(): void
    {
        $this->form->fill($this->loadPreferences());
    }

    /**
     * Build the data state from existing preference rows; missing rows
     * default to true (matches User::wantsNotification semantics).
     */
    private function loadPreferences(): array
    {
        $existing = UserNotificationPreference::query()
            ->where('user_id', auth()->id())
            ->get()
            ->keyBy('notification_type');

        $state = [];
        foreach (array_keys(self::rows()) as $type) {
            $row = $existing->get($type);
            $state[$type . '_database'] = $row ? (bool) $row->database_enabled : true;
            $state[$type . '_mail']     = $row ? (bool) $row->mail_enabled     : true;
        }
        return $state;
    }

    public function form(Form $form): Form
    {
        $schema = [
            Placeholder::make('note')
                ->hiddenLabel()
                ->content(new \Illuminate\Support\HtmlString(
                    '<div class="border-l-4 border-amber-400 rounded-md bg-amber-50 dark:bg-amber-900/30 p-4">'
                    . '<span class="text-amber-800 dark:text-amber-200">⚠️ '.e(config('legacy.enabled') ? 'Kritik bildirimler (Atama ve SLA İhlali) kapatılamaz.' : __('ui.pref_mandatory_note')).'</span>'
                    . '</div>'
                )),
        ];

        $schema[] = Section::make('Bildirim Türleri')
            ->description('Her bildirim türü için uygulama içi ve e-posta tercihlerinizi belirleyebilirsiniz.')
            ->schema($this->buildRowSchema())
            ->columns(1);

        return $form->statePath('data')->schema($schema);
    }

    private function buildRowSchema(): array
    {
        $rows = [];

        foreach (self::rows() as $type => ['label' => $label, 'channels' => $channels]) {
            $alwaysOnDb = in_array($type, UserNotificationPreference::ALWAYS_ON_DATABASE, true);

            $rows[] = \Filament\Forms\Components\Grid::make([
                'default' => 1,
                'md'      => 3,
            ])->schema([
                Placeholder::make('label_' . $type)
                    ->hiddenLabel()
                    ->content(new \Illuminate\Support\HtmlString(
                        '<div class="font-semibold text-gray-900 dark:text-white" style="padding-top:6px;">'
                        . e($label)
                        . ($alwaysOnDb ? ' <span class="text-red-600 dark:text-red-400" style="font-size:0.75em;font-weight:500;">(zorunlu)</span>' : '')
                        . '</div>'
                    )),

                Toggle::make($type . '_database')
                    ->label('Uygulama İçi')
                    ->disabled($alwaysOnDb)
                    ->default(true)
                    ->live()
                    ->afterStateUpdated(fn (bool $state) => $this->savePreference($type, 'database', $state)),

                Toggle::make($type . '_mail')
                    ->label('E-posta')
                    ->visible(in_array('mail', $channels, true))
                    ->default(true)
                    ->live()
                    ->afterStateUpdated(fn (bool $state) => $this->savePreference($type, 'mail', $state)),
            ]);
        }

        return $rows;
    }

    /**
     * Per-toggle save: upserts the (user_id, notification_type) row with
     * the current data state. Toggling either channel writes both — the
     * other column comes straight from $this->data so a previously-saved
     * value isn't clobbered by the default true.
     */
    public function savePreference(string $type, string $channel, bool $state): void
    {
        $userId = auth()->id();
        if (!$userId) {
            return;
        }

        // Critical types ignore the database opt-out at the storage layer too.
        if ($channel === 'database'
            && in_array($type, UserNotificationPreference::ALWAYS_ON_DATABASE, true)) {
            $state = true;
        }

        $databaseEnabled = (bool) ($this->data[$type . '_database'] ?? true);
        $mailEnabled     = (bool) ($this->data[$type . '_mail']     ?? true);

        UserNotificationPreference::updateOrCreate(
            ['user_id' => $userId, 'notification_type' => $type],
            [
                'database_enabled' => in_array($type, UserNotificationPreference::ALWAYS_ON_DATABASE, true)
                    ? true
                    : $databaseEnabled,
                'mail_enabled'     => $mailEnabled,
            ],
        );

        Notification::make()
            ->title('Tercih kaydedildi')
            ->success()
            ->duration(2000)
            ->send();
    }
}
