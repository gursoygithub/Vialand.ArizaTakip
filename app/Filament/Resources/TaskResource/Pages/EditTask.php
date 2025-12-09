<?php

namespace App\Filament\Resources\TaskResource\Pages;

use App\Enums\TaskStatusEnum;
use App\Filament\Resources\TaskResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditTask extends EditRecord
{
    protected static string $resource = TaskResource::class;

    //redirect to view page after edit
    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', [
            'record' => $this->record,
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['updated_by'] = auth()->id();

        // if status is not completed, set completed_by to null, due_date to null, resolution_notes to null
        if (isset($data['status']) && $data['status'] != TaskStatusEnum::COMPLETED->value) {
            $data['completed_by'] = null;
            $data['due_date'] = null;
            $data['resolution_notes'] = null;
        } else {
            $data['completed_by'] = $this->record->completed_by ?? auth()->id();
        }

        return $data;
    }
}
