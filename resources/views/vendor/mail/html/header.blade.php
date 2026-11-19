@props(['url'])
{{-- The band at the top of every email: the platform logo or name on the brand colour (admin → Branding). --}}
@inject('branding', 'App\Services\Branding\Branding')
<tr>
<td class="header" style="background-color: {{ $branding->emailColor() }}; padding: 20px 0;">
<a href="{{ $url }}" style="display: inline-block; color: #ffffff;">
@if ($branding->logoUrl())
<img src="{{ $branding->logoUrl() }}" alt="{{ config('app.name') }}" style="max-height: 48px; max-width: 240px; height: auto; border: 0;">
@else
{!! $slot !!}
@endif
</a>
</td>
</tr>
