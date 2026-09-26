<?php

declare(strict_types=1);

namespace App\Services\Releases;

use App\Data\ReleaseBrowserState;
use App\Enums\BrowseRoot;
use App\Models\Category;
use App\Models\User;
use App\Support\ReleaseQuality;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

final class TitleReleaseBrowser
{
    public function __construct(
        private readonly TitleMetadataLoader $metadata,
        private readonly ReleaseBrowserQuery $browser,
        private readonly ReleaseBrowseService $rows,
    ) {}

    /** @return array<string, mixed> */
    public function load(BrowseRoot $root, string $id, User $user, Request $request): array
    {
        $categoryId = null;
        $category = $request->input('t');
        if (is_scalar($category) && ctype_digit((string) $category) && (int) $category > 0 && (int) $category !== $root->categoryId()) {
            $categoryId = (int) $category;
            abort_unless(Category::query()->whereKey($categoryId)->where('root_categories_id', $root->categoryId())->exists(), 404);
            abort_if(in_array($categoryId, (array) $user->categoryexclusions), 403);
        }
        $state = new ReleaseBrowserState(root: $root, view: 'table', size: 's', per: 100, thumbs: false,
            page: max(1, (int) $request->integer('page', 1)), group: '', posterIdentity: '', categoryId: $categoryId,
            query: '', sort: 'newest', filters: [], watching: false, basketOnly: false, minCompletion: 0, tableOnly: true);
        $source = $this->metadata->source($root);
        $query = $this->browser->matchingQuery($state, $user)->where('r.'.$source['releaseKey'], $id);
        $references = (clone $query)->get(['r.id', 'r.searchname', 'r.display_name', 'r.adddate']);
        foreach ($references as $reference) {
            $reference->quality = ReleaseQuality::fromName($root, release_display_name($reference));
        }
        $qualities = $references->pluck('quality')->filter()->unique()->sortBy(fn (string $quality): int => $this->qualityOrder($quality))->values()->all();
        $requestedQualities = $request->input('quality', []);
        $activeQualities = is_array($requestedQualities) ? array_values(array_intersect($qualities, array_filter($requestedQualities, 'is_string'))) : [];
        $selected = $references->filter(static fn (object $release): bool => $activeQualities === [] || in_array($release->quality, $activeQualities, true))
            ->sort(static fn (object $left, object $right): int => strcmp((string) $right->adddate, (string) $left->adddate) ?: ($right->id <=> $left->id))
            ->values();
        $page = min($state->page, max(1, (int) ceil($selected->count() / 100)));
        $ids = $selected->slice(($page - 1) * 100, 100)->pluck('id');
        $releaseRows = $ids->isEmpty() ? collect() : (clone $query)->whereIn('r.id', $ids)->get(['r.*'])->keyBy('id');
        $releaseRows = $ids->map(static fn ($id): object => $releaseRows->get($id))->values();
        $this->rows->loadReleaseRows($releaseRows);
        $results = new LengthAwarePaginator($releaseRows, $selected->count(), 100, $page, ['path' => $request->url(), 'query' => $request->except('_fragment')]);

        return [
            'results' => $results, 'browserState' => $state,
            'qualities' => $qualities, 'activeQualities' => $activeQualities,
            'releaseCount' => $references->count(), 'latestRelease' => $references->max('adddate'),
            'bestQuality' => $qualities[0] ?? null,
        ];
    }

    private function qualityOrder(string $quality): int
    {
        $order = ['2160p', '2160i', '1080p', '1080i', '720p', '720i', '576p', '576i', '480p', '480i',
            '24-bit FLAC', 'FLAC', 'ALAC', 'WAV', 'MP3', 'AAC', 'OPUS', 'OGG', 'EPUB', 'PDF', 'MOBI', 'AZW3', 'AZW'];

        return (int) array_search($quality, $order, true);
    }
}
