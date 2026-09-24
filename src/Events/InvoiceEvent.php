<?php

declare(strict_types=1);

namespace VeliraPay\Laravel\Events;

use VeliraPay\Resources\Invoice;
use VeliraPay\Webhooks\WebhookEvent;

/**
 * A webhook about an invoice.
 */
abstract class InvoiceEvent
{
    /**
     * Create a new event.
     */
    final public function __construct(
        /** The webhook delivery. */
        public readonly WebhookEvent $webhook,
        /** The invoice, as it was when the event happened. */
        public readonly Invoice $invoice,
    ) {
        //
    }
}
