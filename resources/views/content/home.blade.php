@extends('layouts.main')

@section('main_class', 'tv-page')

@push('modals')
    @include('tv.partials.dialogs')
@endpush

@section('content')
@php
    /**
     * The home page (docs/proposals/home-redesign/SPEC.md 2): the heading row with its one control, Shelves; the
     * user's ticked shelves in the user's order (home.shelves, drawn again by homeShelves after the dialog saves a
     * change); the admin's front-page content under them (SPEC 8); the Shelves dialog.
     *
     * @var list<array{shelf: \App\Enums\HomeShelf, count: string, tiles: list<\App\Data\HomeShelfTile>}> $shelves
     * @var list<array{shelf: \App\Enums\HomeShelf, ticked: bool}> $shelfRows
     */
@endphp
<div class="tv-screen home-screen" data-part="page ground and body text" x-data="homeShelves"
     data-home-url="{{ route('home') }}" data-preference-url="{{ route('profile.update-view') }}"
     data-nzb-link-base="{{ $nzbLinkBase }}" data-api-token="{{ $apiToken }}"
     x-on:click="handleClick" x-on:keydown="handleKeydown" x-on:pointerdown="startDrag">
    <div class="tv-wrap" data-part="content width wrapper">
        <div class="tv-filters home-heading">
            <h1 data-part="page title">Home</h1>
            <span class="tv-grow"></span>
            <button type="button" class="tv-details-button is-secondary home-tool" data-shelves-open><i class="fas fa-sliders" aria-hidden="true"></i>Shelves</button>
        </div>
        <div x-ref="shelves">
            @include('home.shelves')
        </div>
        @if($content !== [])
            {{-- the admin's front-page content, as before the shelves: its own wrapper keeps the old page's type sizes --}}
            <div class="home-dashboard">
                @foreach($content as $item)
                    <article class="card home-content surface-prose">
                        @if(filled($item->title))<h2>{{ $item->title }}</h2>@endif
                        @if(isset($item->body)){!! html_entity_decode(trim($item->body, '\'"')) !!}@endif
                        @if(filled($item->metadescription))<p class="account-muted">{{ $item->metadescription }}</p>@endif
                    </article>
                @endforeach
            </div>
        @endif
    </div>
    @include('home.shelves-dialog')
</div>
@endsection
