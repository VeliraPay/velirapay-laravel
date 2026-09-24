<?php

declare(strict_types=1);

namespace VeliraPay\Laravel\Events;

/**
 * A transfer to a charge was seen on-chain and is gathering confirmations.
 */
final class ChargePaymentDetected extends ChargeEvent {}
