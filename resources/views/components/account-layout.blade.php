@props(['section' => 'profile'])
<div class="account-page" x-data="accountPage" data-account-section="{{ $section }}">
    <x-breadcrumb :items="[['label' => 'Account']]" />
    <x-page-header title="Account" />
    <div class="account-layout">
        <nav class="card account-sections" aria-label="Account sections">
            @foreach(['profile' => 'Profile', 'appearance' => 'Appearance', 'security' => 'Security', 'api' => 'API & RSS', 'downloads' => 'Downloads', 'privacy' => 'Privacy', 'invitations' => 'Invitations'] as $key => $label)
                <a href="{{ route('account', ['section' => $key]) }}" @if($key === $section) aria-current="page" @endif>{{ $label }}</a>
            @endforeach
        </nav>
        <div class="account-content" id="account-section-{{ $section }}">
            @if($errors->any())
                <div class="card account-card text-red-600 dark:text-red-400" role="alert">{{ $errors->first() }}</div>
            @endif
            {{ $slot }}
        </div>
    </div>
</div>
