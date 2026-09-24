<?php

declare(strict_types=1);

namespace VeliraPay\Laravel\Events;

use VeliraPay\Resources\Charge;
use VeliraPay\Webhooks\WebhookEvent;

/**
 * A webhook about a charge.
 */
abstract class ChargeEvent
{
    /**
     * Create a new event.
     */
    final public function __construct(
        /** The webhook delivery. */
        public readonly WebhookEvent $webhook,
        /** The charge, as it was when the event happened. */
        public readonly Charge $charge,
    ) {
        //
    }
}
