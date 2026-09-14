@extends('layouts.guest', ['standaloneError' => true])
@section('content')
<main class="auth-page error-page min-h-dvh flex items-center justify-center px-4 py-10">
    <div class="w-full max-w-md text-center">
        <a class="error-logo" href="{{ url('/') }}"><i class="fas fa-cubes" aria-hidden="true"></i><span>{{ config('app.name') }}</span></a>
        <section class="auth-card auth-card-body rounded-xl mt-6">
            <h1>@yield('code')</h1>
            <p class="my-4">@yield('message')</p>
            <x-button-link :href="url('/')" variant="secondary" icon="fas fa-house">Back to home</x-button-link>
            @yield('action')
        </section>
    </div>
</main>
@endsection
