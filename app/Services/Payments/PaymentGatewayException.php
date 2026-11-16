<?php

namespace App\Services\Payments;

use RuntimeException;

/** The gateway refused to start a payment or could not be reached. */
final class PaymentGatewayException extends RuntimeException {}
