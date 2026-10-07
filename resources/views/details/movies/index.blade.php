@extends('layouts.main')

@section('main_class', 'tv-page')

@push('modals')
    @include('tv.partials.dialogs')
@endpush

@php
    /**
     * The Movies release details page (docs/proposals/movies-redesign/SPEC.md 5C): the approved TV
     * details page adapted. A release with no matched film has no poster, film crumb, Follow film,
     * About the film or releases table; its tabs and facts span the full width (5C.6).
     *
     * @var \App\Data\MovieReleaseRow $row
     * @var \App\Data\MovieFilmHeader|null $film
     * @var array<int, string> $starring
     * @var array{url: string, type: string}|null $clip
     * @var list<array{string, string}> $facts
     * @var list<array{string, string}> $predb
     * @var list<\App\Data\MovieReleaseRow> $similar
     */
    $commentCount = $comments->total();
    $tabs = ['overview' => 'Overview', 'files' => 'Files ('.$row->files.')', 'media' => 'Media info', 'nfo' => 'NFO', 'comments' => 'Comments ('.$commentCount.')'];
    $mediaSummary = $row->mediaInfoWithResolution();
    $uploader = mb_strlen($row->uploader) > 26 ? mb_substr($row->uploader, 0, 25).'…' : $row->uploader;
    $filmUrl = $film === null ? null : route('movies.film', ['movieinfoId' => $film->id]);
    $people = static fn (array $names): string => implode(', ', array_map(static fn (int $id, string $name): string => '<a href="'.e(route('movies.films', ['person' => $id])).'">'.e($name).'</a>', array_keys($names), $names));
@endphp

@section('content')
<div class="tv-screen tv-details" data-part="page ground and body text" x-data="movieReleaseDetails"
     data-guid="{{ $row->guid }}" data-release-id="{{ $row->id }}" data-has-media="{{ $row->mediaInfo === null ? '0' : '1' }}" data-has-nfo="{{ $row->nfo ? '1' : '0' }}" data-nzb-link-base="{{ $nzbLinkBase }}" data-api-token="{{ $apiToken }}"
     x-on:click="handleClick" x-on:submit="handleSubmit">
    <div class="tv-wrap" data-part="content width wrapper">
        <nav class="tv-crumbs" aria-label="Breadcrumb">
            <a href="{{ route('movies.releases') }}">Movie releases</a><span aria-hidden="true">›</span>
            @if($film !== null)
                <a href="{{ $filmUrl }}">{{ $film->title }}</a><span aria-hidden="true">›</span>
            @endif
            <span>{{ $category }}</span>
        </nav>
        <div @class(['tv-details-head', 'is-release-only' => $film === null])>
            @if($film !== null)
                <a class="tv-details-art" href="{{ $filmUrl }}" tabindex="-1" aria-hidden="true" data-part="details poster">
                    @if($film->poster !== null)
                        <img src="{{ $film->poster }}" alt="">
                    @else
                        <span class="tv-show-card is-film"><span class="tv-tile-card-title">{{ $film->title }}</span>@if($film->year !== '')<small>{{ $film->year }}</small>@endif</span>
                    @endif
                </a>
            @endif
            <div>
                @if($film !== null)
                    <h1 data-part="details heading"><a href="{{ $filmUrl }}">{{ $film->title }}</a>{{ $film->year === '' ? '' : ' · '.$film->year }}</h1>
                    <div class="tv-details-name" data-part="details release name">{{ $row->name }}</div>
                @else
                    <h1 class="is-release-name" data-part="details heading">{{ $row->name }}</h1>
                @endif
                <div class="tv-chips tv-details-chips">
                    <x-resolution-chip :resolution="$row->resolution" :part="false" />
                    <span class="tv-source-chip">{{ $row->source }}</span>
                    @if($row->completion !== null)
                        <x-chip :variant="'completion-'.$row->completion['band']"
                                :title="$row->completion['percent'].'% of this release\'s articles were seen by the indexer. '.($row->completion['repairing'] ? 'The site may still recover more of it.' : 'The site will not try to recover more of it.')">{{ $row->completion['percent'] }}% complete{{ $row->completion['repairing'] ? ' · still repairing' : '' }}</x-chip>
                    @endif
                    @if($row->passworded)
                        <x-chip variant="password" icon="fas fa-lock">Password</x-chip>
                    @endif
                    @if($mediaSummary !== null)
                        <x-chip variant="media" action icon="fas fa-circle-info" class="mediainfo-badge" :data-release-id="$row->id" :data-release-display-name="$row->name" title="View media info">{{ $mediaSummary }}</x-chip>
                    @endif
                    @if($row->nfo)
                        <x-chip variant="nfo" action class="nfo-badge" :data-guid="$row->guid" :data-release-display-name="$row->name" title="View NFO">NFO</x-chip>
                    @endif
                    @if($row->preview !== null)
                        <x-chip variant="preview" action class="preview-badge" :data-guid="$row->guid" :data-release-display-name="$row->name" :data-image-url="$row->preview['thumb'] ?? ''"
                                :data-full-url="$row->preview['full']" data-image-title="Preview image" title="View preview image">Preview</x-chip>
                    @endif
                    @if($row->sample !== null)
                        <x-chip variant="sample" action class="sample-badge" :data-guid="$row->guid" :data-release-display-name="$row->name" :data-image-url="$row->sample['thumb'] ?? ''"
                                :data-full-url="$row->sample['full']" data-image-title="Sample image" title="View sample image">Sample</x-chip>
                    @endif
                    @if($clip !== null)
                        <x-chip variant="preview" action class="clip-badge" :data-guid="$row->guid" :data-release-display-name="$row->name" :data-video-url="$clip['url']" :data-video-type="$clip['type']"
                                data-image-title="Video preview" title="Watch video preview">Clip</x-chip>
                    @endif
                </div>
                @if($row->group !== '' || $row->uploader !== '')
                    <div class="tv-chips tv-details-origin">
                        @if($row->group !== '')
                            <a class="tv-origin-chip" href="{{ route('browse.all', ['group' => $row->group]) }}" title="All releases in {{ $row->group }}"><i class="fas fa-users" aria-hidden="true"></i>{{ $row->groupLabel() }}</a>
                        @endif
                        @if($row->uploader !== '')
                            <a class="tv-origin-chip" href="{{ route('browse.all', ['poster' => $row->uploader]) }}" title="All posts by {{ $row->uploader }}"><i class="fas fa-user" aria-hidden="true"></i>{{ $uploader }}</a>
                        @endif
                    </div>
                @endif
                <div class="tv-details-actions">
                    <a class="tv-details-button download-nzb" href="{{ route('getnzb.guid', $row->guid) }}" data-part="details primary button"><i class="fas fa-download" aria-hidden="true"></i>Download NZB</a>
                    <button type="button" class="tv-details-button is-secondary" data-copy-nzb="{{ $row->guid }}" data-part="details secondary button"><i class="fas fa-link" aria-hidden="true"></i>Copy NZB link</button>
                    <button type="button" class="tv-details-button is-secondary" data-cart="{{ $row->guid }}" data-cart-label aria-pressed="{{ $row->inCart ? 'true' : 'false' }}" title="{{ $row->inCart ? 'In cart · click to remove' : 'Add to cart' }}"><i class="fas fa-cart-shopping" aria-hidden="true"></i><span class="tv-state-label"><span class="is-off">Add to cart</span><span class="is-on">In cart</span></span></button>
                    @if($film !== null && $film->imdbId !== '')
                        @include('tv.partials.follow-show', ['showId' => $film->imdbId, 'showTitle' => $film->title, 'followed' => $row->watched, 'followRoot' => 'movies', 'followNoun' => 'film'])
                    @endif
                </div>
            </div>
        </div>
        <div @class(['tv-details-columns', 'is-release-only' => $film === null])>
            <div>
                <div class="tv-details-tabs" role="tablist" aria-label="Release details">
                    @foreach($tabs as $key => $label)
                        <button type="button" role="tab" id="tab-{{ $key }}" data-tab="{{ $key }}" aria-controls="{{ $key }}" aria-selected="{{ $loop->first ? 'true' : 'false' }}" tabindex="{{ $loop->first ? '0' : '-1' }}"
                                @if($loop->index < 2) data-part="{{ $loop->first ? 'tab, current' : 'tab' }}" @endif>{{ $label }}</button>
                    @endforeach
                </div>
                <section id="overview" class="tv-details-panel" role="tabpanel" aria-labelledby="tab-overview" data-details-panel>
                    @if($row->preview !== null && $row->preview['thumb'] !== null)
                        <button type="button" class="tv-details-preview preview-badge" data-guid="{{ $row->guid }}" data-release-display-name="{{ $row->name }}" data-image-url="{{ $row->preview['thumb'] }}"
                                data-full-url="{{ $row->preview['full'] }}" data-image-title="Preview image" aria-label="View preview image"><img src="{{ $row->preview['thumb'] }}" alt="Preview image"></button>
                    @endif
                    @if($film !== null && ($film->tagline !== '' || $film->plot !== ''))
                        <div class="tv-details-episode tv-details-film">
                            @if($film->tagline !== '')<b class="tv-details-tagline">“{{ $film->tagline }}”</b>@endif
                            @if($film->plot !== '')<p class="tv-details-plot">{{ $film->plot }}</p>@endif
                        </div>
                    @endif
                    <dl class="tv-details-facts">
                        @foreach($facts as [$label, $value])
                            <div><dt @if($loop->first) data-part="facts label" @endif>{{ $label }}</dt><dd @if($loop->first) data-part="facts value" @endif>{{ $value }}</dd></div>
                        @endforeach
                    </dl>
                    @if($predb !== [])
                        <section class="tv-details-predb" aria-labelledby="predb-heading">
                            <h3 id="predb-heading">PreDB</h3>
                            <dl class="tv-details-facts">
                                @foreach($predb as [$label, $value])
                                    <div @class(['is-wide' => $label === 'Title'])><dt>{{ $label }}</dt><dd>{{ $value }}</dd></div>
                                @endforeach
                            </dl>
                        </section>
                    @endif
                </section>
                <section id="files" class="tv-details-panel" role="tabpanel" aria-labelledby="tab-files" data-details-panel hidden>
                    <div data-tab-content="files"><p class="tv-note">Loading the file list…</p></div>
                </section>
                <section id="media" class="tv-details-panel" role="tabpanel" aria-labelledby="tab-media" data-details-panel hidden>
                    <div data-tab-content="media">@if($row->mediaInfo === null)<p class="tv-note">No media information was captured for this release.</p>@else<p class="tv-note">Loading media info…</p>@endif</div>
                </section>
                <section id="nfo" class="tv-details-panel" role="tabpanel" aria-labelledby="tab-nfo" data-details-panel hidden>
                    <div data-tab-content="nfo">@if($row->nfo)<p class="tv-note">Loading the NFO…</p>@else<p class="tv-note">No NFO was posted with this release.</p>@endif</div>
                </section>
                <section id="comments" class="tv-details-panel tv-details-comments" role="tabpanel" aria-labelledby="tab-comments" data-details-panel hidden>
                    @include('details.partials.comments')
                </section>
            </div>
            @if($film !== null)
                <aside class="tv-about">
                    <h2 data-part="about the film heading">About the film</h2>
                    @if($film->genres !== [] || $film->tags !== [])
                        <div class="tv-show-tags">
                            @foreach($film->genres as $genreId => $genre)
                                <a class="tv-tag" href="{{ route('movies.films', ['genre' => [$genreId]]) }}">{{ $genre }}</a>
                            @endforeach
                            @foreach($film->tags as $tag)
                                <span class="tv-tag tv-tag-plain">{{ $tag }}</span>
                            @endforeach
                        </div>
                    @endif
                    @if($film->directors !== [])
                        <div class="tv-about-starring">Directed by {!! $people($film->directors) !!}</div>
                    @endif
                    @if($starring !== [])
                        <div class="tv-about-starring">Starring {!! $people($starring) !!}</div>
                    @endif
                    <a class="tv-about-link" href="{{ $filmUrl }}">Film page</a>
                </aside>
            @endif
        </div>
        @if($table !== null)
            <section @class(['tv-siblings', 'tv-list-end' => $similar === []]) id="releases" aria-labelledby="film-releases-heading" x-ref="releases" data-film-releases>
                @include('details.movies.releases')
            </section>
        @endif
        @if($similar !== [])
            <section class="tv-siblings tv-similar-releases" aria-labelledby="similar-releases-heading" data-similar-releases>
                <h2 id="similar-releases-heading">Similar releases</h2>
                @include('details.movies.table', ['rows' => $similar, 'current' => null, 'filmLine' => true, 'sortable' => 'data-similar-sort', 'sort' => 'posted', 'ascending' => false])
            </section>
        @endif
    </div>
</div>
@endsection
