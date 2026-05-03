<?php

namespace App\Repositories\Contracts;

use App\Models\Ticket;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

interface TicketRepositoryInterface
{
    public function paginate(array $filters = [], int $perPage = 20): LengthAwarePaginator;

    public function findByTicketNo(string $ticketNo): ?Ticket;

    public function openBreached(): Collection;
}
