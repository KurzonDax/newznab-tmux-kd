<?php

declare(strict_types=1);

namespace App\Services\Nzb;

use App\Models\Release;
use App\Support\FilenameSanitizer;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use ZipStream\CompressionMethod;
use ZipStream\ZipStream;

final class NzbArchiveStream
{
    public function __construct(private readonly NzbService $nzbs) {}

    /**
     * Resolve the archive once, without opening or retaining decompressed NZB bodies.
     *
     * @param  list<string>  $guids
     * @return array{response: StreamedResponse, releaseIds: list<int>}
     */
    public function prepare(array $guids): array
    {
        $entries = [];
        $releaseIds = [];
        foreach (array_chunk(array_unique($guids), 500) as $batch) {
            $releases = Release::query()->whereIn('guid', $batch)->get(['id', 'guid', 'searchname']);
            foreach ($releases as $release) {
                $id = (int) $release->id;
                if (isset($entries[$id])) {
                    continue;
                }
                $path = $this->nzbs->nzbPath($release->guid);
                if (! is_string($path) || ! is_readable($path)) {
                    continue;
                }
                $name = FilenameSanitizer::sanitize($release->searchname, 'release');
                $entries[$id] = ['path' => $path, 'name' => $name.'--'.$id.'.nzb'];
                $releaseIds[] = $id;
            }
        }

        $response = response()->streamDownload(static function () use ($entries): void {
            $zip = new ZipStream(
                sendHttpHeaders: false,
                defaultCompressionMethod: config('zipstream.compression_method') === 'deflate'
                    ? CompressionMethod::DEFLATE : CompressionMethod::STORE,
            );
            foreach ($entries as $entry) {
                $stream = @gzopen($entry['path'], 'rb');
                if ($stream === false) {
                    throw new RuntimeException('Unable to open NZB for archive: '.$entry['path']);
                }
                try {
                    $zip->addFileFromStream(fileName: $entry['name'], stream: $stream);
                } finally {
                    gzclose($stream);
                }
            }
            $zip->finish();
        }, now()->format('Ymdhis').'.zip', ['Content-Type' => 'application/zip', 'X-Accel-Buffering' => 'no']);

        return ['response' => $response, 'releaseIds' => $releaseIds];
    }
}
