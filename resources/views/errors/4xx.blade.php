@extends('errors.layout')

@section('code')
    {{ isset($exception) && method_exists($exception, 'getStatusCode') ? $exception->getStatusCode() : '400' }}
@endsection
@section('title', __('Something is not right'))
@section('message', __('The request could not be completed. Go back and try again.'))
