<?php

namespace App\Filament\Exports;

use App\Helpers\DateHelper;
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
        $canViewAllTasks = auth()->user()->can('view_all_tasks');

        $columns = [];

        // Diğer sütunlar
        $columns = array_merge($columns, [
//            ExportColumn::make('title')
//                ->label(__('ui.task_title')),
            ExportColumn::make('type_id')
                ->label(__('ui.type'))
                ->formatStateUsing(fn ($state): ?string =>
                $state instanceof \App\Enums\TaskTypeEnum
                    ? $state->getLabel()
                    : (string) $state
                ),
            ExportColumn::make('area.name')
                ->label(__('ui.area')),
            ExportColumn::make('subArea.name')
                ->label(__('ui.sub_area')),
            ExportColumn::make('unit.name')
                ->label(__('ui.unit')),
            ExportColumn::make('task_date')
                ->label(__('ui.task_date'))
                ->formatStateUsing(fn ($state) => DateHelper::formatForExport($state, 'd F Y')),
            ExportColumn::make('description')
                ->label(__('ui.description')),
            ExportColumn::make('unit_description')
                ->label(__('ui.unit_description')),
            ExportColumn::make('status')
                ->label(__('ui.status'))
                ->formatStateUsing(fn ($state): ?string => $state instanceof \App\Enums\TaskStatusEnum ? $state->getLabel() : (string) $state),
//            ExportColumn::make('employee.name')
//                ->label(__('ui.assigned_to')),
            ExportColumn::make('completedBy.name')
                ->label(__('ui.closed_by')),
            ExportColumn::make('due_date')
                ->label(__('ui.due_date'))
                ->formatStateUsing(fn ($state) => DateHelper::formatForExport($state, 'd F Y')),
            ExportColumn::make('resolution_notes')
                ->label(__('ui.resolution_notes')),
        ]);

        if ($isSuperAdmin || $canViewAllTasks) {
            $columns[] = ExportColumn::make('createdBy.name')
                ->label(__('ui.created_by'));
        }

        $columns[] = ExportColumn::make('created_at')
            ->label(__('ui.created_at'))
            ->formatStateUsing(fn ($state) => DateHelper::formatForExport($state, 'd F Y - H:i'));

//        $columns[] = ExportColumn::make('updatedBy.name')
//            ->label(__('ui.last_updated_by'));
//
//        $columns[] = ExportColumn::make('updated_at')
//            ->label(__('ui.updated_at'))
//            ->formatStateUsing(function ($state, $record) {
//                // 1. Güncelleyen kişinin olup olmadığını kontrol et.
//                // Eğer 'updatedBy' ilişkisi NULL ise (yani updated_by_id boşsa),
//                // veya updated_at alanı NULL ise, boş döndür.
//                if (is_null($record->updatedBy) || is_null($state)) {
//                    return '';
//                }
//
//                // 2. Güncelleyen kişi varsa, tarihi formatla.
//                return DateHelper::formatForExport($state, 'd F Y - H:i');
//            });

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
