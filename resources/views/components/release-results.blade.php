@props([
    'results',
    'showThumbs' => false,
    'dateField' => 'adddate',
])

@php
    $shouldShowThumbs = filter_var($showThumbs, FILTER_VALIDATE_BOOL);
    $activeDateField = (string) $dateField;
@endphp

<!-- Results Table (Desktop) -->
<div class="hidden md:block overflow-x-auto">
    <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
        <thead class="bg-gray-100 dark:bg-gray-900">
            <tr>
                <th class="px-3 py-3 text-left">
                    <input type="checkbox" class="rounded border-gray-300 dark:border-gray-600 text-primary-600 dark:text-primary-500 focus:ring-primary-500 dark:focus:ring-primary-400 dark:bg-gray-700" id="chkSelectAll" x-model="allChecked" @change="toggleAll()">
                </th>
                <th class="px-3 py-3 text-left text-xs font-medium text-gray-700 dark:text-gray-300 uppercase tracking-wider">Name</th>
                <th class="px-3 py-3 text-left text-xs font-medium text-gray-700 dark:text-gray-300 uppercase tracking-wider">Category</th>
                <th class="px-3 py-3 text-left text-xs font-medium text-gray-700 dark:text-gray-300 uppercase tracking-wider">Added</th>
                <th class="px-3 py-3 text-left text-xs font-medium text-gray-700 dark:text-gray-300 uppercase tracking-wider">Size</th>
                <th class="px-3 py-3 text-left text-xs font-medium text-gray-700 dark:text-gray-300 uppercase tracking-wider">Files</th>
                <th class="px-3 py-3 text-left text-xs font-medium text-gray-700 dark:text-gray-300 uppercase tracking-wider">Stats</th>
                <th class="px-3 py-3 text-left text-xs font-medium text-gray-700 dark:text-gray-300 uppercase tracking-wider">Action</th>
            </tr>
        </thead>
        <tbody class="surface-panel divide-y divide-gray-200 dark:divide-gray-700">
            @foreach($results as $result)
                @php
                    $reportedCount = (int) ($result->total_report_count ?? $result->report_count ?? 0);
                    $responseCount = (int) ($result->report_response_count ?? 0);
                    $sizeLabel = $result->row_data?->size ?? \App\Support\ReleaseSize::format((float) ($result->size ?? 0));
                    $dateValue = $result->{$activeDateField} ?? $result->adddate ?? $result->postdate ?? null;
                @endphp
                <tr class="hover:bg-gray-50 dark:bg-gray-900 dark:hover:bg-gray-700 transition">
                    <td class="px-3 py-4 whitespace-nowrap">
                        <input type="checkbox" class="chkRelease rounded border-gray-300 dark:border-gray-600 text-primary-600 dark:text-primary-500 focus:ring-primary-500 dark:focus:ring-primary-400 dark:bg-gray-700" name="release[]" value="{{ $result->guid }}" @change="onCheckboxChange()">
                    </td>
                    <td class="px-3 py-4">
                        <div class="flex items-start">
                            @if($shouldShowThumbs)
                                @php
                                    $coverUrl = ($result->cover ?? false) ? $result->cover : getReleaseCover($result);
                                    $hasValidCover = $coverUrl && !str_contains($coverUrl, 'no-cover.png');
                                @endphp
                                @if($hasValidCover)
                                    <a href="{{ url('/details/' . $result->guid) }}" class="shrink-0 bg-gray-100 dark:bg-gray-700 rounded mr-3" x-show="showThumbs" @unless(request()->query('thumbs') === '1') x-cloak @endunless>
                                        <img src="{{ request()->query('thumbs') === '1' ? $coverUrl : '' }}" x-bind:src="showThumbs ? '{{ $coverUrl }}' : ''" class="w-12 h-16 object-cover rounded shadow-sm hover:shadow-md transition" alt="Cover" loading="lazy">
                                    </a>
                                @endif
                            @endif
                            <div class="flex-1">
                                <div class="flex items-center gap-2 flex-wrap">
                                    <a href="{{ url('/details/' . $result->guid) }}" class="text-primary-600 dark:text-primary-400 hover:text-primary-800 dark:hover:text-primary-300 font-medium wrap-break-word break-all">{{ release_display_name($result) }}</a>
                                    @if($reportedCount > 0)
                                        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-orange-100 dark:bg-orange-900 text-orange-800 dark:text-orange-200"
                                              title="Reported: {{ $result->all_report_reasons ?? \App\Models\ReleaseReport::reasonKeysToLabels($result->report_reasons ?? '') }} | Original report: {{ $result->latest_report_reason ?? 'Unknown' }} - {{ $result->latest_report_description ?? 'No additional report details were provided.' }}">
                                            <i class="fas fa-flag mr-1"></i> Reported ({{ $reportedCount }})
                                        </span>
                                    @endif
                                    @if($responseCount > 0)
                                        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-primary-100 dark:bg-primary-900 text-primary-800 dark:text-primary-200"
                                              title="Staff response available on release details">
                                            <i class="fas fa-reply mr-1"></i> Response
                                        </span>
                                    @endif
                                    @if(!empty($result->failed_count) && $result->failed_count > 0)
                                        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-red-100 dark:bg-red-900 text-red-800 dark:text-red-200"
                                              title="{{ $result->failed_count }} user(s) reported download failure">
                                            <i class="fas fa-exclamation-triangle mr-1"></i> Failed ({{ $result->failed_count }})
                                        </span>
                                    @endif
                                    <x-release-facts :release="$result" />

                                </div>
                                <div class="text-xs text-gray-500 dark:text-gray-400 mt-1 flex flex-wrap gap-2">
                                    @if(!empty($result->videos_id) && (int) $result->videos_id > 0)
                                        <x-entity-chip root="tv" :title="$result->row_data?->entity?->title ?? 'View Series'" :href="url('/series/' . $result->videos_id)" />
                                    @endif
                                    <x-origin-chip kind="group" :value="$result->group_name ?? ''" />
                                    @if(!empty($result->postdate))
                                        <span class="inline-flex items-center px-2 py-0.5 rounded bg-green-100 dark:bg-green-900 text-green-800 dark:text-green-200">
                                            <i class="fas fa-calendar mr-1"></i> Posted: {{ userDate($result->postdate, 'M d, Y H:i') }}
                                        </span>
                                    @endif
                                    <x-origin-chip kind="poster" :value="$result->fromname ?? ''" />
                                </div>
                            </div>
                        </div>
                    </td>
                    <td class="px-3 py-4 whitespace-nowrap">
                        <x-chip variant="primary" pill>{{ $result->category_name ?? 'Other' }}</x-chip>
                    </td>
                    <td class="px-3 py-4 whitespace-nowrap text-sm text-gray-600 dark:text-gray-400">
                        {{ $dateValue ? userDateDiffForHumans($dateValue) : 'Unknown' }}
                    </td>
                    <td class="px-3 py-4 whitespace-nowrap text-sm text-gray-600 dark:text-gray-400">
                        {{ $sizeLabel }}
                    </td>
                    <td class="px-3 py-4 whitespace-nowrap text-sm text-gray-600 dark:text-gray-400">
                        @if(($result->totalpart ?? 0) > 0)
                            <button type="button"
                                    class="filelist-badge text-primary-600 dark:text-primary-400 hover:text-primary-800 dark:hover:text-primary-300 font-medium cursor-pointer hover:underline"
                                    data-guid="{{ $result->guid }}"
                                    title="View file list">
                                {{ $result->totalpart ?? 0 }}
                            </button>
                        @else
                            {{ $result->totalpart ?? 0 }}
                        @endif
                    </td>
                    <td class="px-3 py-4 whitespace-nowrap text-sm text-gray-600 dark:text-gray-400">
                        <div class="flex items-center gap-2">
                            <span title="Grabs"><i class="fas fa-download text-green-600 dark:text-green-400"></i> {{ $result->grabs ?? 0 }}</span>
                            <span title="Comments"><i class="fas fa-comment text-primary-600 dark:text-primary-400"></i> {{ $result->comments ?? 0 }}</span>
                        </div>
                    </td>
                    <td class="px-3 py-4 whitespace-nowrap">
                        <div class="flex items-center gap-1 flex-wrap">
                            <a href="{{ url('/getnzb/' . $result->guid) }}" class="download-nzb release-action release-action-download" title="Download NZB">
                                <i class="fa fa-download"></i>
                            </a>
                            <a href="{{ url('/details/' . $result->guid) }}" class="release-action release-action-primary" title="View Details">
                                <i class="fa fa-info"></i>
                            </a>
                            <a href="#" class="add-to-cart release-action release-action-muted" data-guid="{{ $result->guid }}" title="Add to Cart">
                                <i class="icon_cart fa fa-shopping-basket"></i>
                            </a>
                            @if(!empty($result->imdbid) && imdb_id_is_valid($result->imdbid))
                                <a href="{{ url('/mymovies?id=add&imdb=' . $result->imdbid) }}"
                                   class="px-2 py-1 bg-primary-600 dark:bg-primary-700 text-white rounded-lg hover:bg-primary-700 dark:hover:bg-primary-800 transition text-sm"
                                   title="Add to My Movies">
                                    <i class="fa fa-film"></i>
                                </a>
                            @endif
                            <x-report-button :release-name="release_display_name($result)" :release-id="$result->id" :reported-count="$reportedCount" variant="icon" />
                        </div>
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>

<!-- Mobile Card View -->
<div class="md:hidden space-y-3 px-4 py-4">
    @foreach($results as $result)
        @php
            $reportedCount = (int) ($result->total_report_count ?? $result->report_count ?? 0);
            $responseCount = (int) ($result->report_response_count ?? 0);
            $sizeLabel = $result->row_data?->size ?? \App\Support\ReleaseSize::format((float) ($result->size ?? 0));
            $dateValue = $result->{$activeDateField} ?? $result->adddate ?? $result->postdate ?? null;
        @endphp
        <div class="surface-panel border rounded-xl p-4 hover:shadow-md transition">
            <div class="flex items-start gap-3">
                <input type="checkbox" class="chkRelease rounded border-gray-300 dark:border-gray-600 text-primary-600 dark:text-primary-500 focus:ring-primary-500 dark:focus:ring-primary-400 dark:bg-gray-700 mt-1" name="release[]" value="{{ $result->guid }}" @change="onCheckboxChange()">
                <div class="flex-1 min-w-0">
                    @if($shouldShowThumbs)
                        @php
                            $mCoverUrl = ($result->cover ?? false) ? $result->cover : getReleaseCover($result);
                            $mHasCover = $mCoverUrl && !str_contains($mCoverUrl, 'no-cover.png');
                        @endphp
                        @if($mHasCover)
                            <a href="{{ url('/details/' . $result->guid) }}" class="block mb-2 bg-gray-100 dark:bg-gray-700 rounded-lg" x-show="showThumbs" @unless(request()->query('thumbs') === '1') x-cloak @endunless>
                                <img src="{{ request()->query('thumbs') === '1' ? $mCoverUrl : '' }}" x-bind:src="showThumbs ? '{{ $mCoverUrl }}' : ''" class="w-16 h-20 object-cover rounded-lg shadow-sm" alt="Cover" loading="lazy">
                            </a>
                        @endif
                    @endif
                    <a href="{{ url('/details/' . $result->guid) }}" class="text-primary-600 dark:text-primary-400 hover:text-primary-800 dark:hover:text-primary-300 font-medium wrap-break-word text-base break-all">
                        {{ release_display_name($result) }}
                    </a>
                    <div class="flex flex-wrap items-center gap-2 mt-2">
                        @if($reportedCount > 0)
                            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-orange-100 dark:bg-orange-900 text-orange-800 dark:text-orange-200"
                                  title="Reported: {{ $result->all_report_reasons ?? \App\Models\ReleaseReport::reasonKeysToLabels($result->report_reasons ?? '') }} | Original report: {{ $result->latest_report_reason ?? 'Unknown' }} - {{ $result->latest_report_description ?? 'No additional report details were provided.' }}">
                                <i class="fas fa-flag mr-1"></i> Reported ({{ $reportedCount }})
                            </span>
                        @endif
                        @if($responseCount > 0)
                            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-primary-100 dark:bg-primary-900 text-primary-800 dark:text-primary-200"
                                  title="Staff response available on release details">
                                <i class="fas fa-reply mr-1"></i> Response
                            </span>
                        @endif
                        @if(!empty($result->failed_count) && $result->failed_count > 0)
                            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-red-100 dark:bg-red-900 text-red-800 dark:text-red-200"
                                  title="{{ $result->failed_count }} user(s) reported download failure">
                                <i class="fas fa-exclamation-triangle mr-1"></i> Failed ({{ $result->failed_count }})
                            </span>
                        @endif
                        <x-release-facts :release="$result" />
                    </div>
                    <div class="flex flex-wrap items-center gap-2 mt-2 text-sm text-gray-600 dark:text-gray-400">
                        <x-chip variant="primary" pill>{{ $result->category_name ?? 'Other' }}</x-chip>
                        <span><i class="fas fa-clock mr-1"></i>{{ $dateValue ? userDateDiffForHumans($dateValue) : 'Unknown' }}</span>
                        <span><i class="fas fa-hdd mr-1"></i>{{ $sizeLabel }}</span>
                        <span><i class="fas fa-file mr-1"></i>{{ $result->totalpart ?? 0 }} files</span>
                        <span title="Grabs"><i class="fas fa-download text-green-600 dark:text-green-400 mr-1"></i>{{ $result->grabs ?? 0 }}</span>
                        <span title="Comments"><i class="fas fa-comment text-primary-600 dark:text-primary-400 mr-1"></i>{{ $result->comments ?? 0 }}</span>
                                    <x-origin-chip kind="poster" :value="$result->fromname ?? ''" />
                    </div>
                    <div class="mt-3 flex gap-1 flex-wrap">
                        <a href="{{ url('/getnzb/' . $result->guid) }}" class="download-nzb release-action release-action-download px-3 py-1.5" title="Download NZB">
                            <i class="fa fa-download"></i>
                        </a>
                        <a href="{{ url('/details/' . $result->guid) }}" class="release-action release-action-primary px-3 py-1.5" title="View Details">
                            <i class="fa fa-info"></i>
                        </a>
                        <a href="#" class="add-to-cart release-action release-action-muted px-3 py-1.5" data-guid="{{ $result->guid }}" title="Add to Cart">
                            <i class="icon_cart fa fa-shopping-basket"></i>
                        </a>
                        @if(!empty($result->imdbid) && imdb_id_is_valid($result->imdbid))
                            <a href="{{ url('/mymovies?id=add&imdb=' . $result->imdbid) }}"
                               class="px-3 py-1.5 bg-primary-600 dark:bg-primary-700 text-white rounded-lg hover:bg-primary-700 dark:hover:bg-primary-800 transition text-sm"
                               title="Add to My Movies">
                                <i class="fa fa-film"></i>
                            </a>
                        @endif
                        <x-report-button :release-name="release_display_name($result)" :release-id="$result->id" :reported-count="$reportedCount" variant="icon" />
                    </div>
                </div>
            </div>
        </div>
    @endforeach
</div>
