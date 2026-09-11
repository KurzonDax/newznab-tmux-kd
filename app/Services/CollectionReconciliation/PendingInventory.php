<?php

declare(strict_types=1);

namespace App\Services\CollectionReconciliation;

use App\Support\SchemaCapabilities;
use Illuminate\Support\Facades\DB;
use UnexpectedValueException;

final class PendingInventory
{
    /**
     * @param  list<int>  $ids
     * @return list<PostingFile>
     */
    public function load(array $ids): array
    {
        if (count($ids) > 256) {
            throw new UnexpectedValueException('source_limit');
        }
        $rows = DB::table('binaries as b')->join('collections as c', 'c.id', '=', 'b.collections_id')
            ->join('usenet_groups as g', 'g.id', '=', 'c.groups_id')->whereIn('c.id', $ids)
            ->orderBy('b.id')->limit(1025)->get(['b.*', 'c.fromname', 'c.date', 'g.name as group_name']);
        if ($rows->count() > 1024) {
            throw new UnexpectedValueException('file_limit');
        }
        $files = [];
        foreach ($rows as $row) {
            $parts = DB::table('parts')->where('binaries_id', $row->id)->orderBy('partnumber')->get(['partnumber', 'messageid', 'size']);
            $segments = $parts->map(static fn ($part): array => ['number' => (int) $part->partnumber,
                'messageid' => (string) $part->messageid, 'bytes' => (int) $part->size])->all();
            $files[] = new PostingFile((string) $row->collections_id, (string) $row->id, $row->name, $row->group_name,
                $row->fromname, (int) strtotime($row->date), (int) $row->totalparts, $segments,
                SchemaCapabilities::hasTable('collection_groups')
                    ? DB::table('collection_groups')->where('collections_id', $row->collections_id)->orderBy('group_name')->pluck('group_name')->all() : []);
        }

        return $files;
    }

    /** @param list<PostingFile> $files */
    public static function digest(array $files): string
    {
        return hash('sha256', self::encode($files));
    }

    /** @param list<PostingFile> $files */
    public static function encode(array $files): string
    {
        return json_encode($files, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    /** @return list<PostingFile> */
    public static function decode(string $json): array
    {
        $rows = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        $files = [];
        foreach ($rows as $row) {
            $files[] = new PostingFile($row['sourceId'], $row['fileId'], $row['subject'], $row['group'], $row['poster'],
                $row['date'], $row['declaredParts'], $row['segments'], $row['groups'] ?? []);
        }

        return $files;
    }
}
