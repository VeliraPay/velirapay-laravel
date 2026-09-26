<?php

declare(strict_types=1);

namespace VeliraPay\Laravel\Events;

use VeliraPay\Resources\Charge;
use VeliraPay\Resources\Transaction;
use VeliraPay\Webhooks\WebhookEvent;

/**
 * A webhook about a charge.
 */
abstract class ChargeEvent
{
    /**
     * The transfer the event is about, for ChargePaymentDetected and ChargeLatePayment.
     */
    public readonly ?Transaction $transaction;

    /**
     * Create a new event.
     */
    final public function __construct(
        /** The webhook delivery. */
        public readonly WebhookEvent $webhook,
        /** The charge, as it was when the event happened. */
        public readonly Charge $charge,
    ) {
        $this->transaction = $webhook->transaction;
    }
}
