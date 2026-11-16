<?php

namespace App\Services\Payments;

use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;

/**
 * The gateways a payer can choose from, as listed in
 * config('auction.payments.gateways'). The fake gateway is never offered in
 * production.
 */
final class PaymentGateways
{
    /** @var array<string, class-string<PaymentGateway>> */
    private array $drivers = [
        'fake' => FakeGateway::class,
    ];

    public function __construct(private readonly Container $container) {}

    /** @return list<string> */
    public function enabled(): array
    {
        $configured = (array) config('auction.payments.gateways', []);
        $production = app()->isProduction();

        return array_values(array_filter(
            array_map(fn (mixed $name) => trim((string) $name), $configured),
            fn (string $name) => isset($this->drivers[$name]) && ! ($production && $name === 'fake'),
        ));
    }

    public function isEnabled(string $name): bool
    {
        return in_array($name, $this->enabled(), true);
    }

    public function get(string $name): PaymentGateway
    {
        if (! $this->isEnabled($name)) {
            throw new InvalidArgumentException("Payment gateway [{$name}] is not enabled.");
        }

        return $this->container->make($this->drivers[$name]);
    }
}
