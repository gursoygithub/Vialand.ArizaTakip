<?php

namespace App\Filament\Resources\TaskResource\Pages;

use App\Enums\TaskStatusEnum;
use App\Filament\Resources\TaskResource;
use App\Models\Technician;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CreateTask extends CreateRecord
{
    protected static string $resource = TaskResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', [
            'record' => $this->record,
        ]);
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['created_by'] = auth()->id();
        $data['user_id'] = auth()->id();

        // if is_winter_maintenance is true, set status to 1
        if (isset($data['is_winter_maintenance']) && $data['is_winter_maintenance']) {
            $data['status'] = TaskStatusEnum::WINTER_MAINTENANCE;
        }

        return $data;
    }
}
