<?php

declare(strict_types=1);

namespace App\Data;

use App\Enums\BrowseRoot;
use App\Support\ReleaseCompletion;
use Illuminate\Http\Request;

final readonly class ReleaseBrowserState
{
    public function __construct(
        public BrowseRoot $root,
        public string $group,
        public bool $watching,
        public bool $basketOnly,
        public int $minCompletion,
    ) {}

    public static function fromRequest(Request $request, BrowseRoot $root, bool $basketOnly = false): self
    {
        $group = $request->input('group', '');

        return new self(
            root: $root,
            group: is_string($group) ? $group : '',
            watching: in_array($root, [BrowseRoot::All, BrowseRoot::Movies, BrowseRoot::Tv], true) && $request->boolean('watching'),
            basketOnly: $basketOnly,
            minCompletion: ReleaseCompletion::normalizeThreshold($request->input(ReleaseCompletion::REQUEST_KEY)),
        );
    }
}
