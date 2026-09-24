<?php

declare(strict_types=1);

namespace VeliraPay\Laravel\Events;

/**
 * A refund to the customer was recorded on a charge.
 */
final class ChargeRefunded extends ChargeEvent {}
