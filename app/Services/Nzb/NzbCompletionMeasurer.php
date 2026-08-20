<?php

declare(strict_types=1);

namespace App\Services\Nzb;

/**
 * Measures a stored NZB the way {@see NzbService::createNzbForRelease()} measures a fresh one:
 * segments present over the part totals the binaries' subjects declare.
 *
 * At creation time the totals come from `binaries.totalparts`; a stored NZB only has the
 * subject line, so the total is read back out of it with the same extractor the rest of the
 * NZB code uses. The arithmetic afterwards is identical, which is what lets a backfilled
 * `completion` sit next to a creation-time one without meaning something different.
 */
final class NzbCompletionMeasurer
{
    public function __construct(
        private readonly NzbParserService $parser = new NzbParserService,
    ) {}

    public function measure(string $nzbXml): NzbCompletionMeasurement
    {
        $document = $this->loadDocument($nzbXml);

        if ($document === null) {
            return new NzbCompletionMeasurement(0, 0);
        }

        $actual = 0;
        $declared = 0;

        foreach ($document->file as $file) {
            $actual += count($file->segments->segment);
            $declared += max(0, $this->parser->extractPartsTotal((string) $file->attributes()->subject));
        }

        return new NzbCompletionMeasurement($actual, $declared);
    }

    private function loadDocument(string $nzbXml): ?\SimpleXMLElement
    {
        if (trim($nzbXml) === '') {
            return null;
        }

        $document = @simplexml_load_string(str_replace("\x0F", '', $nzbXml));

        if ($document === false || strtolower($document->getName()) !== 'nzb') {
            return null;
        }

        return $document;
    }
}
