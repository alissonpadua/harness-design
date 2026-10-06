<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Billing\ProcessWebhookEvent;
use App\Contracts\Billing\PaymentGateway;
use Illuminate\Console\Command;

/**
 * Dev fallback when no public webhook URL exists (no CLI/tunnel): pull recent
 * gateway events and replay them through the exact production pipeline.
 */
final class BillingIngestCommand extends Command
{
    protected $signature = 'billing:ingest';

    protected $description = 'Pull recent gateway events and replay them through the webhook pipeline';

    public function handle(PaymentGateway $gateway, ProcessWebhookEvent $process): int
    {
        $events = $gateway->pullRecentEvents();
        $this->info(sprintf('Pulling events from "%s" gateway (%d found)...', $gateway->name(), count($events)));

        foreach ($events as $event) {
            $result = $process->handle($gateway->name(), (array) $event);
            $this->line(sprintf('  %-38s %-12s %s', $result->type, $result->outcome, $result->eventId));
        }

        return self::SUCCESS;
    }
}
