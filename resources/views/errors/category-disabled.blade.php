@extends('errors.minimal')
@section('title', 'Category disabled')
@section('code', '403')
@section('message'){{ $category }} is hidden in your account preferences.@endsection
@section('action')
    <div class="mt-3"><x-button-link :href="route('account', ['section' => 'appearance'])">Change category preferences</x-button-link></div>
@endsection
