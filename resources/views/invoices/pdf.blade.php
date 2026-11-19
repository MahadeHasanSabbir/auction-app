@php
    /** @var \App\Models\Invoice $invoice */
    $zone = config('auction.display_timezone');
    // Grouped digits and the currency code: the PDF font has no taka sign.
    $amount = function (\App\Support\Money $money): string {
        [$whole, $fraction] = array_pad(explode('.', $money->toDecimal(), 2), 2, null);
        $sign = str_starts_with($whole, '-') ? '-' : '';
        $whole = strrev(implode(',', str_split(strrev(ltrim($whole, '-')), 3)));

        return $sign.$whole.($fraction === null ? '' : '.'.$fraction).' '.$money->currency;
    };
@endphp
<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <title>{{ $invoice->kind->label() }} {{ $invoice->number }}</title>
    <style>
        @page { margin: 32px 40px; }
        body { font-family: "DejaVu Sans", sans-serif; font-size: 11px; color: #1e293b; }
        h1 { font-size: 20px; margin: 0 0 4px; color: #1e3a8a; }
        .muted { color: #64748b; }
        .row { width: 100%; }
        .row td { vertical-align: top; }
        table.lines { width: 100%; border-collapse: collapse; margin-top: 24px; }
        table.lines th { text-align: left; border-bottom: 1px solid #cbd5e1; padding: 6px 4px; font-size: 10px; text-transform: uppercase; color: #64748b; }
        table.lines td { border-bottom: 1px solid #e2e8f0; padding: 8px 4px; }
        .num { text-align: right; white-space: nowrap; }
        .total td { font-weight: bold; border-bottom: none; border-top: 2px solid #1e293b; }
        .note { margin-top: 24px; font-size: 10px; color: #64748b; }
    </style>
</head>
<body>
    <table class="row">
        <tr>
            <td>
                <h1>{{ $invoice->kind->label() }}</h1>
                <div><strong>{{ $invoice->number }}</strong></div>
                <div class="muted">{{ __('Issued') }} {{ $invoice->issued_at->setTimezone($zone)->format('j M Y, H:i') }}</div>
            </td>
            <td style="text-align: right;">
                @if ($logo = app(\App\Services\Branding\Branding::class)->logoDataUri())
                    <img src="{{ $logo }}" alt="" style="max-height: 40px; max-width: 200px; margin-bottom: 6px;"><br>
                @endif
                <strong>{{ $issuer['name'] }}</strong><br>
                @if ($issuer['address']){!! nl2br(e($issuer['address'])) !!}<br>@endif
                @if ($issuer['email']){{ $issuer['email'] }}<br>@endif
                @if ($vatNumber){{ __('VAT registration') }}: {{ $vatNumber }}@endif
            </td>
        </tr>
    </table>

    <div style="margin-top: 24px;">
        <div class="muted">{{ __('Billed to') }}</div>
        <strong>{{ $invoice->billed_name }}</strong><br>
        @if ($invoice->billed_email){{ $invoice->billed_email }}<br>@endif
        @if ($invoice->billed_address){!! nl2br(e($invoice->billed_address)) !!}@endif
    </div>

    <table class="lines">
        <thead>
            <tr>
                <th>{{ __('Description') }}</th>
                <th class="num">{{ __('Amount') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($invoice->moneyLines() as $line)
                <tr>
                    <td>{{ $line['description'] }}</td>
                    <td class="num">{{ $amount($line['amount']) }}</td>
                </tr>
            @endforeach
            <tr class="total">
                <td>{{ __('Total') }}</td>
                <td class="num">{{ $amount($invoice->total) }}</td>
            </tr>
        </tbody>
    </table>

    @if ($invoice->tax->isPositive())
        <p class="note">
            {{ __('Platform fees of :fees include VAT of :tax (:rate%).', [
                'fees' => $amount($invoice->taxable),
                'tax' => $amount($invoice->tax),
                'rate' => rtrim(rtrim(number_format($invoice->tax_rate_bps / 100, 2), '0'), '.'),
            ]) }}
        </p>
    @endif

    <p class="note">{{ __('This document was issued electronically and is valid without a signature.') }}</p>
</body>
</html>
