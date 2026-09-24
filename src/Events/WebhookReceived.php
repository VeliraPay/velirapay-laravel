<?php

declare(strict_types=1);

namespace VeliraPay\Laravel\Events;

use VeliraPay\Webhooks\WebhookEvent;

/**
 * A webhook delivery passed signature verification, whatever its type.
 */
final class WebhookReceived
{
    /**
     * Create a new event.
     */
    public function __construct(
        /** The webhook delivery. */
        public readonly WebhookEvent $webhook,
    ) {
        //
    }
}
