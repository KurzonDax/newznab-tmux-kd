<?php

declare(strict_types=1);

namespace App\Services\ObfuscationRecovery;

use RuntimeException;
use XMLReader;

final class RecoveryNzbVerifier
{
    public function __construct(private readonly RecoveryArtifacts $artifacts) {}

    public function verify(string $path, RecoveryPlan $plan, bool $allowAdditionalFiles = false): string
    {
        $expected = [];
        foreach ($plan->files as $file) {
            $expected[$file->identity] = ['file' => $file, 'hash' => hash_init('sha256'), 'parts' => 0];
        }
        foreach ((new RecoveryManifest($this->artifacts))->read(new RecoveryArtifact($plan->manifestDigest, $plan->manifestBytes)) as $record) {
            if (! isset($expected[$record['file']]) || $record['ordinal'] !== ++$expected[$record['file']]['parts']
                || $record['group'] !== $plan->group || $record['source_epoch'] !== $plan->sourceEpoch) {
                throw new RuntimeException('recovery_manifest_mismatch');
            }
            hash_update($expected[$record['file']]['hash'], $this->segment($record['ordinal'], $record['message_id'], $record['advertised_bytes']));
        }
        $bySubject = [];
        foreach ($expected as $entry) {
            $file = $entry['file'];
            if ($entry['parts'] !== $file->totalParts) {
                throw new RuntimeException('recovery_manifest_mismatch');
            }
            $bySubject['"'.$file->displayName.'" yEnc (1/'.$file->totalParts.')'] = [hash_final($entry['hash']), $file->totalParts];
        }
        $digest = $this->boundedDigest($path, min(1073741824, 1048576 + $plan->plannedParts() * 2048));
        $reader = new XMLReader;
        $previous = libxml_use_internal_errors(true);
        try {
            if (! $reader->open('compress.zlib://'.$path, null, LIBXML_NONET)) {
                throw new RuntimeException('recovery_nzb_unreadable');
            }
            $reader->setParserProperty(XMLReader::LOADDTD, false);
            $reader->setParserProperty(XMLReader::SUBST_ENTITIES, false);
            $subject = null;
            $hash = null;
            $groups = [];
            $count = $files = 0;
            $seen = [];
            while ($reader->read()) {
                if ($reader->nodeType === XMLReader::ELEMENT && $reader->localName === 'file') {
                    if ($subject !== null || ++$files > ($allowAdditionalFiles ? 10000 : $plan->plannedFiles())) {
                        throw new RuntimeException('recovery_nzb_inventory_mismatch');
                    }
                    $subject = $reader->getAttribute('subject');
                    if ($subject === null || isset($seen[$subject]) || (! $allowAdditionalFiles && ! isset($bySubject[$subject]))) {
                        throw new RuntimeException('recovery_nzb_inventory_mismatch');
                    }
                    $seen[$subject] = true;
                    $hash = hash_init('sha256');
                    $groups = [];
                    $count = 0;
                } elseif ($reader->nodeType === XMLReader::ELEMENT && $reader->localName === 'group') {
                    if ($subject === null || count($groups) > 100) {
                        throw new RuntimeException('recovery_nzb_group_mismatch');
                    }
                    $groups[] = $reader->readString();
                } elseif ($reader->nodeType === XMLReader::ELEMENT && $reader->localName === 'segment') {
                    if ($subject === null || $hash === null || ++$count > 500000) {
                        throw new RuntimeException('recovery_nzb_segment_mismatch');
                    }
                    $number = $reader->getAttribute('number');
                    $bytes = $reader->getAttribute('bytes');
                    $id = $reader->readString();
                    if ($number !== (string) $count || $bytes === null || ! ctype_digit($bytes)
                        || (new RecoveryIdentity)->messageId($id) !== $id) {
                        throw new RuntimeException('recovery_nzb_segment_mismatch');
                    }
                    hash_update($hash, $this->segment($count, $id, (int) $bytes));
                } elseif ($reader->nodeType === XMLReader::END_ELEMENT && $reader->localName === 'file') {
                    $entry = $bySubject[$subject] ?? null;
                    if ($hash === null || ($entry !== null && ($entry[0] !== hash_final($hash) || $entry[1] !== $count
                        || ($allowAdditionalFiles ? ! in_array($plan->group, $groups, true) : $groups !== [$plan->group])))) {
                        throw new RuntimeException('recovery_nzb_membership_mismatch');
                    }
                    $subject = null;
                    $hash = null;
                }
            }
            if (libxml_get_errors() !== [] || $subject !== null || array_diff_key($bySubject, $seen) !== []) {
                throw new RuntimeException('recovery_nzb_incomplete');
            }
        } finally {
            $reader->close();
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        return $digest;
    }

    private function segment(int $ordinal, string $id, int $bytes): string
    {
        return pack('J3', $ordinal, $bytes, strlen($id)).$id;
    }

    private function boundedDigest(string $path, int $maximum): string
    {
        $input = gzopen($path, 'rb');
        if ($input === false) {
            throw new RuntimeException('recovery_nzb_unreadable');
        }
        $hash = hash_init('sha256');
        $bytes = 0;
        try {
            while (! gzeof($input)) {
                $chunk = gzread($input, 65536);
                if ($chunk === false || ($bytes += strlen($chunk)) > $maximum) {
                    throw new RuntimeException('recovery_nzb_size_limit');
                }
                hash_update($hash, $chunk);
            }

            return hash_final($hash);
        } finally {
            gzclose($input);
        }
    }
}
