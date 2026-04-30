<?php

namespace App\Filament\Pages;

use App\Settings\GeneralSettings;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Pages\SettingsPage;

class ManageGeneralSettings extends SettingsPage
{

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $navigationIcon = 'heroicon-o-cog-6-tooth';

    // Ayar sınıfımızı buraya bağlıyoruz
    protected static string $settings = GeneralSettings::class;

    public static function getNavigationLabel(): string
    {
        return 'Sistem Ayarları';
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
        $user = auth()->user();
        return $user?->hasRole('super_admin') || $user?->can('manage settings');
    }
}
