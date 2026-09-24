<?php

declare(strict_types=1);

namespace VeliraPay\Laravel\Events;

/**
 * A charge was canceled from the dashboard or the API.
 */
final class ChargeCanceled extends ChargeEvent {}
