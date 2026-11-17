<?php

namespace App\Services;

use App\Enums\ExemptionReasonEnum;
use App\Enums\ExemptionStatusEnum;
use App\Exceptions\TicketWorkflowException;
use App\Models\SlaExemption;
use App\Models\TicketSlaCycle;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;

/**
 * SLA exemptions (decision 8). Whoever carries a breach — the person, or the
 * team supervisor — may ask for it to be exempted with an agreed reason. The
 * decision belongs to someone outside the team holding
 * `sla.exemption.approve`; nobody can clear their own or their team's breach.
 * An approved exemption takes the cycle out of every performance figure.
 */
class SlaExemptionService
{
    public const PERMISSION_APPROVE = 'sla.exemption.approve';

    public function __construct(private TeamTicketNotifier $notifier) {}

    public function canRequest(TicketSlaCycle $cycle, User $user): bool
    {
        if (! $cycle->resolution_breached || $cycle->exempted || $this->hasOpenOrApproved($cycle)) {
            return false;
        }

        if ($user->hasRole('super_admin')) {
            return true;
        }

        $employeeId = $user->employee?->id;

        return $employeeId !== null && (
            (int) $cycle->breach_employee_id === (int) $employeeId
            || (int) $cycle->team?->supervisor_employee_id === (int) $employeeId
        );
    }

    public function request(TicketSlaCycle $cycle, User $user, ExemptionReasonEnum $reason, string $note): SlaExemption
    {
        if (trim($note) === '') {
            throw TicketWorkflowException::reasonRequired();
        }

        if (! $this->canRequest($cycle, $user)) {
            throw TicketWorkflowException::notAllowed();
        }

        $exemption = SlaExemption::create([
            'ticket_sla_cycle_id' => $cycle->id,
            'ticket_id' => $cycle->ticket_id,
            'requested_by' => $user->id,
            'reason' => $reason,
            'note' => $note,
        ]);

        $this->notifier->exemptionRequested($exemption, $this->approversFor($exemption));

        return $exemption;
    }

    /**
     * Independent of the breach: holds the permission and is neither the
     * requester, the person charged, the team supervisor nor a team member.
     */
    public function canDecide(SlaExemption $exemption, User $user): bool
    {
        if ($exemption->status !== ExemptionStatusEnum::Pending) {
            return false;
        }

        if (! $user->hasRole('super_admin') && ! $user->can(self::PERMISSION_APPROVE)) {
            return false;
        }

        if ((int) $exemption->requested_by === (int) $user->id) {
            return false;
        }

        $employeeId = $user->employee?->id;

        if ($employeeId === null) {
            return true;
        }

        $cycle = $exemption->cycle;
        $team = $cycle->team;

        return (int) $cycle->breach_employee_id !== (int) $employeeId
            && (int) $team->supervisor_employee_id !== (int) $employeeId
            && ! $team->members()->whereKey($employeeId)->exists();
    }

    public function approve(SlaExemption $exemption, User $user, ?string $note = null): SlaExemption
    {
        return $this->decide($exemption, $user, ExemptionStatusEnum::Approved, $note);
    }

    public function reject(SlaExemption $exemption, User $user, string $note): SlaExemption
    {
        if (trim($note) === '') {
            throw TicketWorkflowException::reasonRequired();
        }

        return $this->decide($exemption, $user, ExemptionStatusEnum::Rejected, $note);
    }

    /**
     * @return Collection<int, User>
     */
    public function approversFor(SlaExemption $exemption): Collection
    {
        if (! Permission::query()->where('name', self::PERMISSION_APPROVE)->exists()) {
            return collect();
        }

        return User::permission(self::PERMISSION_APPROVE)->get()
            ->filter(fn (User $user): bool => $this->canDecide($exemption, $user))
            ->values();
    }

    private function decide(SlaExemption $exemption, User $user, ExemptionStatusEnum $status, ?string $note): SlaExemption
    {
        if (! $this->canDecide($exemption, $user)) {
            throw TicketWorkflowException::notAllowed();
        }

        $exemption = DB::transaction(function () use ($exemption, $user, $status, $note): SlaExemption {
            $exemption->update([
                'status' => $status,
                'decided_by' => $user->id,
                'decided_at' => CarbonImmutable::now(),
                'decision_note' => $note,
            ]);

            if ($status === ExemptionStatusEnum::Approved) {
                $exemption->cycle->update(['exempted' => true]);
            }

            return $exemption->fresh();
        });

        $this->notifier->exemptionDecided($exemption);

        return $exemption;
    }

    private function hasOpenOrApproved(TicketSlaCycle $cycle): bool
    {
        return $cycle->exemptions()
            ->whereIn('status', [ExemptionStatusEnum::Pending, ExemptionStatusEnum::Approved])
            ->exists();
    }
}
