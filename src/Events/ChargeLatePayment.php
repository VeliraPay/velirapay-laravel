<?php

declare(strict_types=1);

namespace VeliraPay\Laravel\Events;

/**
 * Funds arrived after a charge expired or was canceled, or on top of a charge already paid.
 */
final class ChargeLatePayment extends ChargeEvent {}
