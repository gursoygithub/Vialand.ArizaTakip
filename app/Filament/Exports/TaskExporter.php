<?php

namespace App\Filament\Exports;

use App\Models\Task;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;

class TaskExporter extends Exporter
{
    protected static ?string $model = Task::class;

    public static function getColumns(): array
    {
        $isSuperAdmin = auth()->user()->hasRole('super_admin');
        $canViewTcNo = auth()->user()->can('view_tc_no');

        $columns = [];

        // TC No sütunu sadece süper admin veya ilgili izne sahip kullanıcılar için gösterilir
        // ve dizinin başına eklenir
//        if ($isSuperAdmin || $canViewTcNo) {
//            $columns[] = ExportColumn::make('tc_no')
//                ->label(__('ui.tc_no'));
//        }

        // Diğer sütunlar
        $columns = array_merge($columns, [
            ExportColumn::make('title')
                ->label(__('ui.task_title')),
            ExportColumn::make('area.name')
                ->label(__('ui.area')),
            ExportColumn::make('subArea.name')
                ->label(__('ui.sub_area')),
            ExportColumn::make('unit.name')
                ->label(__('ui.unit')),
            ExportColumn::make('type_id')
                ->label(__('ui.type'))
                ->formatStateUsing(fn ($state): ?string =>
                    $state instanceof \App\Enums\TaskTypeEnum
                        ? $state->getLabel()
                        : (string) $state
                ),
            ExportColumn::make('description')
                ->label(__('ui.description')),
            ExportColumn::make('status')
                ->label(__('ui.status'))
                ->formatStateUsing(fn ($state): ?string =>
                    $state instanceof \App\Enums\TaskStatusEnum
                        ? $state->getLabel()
                        : (string) $state
                ),
            ExportColumn::make('employee.name')
                ->label(__('ui.assigned_to')),
            ExportColumn::make('task_date')
                ->label(__('ui.task_date'))
                ->formatStateUsing(fn ($state) => date('d.m.Y', strtotime($state))),
            ExportColumn::make('due_date')
                ->label(__('ui.due_date'))
                ->formatStateUsing(fn ($state) => $state ? date('d.m.Y H:i:s', strtotime($state)) : ''),
            ExportColumn::make('resolution_notes')
                ->label(__('ui.resolution_notes')),




//            ExportColumn::make('full_name')
//                ->label(__('ui.full_name')),
//            ExportColumn::make('department_name')
//                ->label(__('ui.department')),
//            ExportColumn::make('position_name')
//                ->label(__('ui.position')),
//            ExportColumn::make('date')
//                ->label(__('ui.date'))
//                ->formatStateUsing(fn ($state) => date('d.m.Y', strtotime($state))),
//            ExportColumn::make('first_reading')
//                ->label(__('ui.first_reading'))
//                ->formatStateUsing(fn ($state) => $state ? date('H:i:s', strtotime($state)) : ''),
//            ExportColumn::make('last_reading')
//                ->label(__('ui.last_reading'))
//                ->formatStateUsing(fn ($state) => $state ? date('H:i:s', strtotime($state)) : ''),
//            ExportColumn::make('working_time')
//                ->label(__('ui.working_time')),
//            ExportColumn::make('status')
//                ->label(__('ui.status'))
//                ->formatStateUsing(fn ($state): ?string =>
//                $state instanceof \App\Enums\ManagerStatusEnum
//                    ? $state->getLabel()
//                    : (string) $state
//                ),
        ]);

        return $columns;
    }

    public static function getCompletedNotificationBody(Export $export): string
    {
        $rows = number_format($export->successful_rows);
        $body = $rows . ' veri dışa aktarılmaya hazır.';

        if ($failedRowsCount = $export->getFailedRowsCount()) {
            $failedRows = number_format($failedRowsCount);
            $body .= ' ' . $failedRows . ' veri dışa aktarılamadı.';
        }

        return $body;
    }
}
