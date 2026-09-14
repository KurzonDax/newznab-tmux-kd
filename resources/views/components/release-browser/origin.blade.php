@php
    $entityUrl = $entity?->titleUrl() ?? route('details', $row->guid);
@endphp
<div class="release-browser-origin">
    @if($entity)
        <x-entity-chip :root="$entity->root" :title="$entity->title" :year="$entity->year" :href="$entityUrl" />
    @endif
    <x-origin-chip kind="group" :value="$row->group" :href="route('browse.all', ['group' => $row->group])" />
    <x-origin-chip kind="poster" :value="$row->poster" :href="route('browse.all', ['poster' => $row->poster])" />
</div>
