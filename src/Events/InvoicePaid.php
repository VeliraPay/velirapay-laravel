<?php

declare(strict_types=1);

namespace VeliraPay\Laravel\Events;

/**
 * One of an invoice's charges was paid, which settles it.
 */
final class InvoicePaid extends InvoiceEvent {}
