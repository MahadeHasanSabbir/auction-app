@extends('errors.layout')

@section('code', '503')
@section('title', __('We\'ll be right back'))
@section('message', __('The marketplace is down for a short update. Please try again in a few minutes.'))

@section('actions')
    <a class="button" href="javascript:location.reload()">{{ __('Try again') }}</a>
@endsection
