<?php

declare(strict_types=1);

namespace App\Services\CollectionReconciliation;

use UnexpectedValueException;

/** Membership proof shared by pending collections and historical NZB inventories. */
final class PostingResolver
{
    /**
     * @param  list<PostingFile>  $files  Full bounded population, never a truncated query.
     * @param  array<string, string>  $heads  Exact message ID => independently acquired HEAD.
     * @param  array<string, string>  $metadata  Exact message ID => decoded PAR2 bytes.
     * @param  array<string, array{length: int, prefix: string, full?: string}>  $probes  Decoded evidence, not overview sizes.
     */
    public function resolve(array $files, array $heads, array $metadata, array $probes = []): PostingDecision
    {
        if (count($files) > 1024 || count(array_unique(array_column($files, 'sourceId'))) > 256) {
            return new PostingDecision([], 0, null, '', [], [], 'population_limit');
        }
        $bases = array_values(array_filter($files, static fn (PostingFile $file): bool => $file->isBasePar2()));
        if (count($bases) !== 1) {
            return new PostingDecision([], 0, null, '', [], [], 'missing_or_competing_base');
        }
        $base = $bases[0];
        try {
            $envelope = SourceEnvelope::validate($heads[$base->firstArticle()] ?? '', $base);
            $manifest = (new Par2Inventory)->parse($metadata[$base->firstArticle()] ?? '');
        } catch (UnexpectedValueException $e) {
            return new PostingDecision([], $base->total, null, '', [], [], $e->getMessage());
        }
        $names = [];
        foreach ($files as $file) {
            $names[$file->filename][] = $file;
        }
        $accepted = $unresolved = $evidence = [];
        $packets = $manifest->packets;
        $described = count($manifest->files);
        foreach ($files as $file) {
            try {
                if (! $file->hasCompleteSegments() || $file->total !== $base->total || $file->group !== $base->group
                    || abs($file->date - $base->date) > 1800) {
                    throw new UnexpectedValueException('incompatible_inventory');
                }
                $head = SourceEnvelope::validate($heads[$file->firstArticle()] ?? '', $file);
                if ($head->poster !== $envelope->poster || abs($head->date - $envelope->date) > 1800) {
                    throw new UnexpectedValueException('different_poster');
                }
                if ($file->isPar2()) {
                    $companion = $file === $base ? $manifest : (new Par2Inventory)->parse($metadata[$file->firstArticle()] ?? '', 1024 - $described, 16384 - $packets);
                    if ($file !== $base) {
                        $packets += $companion->packets;
                        $described += count($companion->files);
                    }
                    if ($companion->setId !== $manifest->setId || $companion->files !== $manifest->files
                        || count($names[$file->filename]) !== 1) {
                        throw new UnexpectedValueException('competing_par2');
                    }
                    $evidence[$file->fileId] = 'head_and_par2:'.hash('sha256', $metadata[$file->firstArticle()]);
                } else {
                    $description = $manifest->files[$file->filename] ?? null;
                    if ($description === null || ! str_contains($file->filename, '.')) {
                        throw new UnexpectedValueException('unlisted_filename');
                    }
                    $probe = $probes[$file->firstArticle()] ?? null;
                    if ($probe !== null && ! $this->matches($probe, $description)) {
                        throw new UnexpectedValueException('contradictory_payload');
                    }
                    if (count($names[$file->filename]) > 1) {
                        $matching = array_filter($names[$file->filename], function (PostingFile $candidate) use ($probes, $description): bool {
                            $probe = $probes[$candidate->firstArticle()] ?? null;

                            // An unprobed candidate still competes: absence of evidence is not exclusion.
                            return $probe === null || $this->matches($probe, $description);
                        });
                        if ($probe === null || count($matching) !== 1) {
                            throw new UnexpectedValueException('ambiguous_payload');
                        }
                    }
                    $evidence[$file->fileId] = $probe === null ? 'unique_filename_and_head:'.$description['id'] : 'decoded_payload_and_head:'.$description['id'];
                }
                $accepted[$file->fileId] = new PostingFile($file->sourceId, $file->fileId, $file->subject, $file->group,
                    $head->poster, $head->date, $file->declaredParts, $file->segments, $file->groups);
            } catch (UnexpectedValueException $e) {
                $unresolved[$file->fileId] = $e->getMessage();
            }
        }
        $ordinals = [];
        foreach ($accepted as $file) {
            $ordinals[$file->ordinal][] = $file->fileId;
        }
        foreach ($ordinals as $ids) {
            if (count($ids) > 1) {
                foreach ($ids as $id) {
                    $unresolved[$id] = 'conflicting_ordinal';
                    unset($accepted[$id]);
                }
            }
        }
        $rejectedSources = [];
        foreach ($files as $file) {
            if (! isset($accepted[$file->fileId])) {
                $rejectedSources[$file->sourceId] = true;
            }
        }
        foreach ($accepted as $id => $file) {
            if (isset($rejectedSources[$file->sourceId])) {
                $unresolved[$id] = 'mixed_source';
                unset($accepted[$id]);
            }
        }
        if (! isset($accepted[$base->fileId])) {
            $accepted = [];
        }
        $sources = array_unique(array_column($accepted, 'sourceId'));
        if (count($sources) < 2) {
            $accepted = [];
        }

        return new PostingDecision(array_values($accepted), $base->total, $manifest->setId,
            substr($base->filename, 0, -5), $unresolved, $evidence, $accepted === [] ? 'no_verified_union' : 'verified_union');
    }

    /**
     * @param  array{length: int, prefix: string, full?: string}  $probe
     * @param  array{id: string, size: int, full: string, prefix: string}  $description
     */
    private function matches(array $probe, array $description): bool
    {
        return $probe['length'] === $description['size'] && hash_equals($description['prefix'], $probe['prefix'])
            && (! isset($probe['full']) || hash_equals($description['full'], $probe['full']));
    }
}
