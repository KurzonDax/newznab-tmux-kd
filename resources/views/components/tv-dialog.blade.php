@props(['name', 'close' => 'close()'])
{{-- The TV screens' dialog frame (docs/proposals/tv-redesign/prototype, "dialog"): a scrim, a rounded panel with the title, the release name and a round close button. Works with modalLifecycle through data-modal-dialog. --}}
<div data-modal-dialog x-show="open" x-cloak class="tv-scrim" x-on:click.self="{{ $close }}">
    <div {{ $attributes->class(['tv-dialog']) }} role="dialog" aria-modal="true" aria-labelledby="{{ $name }}-dialog-title" x-bind:data-part="partWhenOpen('dialog')">
        <header>
            <div class="tv-dialog-heading">
                <h2 id="{{ $name }}-dialog-title" x-bind:data-part="partWhenOpen('dialog title')">{{ $title }}</h2>
                @isset($subtitle)
                    <p>{{ $subtitle }}</p>
                @endisset
            </div>
            <button type="button" class="tv-action tv-dialog-close" x-on:click="{{ $close }}" title="Close (Esc)" aria-label="Close" x-bind:data-part="partWhenOpen('dialog close button')"><i class="fas fa-xmark" aria-hidden="true"></i></button>
        </header>
        <div class="tv-dialog-body">{{ $slot }}</div>
        @isset($footer)
            <footer class="tv-dialog-footer">{{ $footer }}</footer>
        @endisset
    </div>
</div>
