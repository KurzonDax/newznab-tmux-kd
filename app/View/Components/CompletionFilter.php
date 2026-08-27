<?php

declare(strict_types=1);

namespace App\View\Components;

use App\Support\ReleaseCompletion;
use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/**
 * Minimum-completion threshold picker for the browse and search toolbars.
 *
 * The choice travels in the URL as `minc`, the same way the sort control and
 * the list/covers toggle carry theirs, so it survives paging, re-sorting, and
 * switching views instead of resetting on every click.
 */
class CompletionFilter extends Component
{
    public int $currentThreshold;

    public string $currentLabel;

    public string $baseUrl;

    /** @var array<string, mixed> */
    public array $queryParams;

    /** @var array<int, string> */
    public array $thresholdUrls = [];

    /** @var array<int, string> */
    public array $thresholds;

    /**
     * @param  array<string, mixed>|null  $queryParams
     */
    public function __construct(
        ?int $currentThreshold = null,
        ?string $baseUrl = null,
        ?array $queryParams = null,
    ) {
        $this->thresholds = ReleaseCompletion::THRESHOLDS;
        $this->currentThreshold = ReleaseCompletion::normalizeThreshold(
            $currentThreshold ?? request(ReleaseCompletion::REQUEST_KEY)
        );
        $this->currentLabel = $this->thresholds[$this->currentThreshold];
        $this->baseUrl = $baseUrl ?? request()->url();
        $this->queryParams = $queryParams ?? request()->except([ReleaseCompletion::REQUEST_KEY, 'page']);

        foreach ($this->thresholds as $threshold => $label) {
            $params = $threshold > 0
                ? array_merge($this->queryParams, [ReleaseCompletion::REQUEST_KEY => $threshold])
                : $this->queryParams;
            $this->thresholdUrls[$threshold] = $params === []
                ? $this->baseUrl
                : $this->baseUrl.'?'.http_build_query($params);
        }
    }

    public function render(): View|Closure|string
    {
        return view('components.completion-filter');
    }
}
