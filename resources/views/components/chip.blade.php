@blaze
@props(['variant' => 'neutral', 'icon' => null, 'href' => null, 'action' => false, 'pill' => false])

@php
    $tones = [
        'neutral' => 'bg-(--surface-panel-alt) dark:bg-(--surface-body-dark) text-gray-700 dark:text-gray-300',
        'origin' => 'bg-(--surface-panel-alt) dark:bg-(--surface-body-dark) text-slate-700 dark:text-slate-300 border border-(--border-default) dark:border-(--border-default-dark)',
        'entity' => 'text-slate-700 dark:text-slate-300 border',
        'primary' => 'bg-primary-100 dark:bg-primary-900 text-primary-800 dark:text-primary-200',
        'success' => 'bg-green-100 dark:bg-green-900 text-green-800 dark:text-green-200',
        'warning' => 'bg-yellow-100 dark:bg-yellow-900 text-yellow-800 dark:text-yellow-200',
        'danger' => 'bg-red-100 dark:bg-red-900 text-red-800 dark:text-red-200',
        'info' => 'bg-cyan-100 dark:bg-cyan-900 text-cyan-800 dark:text-cyan-200',
        // Redesigned release rows: tinted ground + coloured text from the chip tokens.
        'media' => 'chip-tone-media',
        'nfo' => 'chip-tone-nfo',
        'preview' => 'chip-tone-preview',
        'sample' => 'chip-tone-sample',
        'password' => 'chip-tone-password',
        'completion-ok' => 'chip-tone-completion-ok',
        'completion-mid' => 'chip-tone-completion-mid',
        'completion-low' => 'chip-tone-completion-low',
    ];
    $chipAttributes = $attributes->merge(['data-chip-variant' => $variant])->class(['release-chip', 'release-chip-pill' => $pill, $tones[$variant] ?? $tones['neutral']]);
@endphp

@if($href !== null)
    <a href="{{ $href }}" {{ $chipAttributes }}>
        @if($icon)<i class="{{ $icon }}" aria-hidden="true"></i>@endif
        {{ $slot }}
    </a>
@elseif($action)
    <button {{ $chipAttributes->merge(['type' => 'button']) }}>
        @if($icon)<i class="{{ $icon }}" aria-hidden="true"></i>@endif
        {{ $slot }}
    </button>
@else
    <span {{ $chipAttributes }}>
        @if($icon)<i class="{{ $icon }}" aria-hidden="true"></i>@endif
        {{ $slot }}
    </span>
@endif
