@extends('layouts.main')

@section('content')
<div class="max-w-4xl mx-auto px-4 py-3">
    <x-breadcrumb :items="[['label' => 'Home', 'url' => url($site['home_link'] ?? '/')]]" />

    <div class="bg-(--surface-card) dark:bg-(--surface-card-dark) rounded-xl shadow-sm dark:bg-(--surface-card-dark)">
        <x-page-header :title="'Invitation to Join '.$site['title']" icon="fas fa-envelope-open" />
        <div class="p-6">
            @if($preview)
                <div class="bg-primary-50 border border-primary-200 text-primary-800 rounded-lg p-4 mb-6 dark:bg-primary-900 dark:border-primary-700 dark:text-primary-300">
                    <div class="flex">
                        <i class="fa fa-user-plus text-4xl mr-4 mt-1"></i>
                        <div>
                            <h6 class="font-semibold mb-2">You've been invited!</h6>
                            <p class="mb-0">
                                <strong>{{ $preview['inviter_name'] ?? 'Someone' }}</strong> has invited you to join <strong>{{ $site['title'] }}</strong>.
                            </p>
                        </div>
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6">
                    <div class="bg-(--surface-panel-alt) dark:bg-(--surface-body-dark) rounded-lg border border-gray-200 dark:border-gray-700 dark:bg-(--surface-panel-alt-dark) dark:border-gray-600">
                        <div class="px-4 py-3 border-b border-gray-200 dark:border-gray-700 dark:border-gray-600">
                            <h6 class="font-semibold text-gray-900 dark:text-gray-100 dark:text-white flex items-center">
                                <i class="fa fa-info-circle mr-2"></i>Invitation Details
                            </h6>
                        </div>
                        <div class="p-4">
                            <div class="grid grid-cols-5 gap-3 mb-3">
                                <div class="col-span-2 text-gray-600 dark:text-gray-400">
                                    <i class="fa fa-user mr-1"></i>Invited by:
                                </div>
                                <div class="col-span-3 font-medium text-gray-900 dark:text-gray-100">
                                    {{ $preview['inviter_name'] ?? 'Anonymous' }}
                                </div>
                            </div>
                            <div class="grid grid-cols-5 gap-3 mb-3">
                                <div class="col-span-2 text-gray-600 dark:text-gray-400">
                                    <i class="fa fa-envelope mr-1"></i>Email:
                                </div>
                                <div class="col-span-3 font-medium text-gray-900 dark:text-gray-100">
                                    {{ $preview['email'] ?? 'N/A' }}
                                </div>
                            </div>
                            <div class="grid grid-cols-5 gap-3 mb-3">
                                <div class="col-span-2 text-gray-600 dark:text-gray-400">
                                    <i class="far fa-clock mr-1"></i>Expires:
                                </div>
                                <div class="col-span-3">
                                    @isset($preview['expires_at'])
                                        <span class="text-gray-900 dark:text-gray-100">{{ date('M j, Y H:i', $preview['expires_at']) }}</span>
                                        @if($preview['expires_at'] < time())
                                            <br><span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-800 mt-1 dark:bg-red-900 dark:text-red-200">
                                                <i class="fa fa-times mr-1"></i>Expired
                                            </span>
                                        @elseif($preview['is_used'])
                                            <br><span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800 mt-1 dark:bg-green-900 dark:text-green-200">
                                                <i class="fa fa-check mr-1"></i>Already Used
                                            </span>
                                        @else
                                            <br><span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800 mt-1 dark:bg-green-900 dark:text-green-200">
                                                <i class="fa fa-check mr-1"></i>Valid
                                            </span>
                                        @endif
                                    @else
                                        <span class="text-gray-500 dark:text-gray-400">N/A</span>
                                    @endisset
                                </div>
                            </div>
                            @if(isset($preview['metadata']['role']) && isset($preview['role_name']))
                                <div class="grid grid-cols-5 gap-3">
                                    <div class="col-span-2 text-gray-600 dark:text-gray-400">
                                        <i class="fa fa-user-tag mr-1"></i>Assigned Role:
                                    </div>
                                    <div class="col-span-3 font-medium">
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-primary-100 text-primary-800 dark:bg-primary-900 dark:text-primary-200">
                                            {{ $preview['role_name'] ?? 'Default' }}
                                        </span>
                                    </div>
                                </div>
                            @endif
                        </div>
                    </div>
                    <div class="bg-(--surface-panel-alt) dark:bg-(--surface-body-dark) rounded-lg border border-gray-200 dark:border-gray-700 dark:bg-(--surface-panel-alt-dark) dark:border-gray-600">
                        <div class="px-4 py-3 border-b border-gray-200 dark:border-gray-700 dark:border-gray-600">
                            <h6 class="font-semibold text-gray-900 dark:text-gray-100 dark:text-white flex items-center">
                                <i class="fa fa-list-ol mr-2"></i>What's Next?
                            </h6>
                        </div>
                        <div class="p-4">
                            <p class="mb-3 text-gray-700 dark:text-gray-300">To accept this invitation and create your account:</p>
                            <ol class="list-decimal list-inside mb-0 space-y-2 text-gray-700 dark:text-gray-300">
                                <li>Click the "Accept Invitation" button below</li>
                                <li>Fill out the registration form with your details</li>
                                <li>Verify your email address when prompted</li>
                                <li>Start exploring {{ $site['title'] }}!</li>
                            </ol>
                        </div>
                    </div>
                </div>

                <div class="text-center mt-6">
                    @if($preview['is_used'])
                        <div class="bg-green-50 border border-green-200 text-green-800 rounded-lg p-6 mb-4 dark:bg-green-900 dark:border-green-700 dark:text-green-300">
                            <i class="fas fa-check-circle text-4xl mb-2"></i>
                            <h6 class="font-semibold mb-2">This invitation has already been used</h6>
                            <p class="mb-0">The account has been successfully created using this invitation.</p>
                        </div>
                        <x-button-link href="{{ url('/login') }}" size="lg" icon="fas fa-sign-in-alt">
                            Login to Your Account
                        </x-button-link>
                    @elseif($preview['expires_at'] < time())
                        <div class="bg-red-50 border border-red-200 text-red-800 rounded-lg p-6 mb-4 dark:bg-red-900 dark:border-red-700 dark:text-red-300">
                            <i class="fas fa-exclamation-triangle text-4xl mb-2"></i>
                            <h6 class="font-semibold mb-2">This invitation has expired</h6>
                            <p class="mb-0">Please contact the person who invited you for a new invitation.</p>
                        </div>
                        <x-button-link href="{{ url('/contact') }}" variant="secondary" icon="fa fa-envelope">
                            Contact Support
                        </x-button-link>
                    @elseif($registrationStatus['is_closed'])
                        <div class="bg-yellow-50 border border-yellow-200 text-yellow-800 rounded-lg p-6 mb-4 dark:bg-yellow-900 dark:border-yellow-700 dark:text-yellow-300">
                            <i class="fas fa-door-closed text-4xl mb-2"></i>
                            <h6 class="font-semibold mb-2">Registrations are currently closed</h6>
                            <p class="mb-0">{{ $registrationStatus['message'] }}</p>
                        </div>
                    @else
                        <x-button-link href="{{ route('register', ['token' => $token]) }}" size="lg" icon="fas fa-user-plus">
                            Accept Invitation & Create Account
                        </x-button-link>
                        <div class="mt-3">
                            <small class="text-gray-500 dark:text-gray-400">
                                Already have an account? <a href="{{ url('/login') }}" class="text-primary-600 dark:text-primary-400 hover:text-primary-800 dark:hover:text-primary-300 dark:text-primary-400 dark:hover:text-primary-300">Login here</a>
                            </small>
                        </div>
                    @endif
                </div>
            @else
                <div class="bg-red-50 border border-red-200 text-red-800 rounded-lg p-6 text-center dark:bg-red-900 dark:border-red-700 dark:text-red-300">
                    <i class="fas fa-exclamation-triangle text-5xl mb-3 text-red-600 dark:text-red-400"></i>
                    <h5 class="text-xl font-semibold text-red-600 mb-3 dark:text-red-400">Invalid Invitation</h5>
                    <p class="mb-4">This invitation link is not valid, has expired, or has been removed.</p>
                    <div class="flex flex-col sm:flex-row gap-2 justify-center">
                        <x-button-link href="{{ url('/contact') }}" variant="secondary" icon="fa fa-envelope">
                            Contact Support
                        </x-button-link>
                        @if($registrationStatus['is_open'])
                            <x-button-link href="{{ url('/register') }}" icon="fa fa-user-plus">
                                Register Without Invitation
                            </x-button-link>
                        @endif
                    </div>
                </div>
            @endif
        </div>
    </div>
</div>
@endsection

