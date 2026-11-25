<?php

namespace App\Filament\Resources\TeamTicketResource\Pages;

use App\Enums\TaskPriorityEnum;
use App\Exceptions\TicketWorkflowException;
use App\Filament\Resources\TeamTicketResource;
use App\Models\Team;
use App\Models\TicketType;
use App\Services\TicketWorkflowService;
use App\Support\RichText;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class CreateTeamTicket extends CreateRecord
{
    protected static string $resource = TeamTicketResource::class;

    protected static bool $canCreateAnother = false;

    public function getTitle(): string
    {
        return __('ui.new_request');
    }

    public function mount(): void
    {
        parent::mount();

        $type = request()->integer('type');

        if ($type && TicketType::active()->whereKey($type)->exists()) {
            $this->form->fill(['ticket_type_id' => $type]);
        }
    }

    /**
     * Opening goes through the workflow service so the SLA cycle starts and
     * every rule (active team, required fields) is enforced server-side.
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $team = Team::query()->active()->find($data['team_id'] ?? null);

        if (! $team || (int) $team->ticket_type_id !== (int) ($data['ticket_type_id'] ?? 0)) {
            throw ValidationException::withMessages(['data.team_id' => __('ui.request_team_required')]);
        }

        $allowedKeys = collect($team->ticketType->form_fields ?? [])->pluck('key')->all();
        $customFields = array_intersect_key($data['custom_fields'] ?? [], array_flip($allowedKeys));
        $priority = $data['priority'] instanceof TaskPriorityEnum
            ? $data['priority']
            : TaskPriorityEnum::from((int) $data['priority']);

        try {
            return app(TicketWorkflowService::class)->open(
                auth()->user(),
                $team,
                $priority,
                RichText::clean($data['description']),
                $customFields,
                array_filter([
                    'area_id' => $data['area_id'] ?? null,
                    'sub_area_id' => $data['sub_area_id'] ?? null,
                ]),
                $data['watchers'] ?? [],
            );
        } catch (TicketWorkflowException $exception) {
            throw ValidationException::withMessages(['data.'.($exception->field ?? 'team_id') => $exception->getMessage()]);
        }
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return __('ui.request_created', ['no' => $this->getRecord()->ticket_no]);
    }
}
