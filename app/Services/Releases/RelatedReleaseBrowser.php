<?php

declare(strict_types=1);

namespace App\Services\Releases;

use App\Data\ReleaseBrowserState;
use App\Data\ReleaseRowData;
use App\Enums\BrowseRoot;
use App\Models\Release;
use App\Models\User;
use App\Support\ReleaseQuality;
use Illuminate\Pagination\LengthAwarePaginator;

final class RelatedReleaseBrowser
{
    public function __construct(private readonly ReleaseBrowserQuery $browser, private readonly ReleaseBrowseService $rows, private readonly TitleMetadataLoader $titles) {}

    /** @return array{otherReleases: LengthAwarePaginator<int, \stdClass>, otherReleaseCount: int} */
    public function forRelease(Release $release, User $user): array
    {
        /** @var ReleaseRowData $row */
        $row = $release->getAttribute('row_data');
        $entity = $row->entity;
        if ($entity === null) {
            return ['otherReleases' => new LengthAwarePaginator([], 0, 10), 'otherReleaseCount' => 0];
        }
        $root = $entity->root === 'anime' ? BrowseRoot::Tv : BrowseRoot::from($entity->root);
        $state = new ReleaseBrowserState(root: $root, view: 'table', size: 's', per: 100, thumbs: false,
            page: 1, group: '', posterIdentity: '', categoryId: null, query: '', sort: 'newest', filters: [],
            watching: false, basketOnly: false, minCompletion: 0, tableOnly: true);
        $key = $entity->root === 'anime' ? 'anidbid' : $this->titles->source($root)['releaseKey'];
        $query = $this->browser->matchingQuery($state, $user)->where('r.'.$key, $entity->id)->where('r.id', '!=', $release->id);
        $rows = $query->orderByDesc('r.adddate')->orderByDesc('r.id')->paginate(10, ['r.*'], 'other_page')->withQueryString()->fragment('other-releases');
        $this->rows->loadReleaseRows($rows);
        foreach ($rows as $related) {
            $related->related_label = ReleaseQuality::label($root, release_display_name($related)) ?: $related->row_data->category;
        }

        return ['otherReleases' => $rows, 'otherReleaseCount' => $rows->total()];
    }
}
