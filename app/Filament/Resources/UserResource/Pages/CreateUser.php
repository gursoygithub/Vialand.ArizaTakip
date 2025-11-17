<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Enums\TaskStatusEnum;
use App\Filament\Resources\UserResource;
use App\Models\Employee;
use App\Models\User;
use App\Notifications\UserCreated;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\DB;

class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['created_by'] = auth()->user()->id;

        if (isset($data['employee_id'])) {
            $employee = DB::connection('sqlsrv2')
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
        } else {
            session()->flash('error', 'Çalışan ID boş olamaz.');
        }

        // create a alfanumeric random password
        //$password = substr(str_shuffle('ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789!@#$%^&*'), 0, 10);
        //$password = 'password';

        //$password = $data['tc_no'];
        //$password = substr($data['tc_no'], -4);

        $password = substr($data['phone'], -8);

        $data['password'] = bcrypt($password);

        return $data;
    }
}
