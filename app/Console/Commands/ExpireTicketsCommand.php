<?php

namespace App\Console\Commands;

use App\Services\TicketExpirationService;
use Illuminate\Console\Command;

class ExpireTicketsCommand extends Command
{
    protected $signature = 'tickets:expire';

    protected $description =
        'Expire active tickets when their event has ended';

    public function handle(
        TicketExpirationService $ticketExpirationService
    ): int {

        try {

            $expired =
                $ticketExpirationService->expireTickets();

            $this->info(
                "Successfully expired {$expired} ticket(s)."
            );

            return self::SUCCESS;

        } catch (\Throwable $e) {

            report($e);

            $this->error(
                'Failed to expire tickets: '.
                $e->getMessage()
            );

            return self::FAILURE;
        }
    }
}
