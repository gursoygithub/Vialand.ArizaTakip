<?php

namespace App\Filament\Pages;

use App\Models\UserNotificationPreference;
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

    protected static ?int $navigationSort = 2;

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
        foreach (array_keys(self::ROWS) as $type) {
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
                    '<div style="background:#fef3c7;border-left:4px solid #f59e0b;padding:12px 16px;border-radius:6px;color:#92400e;">'
                    . '⚠️ <strong>Kritik bildirimler</strong> (Atama ve SLA İhlali) kapatılamaz.'
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

        foreach (self::ROWS as $type => $label) {
            $alwaysOnDb = in_array($type, UserNotificationPreference::ALWAYS_ON_DATABASE, true);

            $rows[] = \Filament\Forms\Components\Grid::make([
                'default' => 1,
                'md'      => 3,
            ])->schema([
                Placeholder::make('label_' . $type)
                    ->hiddenLabel()
                    ->content(new \Illuminate\Support\HtmlString(
                        '<div style="font-weight:600;color:#111827;padding-top:6px;">'
                        . e($label)
                        . ($alwaysOnDb ? ' <span style="font-size:0.75em;color:#dc2626;font-weight:500;">(zorunlu)</span>' : '')
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
