<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Resources\UserResource;
use App\Models\Employee;
use App\Models\Report;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\ViewAction::make(),
            Actions\DeleteAction::make(),
//                ->hidden(fn ($record) =>
//                    $record->id === auth()->user()->id ||
//                    $record->cards()->count() > 0 ||
//                    $record->visitors()->count() > 0 ||
//                    $record->visitorCards()->count() > 0),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('view', [
            'record' => $this->record,
        ]);
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['updated_by'] = auth()->user()->id;

        // update employee info from sqlsrv2 if employee_id changed
        if (isset($data['employee_id']) && $data['employee_id'] !== $this->record->employee_id) {
            $employee = \Illuminate\Support\Facades\DB::connection('sqlsrv2')
                ->table('dbo._TGRY_PERSONEL')
                ->where('UNIQUE_ID', $data['employee_id'])
                ->where('AKTIF_MI', 1)
                ->first();

            if ($employee) {
                $data['employee_id'] = $employee->UNIQUE_ID;
                $data['tc_no'] = $employee->TC_KIMLIK_NO;
                $data['name'] = $employee->ADI . ' ' . $employee->SOYADI;
                $data['title'] = $employee->UNVANI;
                $data['profession'] = $employee->MESLEGI;
                $data['email'] = $employee->E_POSTA;
                $data['phone'] = str_replace(' ', '', $employee->GSM_NO);
                $data['status'] = $employee->AKTIF_MI;
            } else {
                // uyarı ver
                session()->flash('error', 'Çalışan bulunamadı veya aktif değil.');
            }
        }

        return $data;
    }
}
