<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * Size a stored NZB the way NZB import does (issue #982): the sum of every segment's `bytes`,
 * read with SimpleXML so the expectation does not come from the repair document under test.
 */
trait SumsNzbSegmentBytes
{
    protected function nzbSegmentBytes(string $nzbXml): int
    {
        $xml = simplexml_load_string($nzbXml);
        $this->assertNotFalse($xml);
        $bytes = 0;

        foreach ($xml->file as $file) {
            foreach ($file->segments->segment as $segment) {
                $bytes += (int) $segment['bytes'];
            }
        }

        return $bytes;
    }
}
