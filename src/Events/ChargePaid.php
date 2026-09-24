<?php

declare(strict_types=1);

namespace VeliraPay\Laravel\Events;

/**
 * A charge's payment is confirmed, so its order can be fulfilled.
 */
final class ChargePaid extends ChargeEvent {}
