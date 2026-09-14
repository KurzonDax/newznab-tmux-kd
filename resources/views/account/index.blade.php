@extends('layouts.main')
@section('content')
<x-account-layout :section="$section">
    @include('account.'.$section)
</x-account-layout>
@endsection
