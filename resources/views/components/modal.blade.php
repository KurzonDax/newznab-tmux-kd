@props(['name', 'width' => 'md', 'close' => 'close()', 'backdrop' => null])

<div data-modal-dialog x-show="open" x-cloak
     {{ $attributes->class(['public-modal', 'public-modal-'.$width]) }}
     role="dialog" aria-modal="true" aria-labelledby="{{ $name }}-modal-title"
     @click.self="{{ $backdrop ?? $close }}">
    <section class="card public-modal-card">
        <header class="public-modal-header">
            @isset($icon)
                <span class="public-modal-icon" aria-hidden="true">{{ $icon }}</span>
            @endisset
            <div class="min-w-0 flex-1">
                <h2 class="public-modal-title" id="{{ $name }}-modal-title">{{ $title }}</h2>
                @isset($subtitle)
                    <div class="public-modal-subtitle">{{ $subtitle }}</div>
                @endisset
            </div>
            <x-button variant="ghost" size="icon" class="public-modal-close" @click="{{ $close }}" title="Close (Esc)" aria-label="Close dialog" icon="fas fa-xmark" />
        </header>
        <div class="public-modal-body">{{ $slot }}</div>
        @isset($footer)
            <footer class="surface-panel-alt public-modal-footer">{{ $footer }}</footer>
        @endisset
    </section>
    @isset($overlay)
        {{ $overlay }}
    @endisset
</div>
