@props([
    'url',
    'color' => 'primary',
    'align' => 'center',
])
{{-- Primary buttons take the brand colour (admin → Branding). --}}
@inject('branding', 'App\Services\Branding\Branding')
@php($brandStyle = $color === 'primary' ? 'background-color: '.$branding->emailColor().'; border-color: '.$branding->emailColor().'; border-top: 8px solid '.$branding->emailColor().'; border-bottom: 8px solid '.$branding->emailColor().'; border-left: 18px solid '.$branding->emailColor().'; border-right: 18px solid '.$branding->emailColor().';' : '')
<table class="action" align="{{ $align }}" width="100%" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td align="{{ $align }}">
<table width="100%" border="0" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td align="{{ $align }}">
<table border="0" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td>
<a href="{{ $url }}" class="button button-{{ $color }}" target="_blank" rel="noopener" style="{{ $brandStyle }}">{!! $slot !!}</a>
</td>
</tr>
</table>
</td>
</tr>
</table>
</td>
</tr>
</table>
