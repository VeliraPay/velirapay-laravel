<?php

declare(strict_types=1);

namespace VeliraPay\Laravel\Events;

/**
 * An invoice was withdrawn and can no longer be paid.
 */
final class InvoiceVoided extends InvoiceEvent {}
