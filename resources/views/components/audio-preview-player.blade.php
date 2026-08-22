@props([
    'audioUrl' => null,
    'audioType' => null,
    'audioMeta' => null,
    'dynamic' => false,
])

<div {{ $attributes }} @if($dynamic) x-show="audioUrl" @endif>
    <audio controls preload="none"
           class="w-full"
           @if($dynamic)
               x-ref="audioPlayer"
               x-bind:src="audioUrl"
           @else
               src="{{ $audioUrl }}"
           @endif>
        <source @if($dynamic) x-bind:src="audioUrl" x-bind:type="audioType" @else src="{{ $audioUrl }}" type="{{ $audioType }}" @endif>
        Your browser does not support playing this audio preview.
    </audio>

    @if($dynamic || filled($audioMeta))
        <p class="mt-2 text-xs text-gray-600 dark:text-gray-400"
           @if($dynamic) x-show="audioMeta" x-text="audioMeta" @endif>
            @unless($dynamic)
                {{ $audioMeta }}
            @endunless
        </p>
    @endif
</div>
