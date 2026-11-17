<?php

namespace App\Filament\Pages;

use App\Settings\GeneralSettings;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Pages\SettingsPage;

class ManageGeneralSettings extends SettingsPage
{

    protected static ?string $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected static ?int $navigationSort = 1;

    // Ayar sınıfımızı buraya bağlıyoruz
    protected static string $settings = GeneralSettings::class;

    public static function getNavigationLabel(): string
    {
        return 'Sistem Ayarları';
    }

    public static function getNavigationGroup(): ?string
    {
        return __('ui.system');
    }

    public static function shouldRegisterNavigation(): bool
    {
        return auth()->user()?->can('page_ManageGeneralSettings') ?? false;
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('Performans ve SLA Ayarları')
                    ->description('Personel değerlendirme kriterlerini buradan yönetebilirsiniz.')
                    ->schema([
                        TextInput::make('sla_threshold')
                            ->label('SLA Başarı Eşiği (%)')
                            ->numeric()
                            ->suffix('%')
                            ->minValue(0)
                            ->maxValue(100)
                            ->required()
                            ->helperText('Personelin "Verimli" sayılması için gereken minimum başarı yüzdesi.'),
                    ]),

                Section::make(__('ui.ticket_lifecycle_settings'))
                    ->description(__('ui.ticket_lifecycle_settings_help'))
                    ->columns(2)
                    ->schema([
                        TextInput::make('auto_close_days')
                            ->label(__('ui.auto_close_days'))
                            ->numeric()
                            ->integer()
                            ->minValue(1)
                            ->maxValue(30)
                            ->suffix(__('ui.days'))
                            ->required()
                            ->helperText(__('ui.auto_close_days_help')),

                        TextInput::make('reopen_window_days')
                            ->label(__('ui.reopen_window_days'))
                            ->numeric()
                            ->integer()
                            ->minValue(1)
                            ->maxValue(90)
                            ->suffix(__('ui.days'))
                            ->required()
                            ->helperText(__('ui.reopen_window_days_help')),

                        TextInput::make('sla_escalation_hours')
                            ->label(__('ui.sla_escalation_hours'))
                            ->numeric()
                            ->integer()
                            ->minValue(0)
                            ->maxValue(168)
                            ->suffix(__('ui.hours_short'))
                            ->required()
                            ->helperText(__('ui.sla_escalation_hours_help')),
                    ]),
            ]);
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->can('page_ManageGeneralSettings') ?? false;
    }
}
