@props(['kind', 'value', 'href' => null])

@if(filled($value))
    <x-chip variant="origin"
            :icon="$kind === 'group' ? 'fas fa-users' : 'fas fa-user'"
            :href="$href ?? ($kind === 'group' ? url('/browse/group').'?'.http_build_query(['g' => $value]) : route('poster-identity', ['name' => $value]))"
            :title="($kind === 'group' ? 'All releases in ' : 'All posts by ').$value"
            {{ $attributes }}>{{ $value }}</x-chip>
@endif
