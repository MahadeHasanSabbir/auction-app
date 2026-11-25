<?php

namespace App\Services\Payments;

use RuntimeException;

/**
 * A genuine gateway request that has nothing to act on (yet): a Stripe event
 * this app does not use, or a payment still being processed. The gateway is
 * answered "OK" so it stops retrying.
 */
final class IgnoredCallbackException extends RuntimeException {}
