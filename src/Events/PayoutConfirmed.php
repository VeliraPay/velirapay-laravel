<?php

declare(strict_types=1);

namespace VeliraPay\Laravel\Events;

/**
 * The chain confirmed the payout, so the money has reached your address.
 */
final class PayoutConfirmed extends PayoutEvent {}
