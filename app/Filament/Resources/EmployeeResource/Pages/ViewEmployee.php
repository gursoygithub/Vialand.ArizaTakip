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
                    ->columns(3)
                    ->compact()
                    ->schema([
                        TextEntry::make('name')
                            ->label(__('ui.name'))
                            ->helperText(fn($record): ?string => $record->title ?? '-')
                            ->icon('heroicon-o-user')
                            ->weight('bold'),

                        TextEntry::make('email')
                            ->label(__('ui.email'))
                            ->icon('heroicon-o-envelope'),

                        TextEntry::make('phone')
                            ->label(__('ui.phone'))
                            ->icon('heroicon-o-phone'),
                    ]),

                // 2. BÖLÜM: PERFORMANS ANALİZİ (YÖNETİCİ ÖZETİ)
                Infolists\Components\Section::make('SLA Performans Analizi')
                    ->icon('heroicon-o-presentation-chart-line')
                    ->columns(4)
                    ->schema([
                        TextEntry::make('performance_score')
                            ->label('SLA Başarı Oranı')
                            ->numeric(2)
                            ->suffix('%')
                            ->weight('bold')
                            ->color(fn ($record) => $record->performance_score >= $record->current_threshold ? 'success' : 'danger'),

                        TextEntry::make('current_threshold')
                            ->label('SLA Hedef Oranı')
                            ->numeric(2)
                            ->suffix('%'),

                        TextEntry::make('tasks_count')
                            ->label('Tamamlanan İş Hacmi')
                            ->getStateUsing(fn ($record) => $record->tasks()->whereIn('sla_outcome', ['SUCCESS', 'FAILED'])->count() . ' Görev')
                            ->color('info'),

                        TextEntry::make('status')
                            ->label('Genel Yeterlilik')
                            ->getStateUsing(fn ($record) => $record->performance_score >= $record->current_threshold ? 'SLA UYUMLU' : 'GELİŞTİRİLMELİ')
                            ->badge()
                            ->color(fn ($record) => $record->performance_score >= $record->current_threshold ? 'success' : 'danger'),
                    ]),

                // 3. BÖLÜM: BİRİM BAZLI DETAYLI DAĞILIM
                Infolists\Components\Section::make('Birim Bazlı SLA Dağılımı')
                    ->description('Personelin hangi birimde ne kadar başarılı olduğunun dökümü.')
                    ->icon('heroicon-o-rectangle-group')
                    ->schema([
                        Infolists\Components\RepeatableEntry::make('unit_performance')
                            ->label('') // Label'ı boş bırakabiliriz section başlığı var zaten
                            ->getStateUsing(function ($record) {
                                return $record->tasks()
                                    ->whereIn('sla_outcome', ['SUCCESS', 'FAILED'])
                                    ->selectRaw('
                                    unit_id, 
                                    COUNT(*) as total_tasks, 
                                    COUNT(CASE WHEN sla_outcome = "SUCCESS" THEN 1 END) as success_count,
                                    (COUNT(CASE WHEN sla_outcome = "SUCCESS" THEN 1 END) * 100.0 / NULLIF(COUNT(*), 0)) as success_rate
                                ')
                                    ->groupBy('unit_id')
                                    ->with('unit')
                                    ->get()
                                    ->map(function ($stat) {
                                        return [
                                            'unit_name' => $stat->unit?->name ?? 'Tanımsız Birim',
                                            'stats' => "{$stat->success_count} / {$stat->total_tasks}",
                                            'percentage' => round($stat->success_rate, 1) . '%',
                                        ];
                                    });
                            })
                            ->columns(3)
                            ->grid(2)
                            ->schema([
                                TextEntry::make('unit_name')
                                    ->label('Birim')
                                    ->weight('bold'),

                                TextEntry::make('stats')
                                    ->label('Başarı / Toplam')
                                    ->color('gray'),

                                TextEntry::make('percentage')
                                    ->label('Oran')
                                    ->badge()
                                    ->color(fn ($state) =>
                                    floatval($state) >= 80 ? 'success' : (floatval($state) >= 50 ? 'warning' : 'danger')
                                    ),
                            ]),
                    ]),

                // 4. BÖLÜM: ÖNCELİK BAZLI SLA DAĞILIMI
                Infolists\Components\Section::make('Öncelik Bazlı SLA Dağılımı')
                    ->description('Personelin görev önceliklerine göre performans dökümü.')
                    ->icon('heroicon-o-funnel')
                    ->schema([
                        Infolists\Components\RepeatableEntry::make('priority_performance')
                            ->label('')
                            ->getStateUsing(function ($record) {
                                return $record->tasks()
                                    ->whereIn('sla_outcome', ['SUCCESS', 'FAILED'])
                                    ->selectRaw('
                                        priority, 
                                        COUNT(*) as total_tasks, 
                                        COUNT(CASE WHEN sla_outcome = "SUCCESS" THEN 1 END) as success_count,
                                        (COUNT(CASE WHEN sla_outcome = "SUCCESS" THEN 1 END) * 100.0 / NULLIF(COUNT(*), 0)) as success_rate
                                    ')
                                    ->groupBy('priority')
                                    ->get()
                                    ->map(function ($stat) {
                                        // Priority Enum objesini alıyoruz (getLabel, getColor vb. kullanabilmek için)
                                        $priorityEnum = $stat->priority instanceof \App\Enums\TaskPriorityEnum
                                            ? $stat->priority
                                            : \App\Enums\TaskPriorityEnum::tryFrom($stat->priority);

                                        return [
                                            'priority_label' => $priorityEnum?->getLabel() ?? 'Bilinmiyor',
                                            'priority_color' => $priorityEnum?->getColor() ?? 'gray',
                                            'stats' => "{$stat->success_count} / {$stat->total_tasks}",
                                            'percentage' => round($stat->success_rate, 1) . '%',
                                            'raw_percentage' => $stat->success_rate,
                                        ];
                                    });
                            })
                            ->columns(3)
                            ->grid(2)
                            ->schema([
                                Infolists\Components\TextEntry::make('priority_label')
                                    ->label('Görev Önceliği')
                                    ->weight('bold')
                                    ->badge()
                                    ->color(fn ($record) => $record['priority_color']),

                                Infolists\Components\TextEntry::make('stats')
                                    ->label('Başarı / Toplam')
                                    ->icon('heroicon-m-clipboard-document-check')
                                    ->color('gray'),

                                Infolists\Components\TextEntry::make('percentage')
                                    ->label('Başarı Oranı')
                                    ->badge()
                                    ->color(fn ($state) =>
                                    floatval($state) >= 80 ? 'success' : (floatval($state) >= 50 ? 'warning' : 'danger')
                                    ),
                            ]),
                    ]),

                Infolists\Components\Section::make('Öncelik ve Birim Analizi')
                    ->hidden()
                    ->description('Personelin görev önceliklerinin birimlere göre dağılımı.')
                    ->icon('heroicon-o-funnel')
                    ->schema([
                        Infolists\Components\RepeatableEntry::make('priority_unit_performance')
                            ->label('')
                            ->getStateUsing(function ($record) {
                                return $record->tasks()
                                    ->join('units', 'tasks.unit_id', '=', 'units.id') // Birim tablosunu bağlıyoruz
                                    ->whereIn('sla_outcome', ['SUCCESS', 'FAILED'])
                                    ->selectRaw('
                                            tasks.priority, 
                                            tasks.unit_id,
                                            units.name as unit_name,
                                            COUNT(tasks.id) as total_tasks, 
                                            COUNT(CASE WHEN tasks.sla_outcome = "SUCCESS" THEN 1 END) as success_count,
                                            (COUNT(CASE WHEN tasks.sla_outcome = "SUCCESS" THEN 1 END) * 100.0 / NULLIF(COUNT(tasks.id), 0)) as success_rate
                                        ')
                                    ->groupBy('tasks.priority', 'tasks.unit_id', 'units.name')
                                    ->get()
                                    ->map(function ($stat) {
                                        $priorityEnum = $stat->priority instanceof \App\Enums\TaskPriorityEnum
                                            ? $stat->priority
                                            : \App\Enums\TaskPriorityEnum::tryFrom($stat->priority);

                                        return [
                                            'unit_display_name' => $stat->unit_name ?? 'Genel',
                                            'priority_label' => $priorityEnum?->getLabel() ?? 'Bilinmiyor',
                                            'priority_color' => $priorityEnum?->getColor() ?? 'gray',
                                            'stats' => "{$stat->success_count} / {$stat->total_tasks}",
                                            'percentage' => round($stat->success_rate, 1) . '%',
                                        ];
                                    });
                            })
                            ->columns(3)
                            ->grid(2)
                            ->schema([
                                Infolists\Components\TextEntry::make('unit_display_name')
                                    ->label('Birim')
                                    ->weight('bold')
                                    ->color('primary'),

                                Infolists\Components\TextEntry::make('priority_label')
                                    ->label('Öncelik')
                                    ->badge()
                                    ->color(fn ($record) => $record['priority_color']),

                                Infolists\Components\TextEntry::make('percentage')
                                    ->label('Başarı Oranı')
                                    ->badge()
                                    ->hint(fn($record) => $record['stats']) // Kaçta kaç olduğunu sağ üstte gösterir
                                    ->color(fn ($state) =>
                                    floatval($state) >= 80 ? 'success' : (floatval($state) >= 50 ? 'warning' : 'danger')
                                    ),
                            ]),
                    ]),
            ]);
    }

//    public function infolist(Infolist $infolist): Infolist
//    {
//        return $infolist
//            ->schema([
//                // 1. BÖLÜM: PERSONEL KİMLİK BİLGİLERİ
//                Infolists\Components\Section::make(__('ui.employee'))
//                    ->icon('heroicon-o-user-circle')
//                    ->description(__('ui.employee_details'))
//                    ->columns(3)
//                    ->compact()
//                    ->schema([
//                        TextEntry::make('name')
//                            ->label(__('ui.name'))
//                            ->helperText(fn($record): ?string => $record->title ?? '-')
//                            ->icon('heroicon-o-user')
//                            ->size('lg')
//                            ->weight('bold'),
//
//                        TextEntry::make('email')
//                            ->label(__('ui.email'))
//                            ->icon('heroicon-o-envelope'),
//
//                        TextEntry::make('phone')
//                            ->label(__('ui.phone'))
//                            ->icon('heroicon-o-phone')
//                            ->placeholder('—'),
//                    ]),
//
//                // 2. BÖLÜM: PERFORMANS ANALİZİ (YÖNETİCİ ÖZETİ)
//                Infolists\Components\Section::make('SLA Performans Analizi')
//                    ->description('Personelin başarı oranları ve iş yükü dağılımı.')
//                    ->icon('heroicon-o-presentation-chart-line')
//                    ->columns(4)
//                    ->schema([
//                        // Mevcut Başarı Oranı
//                        TextEntry::make('performance_score')
//                            ->label('SLA Başarı Oranı')
//                            ->numeric(2)
//                            ->suffix('%')
//                            ->weight('bold')
//                            ->color(fn ($record) => $record->performance_score >= $record->current_threshold ? 'success' : 'danger'),
//
//                        // Hedef (Baraj)
//                        TextEntry::make('current_threshold')
//                            ->label('SLA Hedef Oranı')
//                            ->numeric(2)
//                            ->suffix('%')
//                            ->color('gray'),
//
//                        // İş Yükü Hacmi (Kaç görev üzerinden bu puanı aldı?)
//                        TextEntry::make('tasks_count')
//                            ->label('Tamamlanan İş Hacmi')
//                            ->getStateUsing(fn ($record) => $record->tasks()->whereIn('sla_outcome', ['SUCCESS', 'FAILED'])->count() . ' Görev')
//                            ->icon('heroicon-o-briefcase')
//                            ->color('info'),
//
//                        // Görsel Durum Etiketi
//                        TextEntry::make('status')
//                            ->label('Genel Yeterlilik')
//                            ->getStateUsing(fn ($record) => $record->performance_score >= $record->current_threshold ? 'SLA UYUMLU' : 'GELİŞTİRİLMELİ')
//                            ->badge()
//                            ->color(fn ($record) => $record->performance_score >= $record->current_threshold ? 'success' : 'danger')
//                            ->icon(fn ($record) => $record->performance_score >= $record->current_threshold ? 'heroicon-m-check-badge' : 'heroicon-m-exclamation-triangle'),
//                    ]),
//
//                // 3. BÖLÜM: DETAYLI İSTATİSTİKLER (BAŞARI VE HIZ)
//                Infolists\Components\Section::make('Görev Detay Analizi')
//                    ->columns(3)
//                    ->compact()
//                    ->schema([
//                        TextEntry::make('success_tasks')
//                            ->label('Zamanında Kapatılan')
//                            ->getStateUsing(fn($record) => $record->tasks()->where('sla_outcome', 'SUCCESS')->count())
//                            ->icon('heroicon-o-check-circle')
//                            ->color('success'),
//
//                        TextEntry::make('failed_tasks')
//                            ->label('Süresi Geçen')
//                            ->getStateUsing(fn($record) => $record->tasks()->where('sla_outcome', 'FAILED')->count())
//                            ->icon('heroicon-o-clock')
//                            ->color('danger'),
//
//                        TextEntry::make('discipline_note')
//                            ->label('Disiplin Notu')
//                            ->getStateUsing(fn($record) => $record->performance_score > 80 ? 'Yüksek' : 'Takip Gerekiyor')
//                            ->weight('bold')
//                            ->color(fn($record) => $record->performance_score > 60 ? 'info' : 'warning'),
//                    ]),
//            ]);
//    }
}