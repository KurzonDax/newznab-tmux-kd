@extends('layouts.main')

@section('main_class', 'tv-page')

@push('modals')
    @include('tv.partials.dialogs')
@endpush

@section('content')
@php
    /**
     * The generic release lists (docs/proposals/generic-release-lists/SPEC.md 5): the Books / PC list form in four
     * contexts. A group's and a poster's list carry the "All releases › Group / Poster" breadcrumb (the Following
     * scope "All releases › Following"); a poster's heading may break before "@" and "<"; an administrator sees the
     * Blacklist button or the muted "Blacklisted (rule #N)" link beside the name search (SPEC 5.8).
     *
     * @var \App\Data\GenericListContext $context
     * @var \App\Data\GenericReleaseFilters $filters
     */
    $admin = $context->isPoster() && $context->key !== '' && (auth()->user()?->hasRole('Admin') ?? false);
    $crumb = match (true) {
        $context->isGroup() => 'Group',
        $context->isPoster() && $context->key !== '' => 'Poster',
        $context->watching => 'Following',
        default => null,
    };
    $headingHtml = $context->isPoster() ? str_replace(['@', '&lt;'], ['<wbr>@', '<wbr>&lt;'], e($heading)) : e($heading);
    $dialog = $admin && $blacklistRule === null && $blacklistPreview !== null;
@endphp
<div class="tv-screen" data-part="page ground and body text" x-data="tvReleases"
     data-preference-url="{{ route('profile.update-view') }}" data-preference-root="{{ $preferenceRoot }}" data-filters-clock="{{ $filtersClock }}"
     data-nzb-link-base="{{ $nzbLinkBase }}" data-api-token="{{ $apiToken }}"
     x-on:click="handleClick" x-on:change="handleChange" x-on:checkbox-menu-change="applyFilter" x-on:blacklist-sweep-finished="refreshList">
    <div class="tv-wrap" data-part="content width wrapper">
        @if($crumb !== null)
            <nav class="tv-crumbs tv-list-crumbs" aria-label="Breadcrumb">
                <a href="{{ route('browse.all') }}">All releases</a><span aria-hidden="true">›</span>
                <span>{{ $crumb }}</span>
            </nav>
        @endif
        <div @if($dialog) x-data="posterIdentityBlacklist" @endif>
        <div class="tv-filters">
            <h1 data-part="page title"@if($context->isPoster()) class="is-poster"@endif>{!! $headingHtml !!}</h1>
            {{-- The name search (SPEC 5.6): narrows the list to names containing the text; never remembered. --}}
            <div class="tv-search tv-name-search">
                <label>
                    <i class="fas fa-magnifying-glass" aria-hidden="true"></i>
                    <input type="text" x-ref="nameSearch" value="{{ $filters->search }}" placeholder="Search release names" autocomplete="off" aria-label="Search release names" data-part="search field"
                           x-on:input="searchNames" x-on:keydown.escape.prevent="clearNameSearch">
                    <button type="button" @class(['tv-name-search-clear', 'is-hidden' => $filters->search === '']) x-ref="nameClear" aria-label="Clear the name search" title="Clear the name search" x-on:click="clearNameSearch" @if($filters->search === '') aria-hidden="true" tabindex="-1" @endif><i class="fas fa-xmark" aria-hidden="true"></i></button>
                </label>
            </div>
            @if($admin)
                @if($blacklistRule !== null)
                    <a class="tv-blacklist-link" href="{{ route('admin.binaryblacklist-edit', ['id' => $blacklistRule->id]) }}" data-blacklisted><i class="fas fa-ban" aria-hidden="true"></i>Blacklisted (rule #{{ $blacklistRule->id }})</a>
                @elseif($dialog)
                    <button type="button" class="tv-blacklist-button" data-blacklist x-on:click="openConfirmation"><i class="fas fa-ban" aria-hidden="true"></i>Blacklist this poster</button>
                @endif
            @endif
            <span class="tv-grow"></span>
            <label class="tv-sort">
                <select aria-label="Sort releases" data-part="sort dropdown" x-on:change="changeSort">
                    @foreach(\App\Data\GenericReleaseFilters::SORTS as $value => $text)
                        <option value="{{ $value }}" @selected($filters->sort->value === $value)>{{ $text }}</option>
                    @endforeach
                </select>
                <i class="fas fa-chevron-down" aria-hidden="true"></i>
            </label>
        </div>
        @if($dialog)
            @include('generic.releases.blacklist-dialog')
        @endif
        </div>
        {{-- Two release cells (SPEC 5.2), each as wide as a Movie releases release-bar cell: Category (roots, or Misc / Hashed on Other) and Completion. --}}
        <div class="filter-row tv-bar-list is-shelf">
            <x-filter-bar label="The release" class="is-release">
                <x-checkbox-menu cell name="category" label="Category" noun="categories" :options="$categoryMenu" :selected="$filters->categories" :exclude-other="$excludableOther" />
                <x-checkbox-menu cell single name="completion" label="Completion" any="Any completion" :options="\App\Data\ReleaseListFilters::completionOptions()" :short="\App\Data\ReleaseListFilters::completionCells()" :selected="$filters->completion === null ? [] : [$filters->completion]" />
            </x-filter-bar>
        </div>
        <div x-ref="list" class="tv-list-end">
            @include('generic.releases.list')
        </div>
    </div>
    <div class="tv-bulk" x-show="selectedCount" x-cloak>
        <span><span x-text="selectedCount"></span> selected</span>
        <button type="button" class="tv-button" x-on:click="downloadSelected"><i class="fas fa-download" aria-hidden="true"></i>Download NZBs</button>
        <button type="button" class="tv-button tv-button-secondary" x-on:click="addSelectedToCart"><i class="fas fa-cart-shopping" aria-hidden="true"></i>Add to cart</button>
        <button type="button" class="tv-button tv-button-secondary" x-on:click="clearSelection">Clear selection</button>
    </div>
</div>
@endsection
