<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Search\Contracts\SearchServiceInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

class SearchSuggestController extends Controller
{
    private SearchServiceInterface $searchEngine;

    public function __construct(SearchServiceInterface $searchEngine)
    {
        $this->searchEngine = $searchEngine;
    }

    /**
     * Get spell correction suggestions ("Did you mean?").
     */
    public function suggest(Request $request): JsonResponse
    {
        $query = $request->input('q', '');
        $index = $request->input('index') ?? $this->searchEngine->getReleasesIndex();

        // Rate limiting: 30 requests per minute per IP
        $key = 'suggest:'.$request->ip();
        if (RateLimiter::tooManyAttempts($key, 30)) {
            return response()->json([
                'success' => false,
                'error' => 'Too many requests',
            ], 429);
        }
        RateLimiter::hit($key, 60);

        if (! $this->searchEngine->isSuggestEnabled()) {
            return response()->json([
                'success' => true,
                'suggestions' => [],
            ]);
        }

        if (empty($query)) {
            return response()->json([
                'success' => true,
                'suggestions' => [],
            ]);
        }

        try {
            $suggestions = $this->searchEngine->suggest($query, $index);

            // Get the best suggestion (highest doc count)
            $bestSuggestion = null;
            if (! empty($suggestions)) {
                usort($suggestions, fn ($a, $b) => $b['docs'] - $a['docs']);
                $bestSuggestion = $suggestions[0]['suggest'] ?? null;
            }

            return response()->json([
                'success' => true,
                'query' => $query,
                'suggestions' => array_map(fn ($s) => $s['suggest'], $suggestions),
                'best' => $bestSuggestion,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => true,
                'query' => $query,
                'suggestions' => [],
            ]);
        }
    }
}
