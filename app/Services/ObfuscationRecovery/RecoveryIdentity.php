<?php

declare(strict_types=1);

namespace App\Services\ObfuscationRecovery;

use InvalidArgumentException;

final class RecoveryIdentity
{
    public function messageId(string $source): string
    {
        $id = trim($source, " \t");
        if (str_starts_with($id, '<') && str_ends_with($id, '>')) {
            $id = substr($id, 1, -1);
        }
        if ($id === '' || strlen($id) > 255 || preg_match('/^[\x21-\x7e]+$/D', $id) !== 1
            || str_contains($id, '<') || str_contains($id, '>')) {
            throw new InvalidArgumentException('invalid_message_id');
        }

        return $id;
    }

    /** @param list<string> $fileIds Raw sixteen-byte PAR2 FileIDs. */
    public function publication(string $family, string $indexMessageId, string $setId, array $fileIds): string
    {
        if (! in_array($family, ['media', 'rar'], true) || strlen($setId) !== 16
            || count($fileIds) < 1 || count($fileIds) > 32
            || count(array_unique($fileIds, SORT_STRING)) !== count($fileIds)) {
            throw new InvalidArgumentException('invalid_inventory_identity');
        }
        foreach ($fileIds as $fileId) {
            if (strlen($fileId) !== 16) {
                throw new InvalidArgumentException('invalid_file_id');
            }
        }
        usort($fileIds, strcmp(...));

        return $this->digest(['nntmux:recovery:publication', $family, $this->messageId($indexMessageId), $setId, (string) count($fileIds), ...$fileIds]);
    }

    /** @param iterable<string> $fields */
    public function digest(iterable $fields): string
    {
        $context = hash_init('sha256');
        foreach ($fields as $field) {
            hash_update($context, pack('J', strlen($field)));
            hash_update($context, $field);
        }

        return hash_final($context);
    }

    public function collectionProjection(string $publication): string
    {
        return substr(hex2bin($this->digest(['nntmux:recovery:collection', $publication])), 0, 20);
    }

    public function binaryIdentity(string $publication, string $role, string $fileIdentity): string
    {
        return $this->digest(['nntmux:recovery:binary', $publication, $role, $fileIdentity]);
    }

    public function binaryProjection(string $publication, string $role, string $fileIdentity): string
    {
        return substr(hex2bin($this->binaryIdentity($publication, $role, $fileIdentity)), 0, 16);
    }
}
