<?php

namespace App\Repositories;

use App\Models\Ticket;
use App\Repositories\Contracts\TicketRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class TicketRepository implements TicketRepositoryInterface
{
    public function paginate(array $filters = [], int $perPage = 20): LengthAwarePaginator
    {
        return Ticket::query()
            ->when($filters['area_id'] ?? null, fn ($q, $v) => $q->where('area_id', $v))
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filters['priority'] ?? null, fn ($q, $v) => $q->where('priority', $v))
            ->latest()
            ->paginate($perPage);
    }

    public function findByTicketNo(string $ticketNo): ?Ticket
    {
        return Ticket::where('ticket_no', $ticketNo)->first();
    }

    public function openBreached(): Collection
    {
        return Ticket::query()->slaBreached()->get();
    }
}
