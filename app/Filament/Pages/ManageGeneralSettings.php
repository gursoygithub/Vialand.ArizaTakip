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
        return (bool) auth()->user()?->can('manage_settings');
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
                    ])
            ]);
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->can('page_ManageGeneralSettings') ?? false;
    }
}
