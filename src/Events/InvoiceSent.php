<?php

declare(strict_types=1);

namespace VeliraPay\Laravel\Events;

/**
 * An invoice, or a reminder of it, was emailed to the customer.
 */
final class InvoiceSent extends InvoiceEvent {}
