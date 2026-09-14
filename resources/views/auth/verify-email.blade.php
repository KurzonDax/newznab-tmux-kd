@extends('layouts.guest')

@section('content')
    <div class="min-h-screen auth-page flex items-center justify-center px-4">
        <div class="w-full max-w-md auth-card auth-card-body shadow-md rounded-xl space-y-6">
            <div class="space-y-2 text-center">
                <h1 class="text-2xl font-semibold text-gray-900 dark:text-gray-100">Verify your email address</h1>
                <p class="text-sm text-gray-600 dark:text-gray-300">
                    Before continuing, please check your inbox for the verification link we emailed to you.
                </p>
            </div>

            <form method="POST" action="{{ route('verification.send') }}" class="space-y-4">
                @csrf
                <x-button
                    type="submit"
                    class="w-full"
                >
                    Resend verification email
                </x-button>
            </form>

            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <x-button
                    type="submit"
                    variant="secondary"
                    class="w-full"
                >
                    Sign out
                </x-button>
            </form>
        </div>
    </div>
@endsection

