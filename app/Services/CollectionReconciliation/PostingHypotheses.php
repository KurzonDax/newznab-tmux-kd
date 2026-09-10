<?php

declare(strict_types=1);

namespace App\Services\CollectionReconciliation;

/** A nearby population is a search space, not one posting. */
final class PostingHypotheses
{
    /**
     * @param  list<PostingFile>  $files
     * @param  callable(list<PostingFile>): PostingDecision  $resolve
     * @return list<PostingDecision>
     */
    public function resolve(array $files, callable $resolve): array
    {
        $bases = array_values(array_filter($files, static fn (PostingFile $file): bool => $file->isBasePar2()));
        usort($bases, static fn (PostingFile $a, PostingFile $b): int => $a->firstArticle() <=> $b->firstArticle());
        $decisions = $ownership = [];
        foreach ($bases as $base) {
            $excluded = [];
            foreach ($bases as $other) {
                if ($other->fileId !== $base->fileId) {
                    $excluded[$other->sourceId] = true;
                }
            }
            foreach ($files as $file) {
                if ($file->group !== $base->group || $file->poster !== $base->poster || $file->total !== $base->total || abs($file->date - $base->date) > 1800) {
                    $excluded[$file->sourceId] = true;
                }
            }
            $hypothesis = array_values(array_filter($files, static fn (PostingFile $file): bool => ! isset($excluded[$file->sourceId])
                && $file->group === $base->group && $file->poster === $base->poster && $file->total === $base->total
                && abs($file->date - $base->date) <= 1800));
            if (! in_array($base, $hypothesis, true)) {
                continue;
            }
            $decision = $resolve($hypothesis);
            $decisions[] = $decision;
            $potential = array_fill_keys(array_column($decision->accepted, 'fileId'), true);
            $disproved = [];
            foreach ($hypothesis as $file) {
                $reason = $decision->unresolved[$file->fileId] ?? null;
                if (in_array($reason, ['unlisted_filename', 'contradictory_payload', 'different_poster', 'incompatible_inventory'], true)) {
                    $disproved[$file->sourceId] = true;
                } elseif (($reason !== null && $reason !== 'mixed_source') || ($decision->accepted === [] && $decision->unresolved === [])) {
                    $potential[$file->fileId] = true;
                }
            }
            $keys = [];
            foreach ($hypothesis as $file) {
                if (! isset($potential[$file->fileId]) || isset($disproved[$file->sourceId])) {
                    continue;
                }
                $keys['source:'.$file->sourceId] = true;
                $keys['file:'.$file->ordinal.':'.$file->filename] = true;
                foreach ($file->segments as $segment) {
                    $keys['article:'.trim($segment['messageid'], "<> \t\r\n")] = true;
                }
            }
            $ownership[] = $keys;
        }
        foreach ($decisions as $index => $decision) {
            foreach ($ownership as $other => $keys) {
                if ($other !== $index && array_intersect_key($ownership[$index], $keys) !== []) {
                    $decisions[$index] = new PostingDecision([], $decision->declaredTotal, $decision->setId, $decision->label,
                        $decision->unresolved, $decision->evidence, 'competing_hypothesis');
                    break;
                }
            }
        }

        return $decisions;
    }
}
