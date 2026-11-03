<?php

declare(strict_types=1);

namespace VeliraPay\Laravel\Events;

/**
 * Your share of a paid charge was broadcast and is waiting for the chain to confirm it.
 */
final class PayoutSent extends PayoutEvent {}
