<?php

declare(strict_types=1);

namespace VeliraPay\Laravel\Events;

/**
 * A confirmed payment to a charge fell short of the underpayment tolerance.
 */
final class ChargeUnderpaid extends ChargeEvent {}
