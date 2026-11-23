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
        'sslcommerz' => SslCommerzGateway::class,
        'stripe' => StripeGateway::class,
        'fake' => FakeGateway::class,
    ];

    public function __construct(private readonly Container $container) {}

    /** @return list<string> */
    public function enabled(): array
    {
        $configured = (array) config('auction.payments.gateways', []);
        // The fake gateway hands out money: only on a developer's machine and in tests,
        // never on a server, whatever the environment is called there.
        $fakeAllowed = app()->environment('local', 'testing');

        return array_values(array_filter(
            array_map(fn (mixed $name) => trim((string) $name), $configured),
            fn (string $name) => isset($this->drivers[$name]) && ($fakeAllowed || $name !== 'fake'),
        ));
    }

    /**
     * The enabled gateways keyed by name, for a payment form.
     *
     * @return array<string, PaymentGateway>
     */
    public function all(): array
    {
        $gateways = [];

        foreach ($this->enabled() as $name) {
            $gateways[$name] = $this->get($name);
        }

        return $gateways;
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
