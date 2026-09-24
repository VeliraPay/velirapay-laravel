<?php

declare(strict_types=1);

namespace VeliraPay\Laravel\Events;

/**
 * A charge's rate lock ran out before the customer paid.
 */
final class ChargeExpired extends ChargeEvent {}
