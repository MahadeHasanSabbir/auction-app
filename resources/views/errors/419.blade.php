@extends('errors.layout')

@section('code', '419')
@section('title', __('This page expired'))
@section('message', __('It was open for too long. Go back, refresh the page and try again.'))

@section('actions')
    <a class="button" href="javascript:history.back()">{{ __('Go back') }}</a>
    <a class="button secondary" href="{{ url('/') }}">{{ __('Go to the home page') }}</a>
@endsection
