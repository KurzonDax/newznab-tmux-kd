<?php

declare(strict_types=1);

namespace App\Services\ObfuscationRecovery;

use InvalidArgumentException;

final class RecoveryRarAssociation
{
    /**
     * @param  list<array<string,mixed>>  $blocks
     * @return list<array<string,mixed>>
     */
    public function preflight(RecoveryInventory $inventory, int $length, int $train, int $payloadCount, string $indexId, array $blocks): array
    {
        $files = (new RecoveryInventoryPolicy)->rar($inventory, $length, $train, $payloadCount);
        if (count($blocks) !== count($files)) {
            throw new InvalidArgumentException('rar_block_count_mismatch');
        }
        $used = [$indexId => true];
        $startTargets = 0;
        $ordinal = 1;
        foreach ($files as $index => &$file) {
            $block = $blocks[$index];
            if ($block['start_ordinal'] !== $ordinal || $block['end_ordinal'] !== $ordinal + $file['expected_total'] - 1
                || $block['observed_count'] !== $file['expected_total']) {
                throw new InvalidArgumentException('rar_block_membership_mismatch');
            }
            $ordinal += $file['expected_total'];
            if ($block['terminal_count'] !== 1) {
                throw new InvalidArgumentException('ambiguous_rar_terminal');
            }
            $earliest = $block['earliest'];
            if (count($earliest) < 1 || count($earliest) > 8 || $startTargets + count($earliest) > 64) {
                throw new InvalidArgumentException('anchor_target_cap');
            }
            usort($earliest, static fn (array $a, array $b): int => strcmp($a['message_id'], $b['message_id']));
            $file['targets'] = [];
            foreach ($earliest as $header) {
                if ($header['embedded_timestamp_ms'] !== $block['start_ms'] || isset($used[$header['message_id']])) {
                    throw new InvalidArgumentException('invalid_earliest_block');
                }
                $used[$header['message_id']] = true;
                $file['targets'][] = $header['message_id'];
                $startTargets++;
            }
            $terminal = $block['terminal_message_id'];
            if (isset($used[$terminal]) && ($file['expected_total'] !== 1 || ! in_array($terminal, $file['targets'], true))) {
                throw new InvalidArgumentException('ambiguous_rar_terminal');
            }
            $used[$terminal] = true;
            $file['terminal_target'] = $terminal;
            $file['block'] = $block;
        }
        unset($file);

        return $files;
    }

    /** @param list<array<string,mixed>> $files
     * @param  array<string,RecoveryArticle>  $evidence
     * @return list<array<string,mixed>>
     */
    public function verify(array $files, array $evidence): array
    {
        $association = new RecoveryAssociation;
        $headers = new RecoveryRarHeaders;
        foreach ($files as &$file) {
            $protected = $file['file'];
            $anchor = $association->anchor($protected, $file['targets'], $evidence, 'rar4');
            $archive = $headers->inspect($anchor['article']->data);
            if ($archive['encrypted']) {
                throw new InvalidArgumentException('rar_encryption_unsupported');
            }
            if ($archive['first_volume'] !== ($file['archive_ordinal'] === 1)) {
                throw new InvalidArgumentException('rar_volume_order_conflict');
            }
            $terminal = $evidence[$file['terminal_target']] ?? null;
            if ($terminal === null) {
                throw new InvalidArgumentException('incomplete_terminal_evidence');
            }
            $total = $file['expected_total'];
            if ($terminal->part !== $total || $terminal->total !== $total || $terminal->fileSize !== $protected->size
                || $terminal->begin !== ($total - 1) * 716800 + 1 || $terminal->end !== $protected->size) {
                throw new InvalidArgumentException('rar_terminal_declaration_conflict');
            }
            $file['anchor'] = $anchor;
            $file['archive_header'] = $archive;
        }
        unset($file);

        return $files;
    }
}
