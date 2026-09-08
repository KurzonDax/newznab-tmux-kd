<?php

declare(strict_types=1);

namespace App\Services\ObfuscationRecovery;

use Closure;
use InvalidArgumentException;

final class RecoveryAssociation
{
    /** @param list<array<string,mixed>> $runs */
    public function mediaIndex(array $runs): string
    {
        $indexes = array_values(array_filter($runs, static fn (array $run): bool => (int) $run['advertised_total'] === 1));
        if (count($indexes) !== 1 || $indexes[0]['state'] !== 'count_exact' || (int) $indexes[0]['observed_count'] !== 1) {
            throw new InvalidArgumentException('ambiguous_media_index');
        }

        return $indexes[0]['first_message_id'];
    }

    /**
     * @param  list<array<string,mixed>>  $runs
     * @param  Closure(array<string,mixed>): list<array{message_id:string,embedded_timestamp_ms:int}>  $earliest
     * @return list<array<string,mixed>>
     */
    public function mediaPreflight(RecoveryInventory $inventory, array $runs, Closure $earliest): array
    {
        $index = $this->mediaIndex($runs);
        $files = (new RecoveryInventoryPolicy)->media($inventory);
        $used = [$index => true];
        $targets = 0;
        foreach ($files as &$file) {
            $candidates = array_values(array_filter($runs, static fn (array $run): bool => (int) $run['advertised_total'] === $file['expected_total']));
            if (count($candidates) !== 1 || $candidates[0]['state'] !== 'count_exact'
                || (int) $candidates[0]['observed_count'] !== $file['expected_total']
                || $candidates[0]['first_message_id'] === $index) {
                throw new InvalidArgumentException('ambiguous_media_membership');
            }
            $file['run'] = $candidates[0];
        }
        unset($file);
        foreach ($files as &$file) {
            $block = $earliest($file['run']);
            if (count($block) < 1 || count($block) > 8 || $targets + count($block) > 64) {
                throw new InvalidArgumentException('anchor_target_cap');
            }
            usort($block, static fn (array $a, array $b): int => strcmp($a['message_id'], $b['message_id']));
            $file['targets'] = [];
            foreach ($block as $header) {
                if ($header['embedded_timestamp_ms'] !== (int) $file['run']['start_ms'] || isset($used[$header['message_id']])) {
                    throw new InvalidArgumentException('invalid_earliest_block');
                }
                $used[$header['message_id']] = true;
                $file['targets'][] = $header['message_id'];
                $targets++;
            }
        }
        unset($file);

        return $files;
    }

    /** @param list<string> $targets
     * @param  array<string,RecoveryArticle>  $evidence
     * @return array{message_id:string,format:string,observed_format:?string,small_file_verified:bool,article:RecoveryArticle}
     */
    public function anchor(RecoveryProtectedFile $file, array $targets, array $evidence, ?string $declaredFormat): array
    {
        if (count($targets) < 1 || count($targets) > 8 || count(array_unique($targets)) !== count($targets)) {
            throw new InvalidArgumentException('invalid_anchor_targets');
        }
        $anchor = null;
        $expected = RecoveryInventoryPolicy::parts($file->size);
        foreach ($targets as $target) {
            $article = $evidence[$target] ?? null;
            if ($article === null) {
                throw new InvalidArgumentException('incomplete_anchor_evidence');
            }
            if ($article->total !== $expected || $article->fileSize !== $file->size) {
                throw new InvalidArgumentException('anchor_declaration_conflict');
            }
            if ($article->begin !== ($article->part - 1) * 716800 + 1 || $article->end !== min($article->part * 716800, $file->size)) {
                throw new InvalidArgumentException('anchor_range_conflict');
            }
            if ($article->part !== 1) {
                continue;
            }
            $prefixLength = min(16384, $file->size);
            if ($article->begin !== 1 || $article->end !== min(716800, $file->size)) {
                throw new InvalidArgumentException('anchor_range_conflict');
            }
            if (strlen($article->data) < $prefixLength || md5(substr($article->data, 0, $prefixLength), true) !== $file->prefixMd5) {
                throw new InvalidArgumentException('anchor_content_conflict');
            }
            if ($file->size <= 16384 && (! $article->complete || md5($article->data, true) !== $file->md5)) {
                throw new InvalidArgumentException('incomplete_small_file_anchor');
            }
            if ($anchor !== null) {
                throw new InvalidArgumentException('multiple_matching_anchors');
            }
            $observed = (new RecoveryFormats)->detect($article->data);
            $format = $observed ?? (in_array($declaredFormat, ['mkv', 'mp4'], true) ? $declaredFormat : null);
            if (! in_array($format, ['mkv', 'mp4', 'rar4'], true) || ($declaredFormat !== null && $format !== $declaredFormat)
                || ($format === 'rar4' && $declaredFormat !== 'rar4')) {
                throw new InvalidArgumentException('unsupported_or_conflicting_format');
            }
            $anchor = ['message_id' => $target, 'format' => $format, 'observed_format' => $observed, 'small_file_verified' => $file->size <= 16384, 'article' => $article];
        }
        if ($anchor === null) {
            throw new InvalidArgumentException('missing_part_one_anchor');
        }

        return $anchor;
    }
}
