@extends('errors.layout')

@section('code')
    {{ isset($exception) && method_exists($exception, 'getStatusCode') ? $exception->getStatusCode() : '500' }}
@endsection
@section('title', __('Something went wrong'))
@section('message', __('It is our fault, not yours. Please try again in a moment.'))
