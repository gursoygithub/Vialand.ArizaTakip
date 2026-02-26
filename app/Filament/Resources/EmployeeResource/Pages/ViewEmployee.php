<?php

namespace App\Filament\Resources\EmployeeResource\Pages;

use App\Filament\Resources\EmployeeResource;
use Filament\Infolists;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Pages\ViewRecord;

class ViewEmployee extends ViewRecord
{
    protected static string $resource = EmployeeResource::class;

    public function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                // 1. BÖLÜM: PERSONEL KİMLİK BİLGİLERİ
                Infolists\Components\Section::make(__('ui.employee'))
                    ->icon('heroicon-o-user-circle')
                    ->description(__('ui.employee_details'))
                    ->columns(3)
                    ->compact()
                    ->schema([
                        TextEntry::make('name')
                            ->label(__('ui.name'))
                            ->helperText(fn($record): ?string => $record->title ?? '-')
                            ->icon('heroicon-o-user')
                            ->size('lg')
                            ->weight('bold'),

                        TextEntry::make('email')
                            ->label(__('ui.email'))
                            ->icon('heroicon-o-envelope'),

                        TextEntry::make('phone')
                            ->label(__('ui.phone'))
                            ->icon('heroicon-o-phone')
                            ->placeholder('—'),
                    ]),

                // 2. BÖLÜM: PERFORMANS ANALİZİ (YÖNETİCİ ÖZETİ)
                Infolists\Components\Section::make('SLA Performans Analizi')
                    ->description('Personelin başarı oranları ve iş yükü dağılımı.')
                    ->icon('heroicon-o-presentation-chart-line')
                    ->columns(4)
                    ->schema([
                        // Mevcut Başarı Oranı
                        TextEntry::make('performance_score')
                            ->label('SLA Başarı Oranı')
                            ->numeric(2)
                            ->suffix('%')
                            ->weight('bold')
                            ->color(fn ($record) => $record->performance_score >= $record->current_threshold ? 'success' : 'danger'),

                        // Hedef (Baraj)
                        TextEntry::make('current_threshold')
                            ->label('SLA Hedef Oranı')
                            ->numeric(2)
                            ->suffix('%')
                            ->color('gray'),

                        // İş Yükü Hacmi (Kaç görev üzerinden bu puanı aldı?)
                        TextEntry::make('tasks_count')
                            ->label('Tamamlanan İş Hacmi')
                            ->getStateUsing(fn ($record) => $record->tasks()->whereIn('sla_outcome', ['SUCCESS', 'FAILED'])->count() . ' Görev')
                            ->icon('heroicon-o-briefcase')
                            ->color('info'),

                        // Görsel Durum Etiketi
                        TextEntry::make('status')
                            ->label('Genel Yeterlilik')
                            ->getStateUsing(fn ($record) => $record->performance_score >= $record->current_threshold ? 'SLA UYUMLU' : 'GELİŞTİRİLMELİ')
                            ->badge()
                            ->color(fn ($record) => $record->performance_score >= $record->current_threshold ? 'success' : 'danger')
                            ->icon(fn ($record) => $record->performance_score >= $record->current_threshold ? 'heroicon-m-check-badge' : 'heroicon-m-exclamation-triangle'),
                    ]),

                // 3. BÖLÜM: DETAYLI İSTATİSTİKLER (BAŞARI VE HIZ)
                Infolists\Components\Section::make('Görev Detay Analizi')
                    ->columns(3)
                    ->compact()
                    ->schema([
                        TextEntry::make('success_tasks')
                            ->label('Zamanında Kapatılan')
                            ->getStateUsing(fn($record) => $record->tasks()->where('sla_outcome', 'SUCCESS')->count())
                            ->icon('heroicon-o-check-circle')
                            ->color('success'),

                        TextEntry::make('failed_tasks')
                            ->label('Süresi Geçen')
                            ->getStateUsing(fn($record) => $record->tasks()->where('sla_outcome', 'FAILED')->count())
                            ->icon('heroicon-o-clock')
                            ->color('danger'),

                        TextEntry::make('discipline_note')
                            ->label('Disiplin Notu')
                            ->getStateUsing(fn($record) => $record->performance_score > 80 ? 'Yüksek' : 'Takip Gerekiyor')
                            ->weight('bold')
                            ->color(fn($record) => $record->performance_score > 60 ? 'info' : 'warning'),
                    ]),
            ]);
    }
}