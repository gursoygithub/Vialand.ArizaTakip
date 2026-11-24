<?php

namespace App\Filament\Resources\EmployeeResource\Pages;

use App\Exports\EmployeeEmailIssuesExport;
use App\Filament\Resources\EmployeeResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Read-only list (records come from the HR sync). The only header action
 * hands HR the people whose e-mail needs fixing.
 */
class ListEmployees extends ListRecords
{
    protected static string $resource = EmployeeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('emailIssues')
                ->label(__('ui.email_issues_export'))
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->tooltip(__('ui.email_issues_export_help'))
                ->action(fn (): BinaryFileResponse => Excel::download(
                    new EmployeeEmailIssuesExport,
                    'personel-eposta-sorunlari-'.now()->format('Y-m-d').'.xlsx',
                )),
        ];
    }
}
