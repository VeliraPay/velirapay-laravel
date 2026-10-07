<?php

declare(strict_types=1);

namespace VeliraPay\Laravel\Events;

/**
 * A charge was canceled from the dashboard or the API, or replaced when the customer switched coin or its invoice was voided.
 */
final class ChargeCanceled extends ChargeEvent {}
