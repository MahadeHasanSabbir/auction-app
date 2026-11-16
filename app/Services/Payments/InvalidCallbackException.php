<?php

namespace App\Services\Payments;

use RuntimeException;

/** A gateway callback that cannot be confirmed or does not match our records. */
final class InvalidCallbackException extends RuntimeException {}
