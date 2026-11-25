@extends('errors.layout')

@section('code', '401')
@section('title', __('Please sign in'))
@section('message', __('You need to sign in to see this page.'))

@section('actions')
    <a class="button" href="{{ url('/login') }}">{{ __('Sign in') }}</a>
    <a class="button secondary" href="{{ url('/') }}">{{ __('Go to the home page') }}</a>
@endsection
