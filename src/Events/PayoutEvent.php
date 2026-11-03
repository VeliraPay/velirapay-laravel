<?php

declare(strict_types=1);

namespace VeliraPay\Laravel\Events;

use VeliraPay\Resources\Charge;
use VeliraPay\Resources\Payout;
use VeliraPay\Webhooks\WebhookEvent;

/**
 * A webhook about the payout of a charge.
 */
abstract class PayoutEvent
{
    /**
     * Create a new event.
     */
    final public function __construct(
        /** The webhook delivery. */
        public readonly WebhookEvent $webhook,
        /** The charge the payout came from, as it was when the event happened. */
        public readonly Charge $charge,
        /** The payout, as it was when the event happened. */
        public readonly Payout $payout,
    ) {
        //
    }
}
