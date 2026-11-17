<?php

namespace App\Enums;

/**
 * Where a withdrawal is paid to. Staff pay it out by hand; the details are
 * stored encrypted on the withdrawal request.
 */
enum PayoutMethod: string
{
    case Bank = 'bank';
    case Bkash = 'bkash';
    case Nagad = 'nagad';
    case Rocket = 'rocket';

    public function label(): string
    {
        return match ($this) {
            self::Bank => __('Bank account'),
            self::Bkash => __('bKash'),
            self::Nagad => __('Nagad'),
            self::Rocket => __('Rocket'),
        };
    }

    public function isMobileWallet(): bool
    {
        return $this !== self::Bank;
    }

    /**
     * Validation rules for the destination details of this method.
     *
     * @return array<string, list<string>>
     */
    public function destinationRules(): array
    {
        $common = ['account_name' => ['required', 'string', 'max:100']];

        if ($this->isMobileWallet()) {
            // A Bangladeshi mobile number: 01, an operator digit, eight digits.
            return $common + ['account_number' => ['required', 'string', 'regex:/^01[3-9]\d{8}$/']];
        }

        return $common + [
            'account_number' => ['required', 'string', 'regex:/^\d{6,20}$/'],
            'bank_name' => ['required', 'string', 'max:100'],
            'branch' => ['required', 'string', 'max:100'],
            'routing_number' => ['nullable', 'string', 'regex:/^\d{9}$/'],
        ];
    }

    /**
     * A short description safe to show: the method and the last digits.
     *
     * @param  array<string, mixed>  $destination
     */
    public function describe(array $destination): string
    {
        $number = (string) ($destination['account_number'] ?? '');
        $tail = $number === '' ? '' : ' ••'.substr($number, -4);
        $bank = $this === self::Bank && isset($destination['bank_name']) ? ' ('.$destination['bank_name'].')' : '';

        return $this->label().$bank.$tail;
    }
}
