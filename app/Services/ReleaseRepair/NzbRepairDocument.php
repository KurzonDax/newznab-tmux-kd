<?php

declare(strict_types=1);

namespace App\Services\ReleaseRepair;

use App\Services\Nzb\CompletionSignals;
use App\Services\Nzb\CompletionTally;
use App\Services\Nzb\NzbParserService;
use DOMDocument;
use DOMElement;

/**
 * An NZB held open for repair: plan what is missing, then write the derived segments back in.
 *
 * The document is kept as a DOM rather than re-parsed between the two steps, so the segments
 * that verification accepted land in exactly the file they were derived from.
 */
final class NzbRepairDocument
{
    /**
     * @param  array<int, DOMElement>  $files  File index => `<file>` element, document order.
     */
    private function __construct(
        private readonly DOMDocument $document,
        private array $files,
        private readonly NzbParserService $parser,
    ) {}

    public static function load(string $nzbXml, NzbParserService $parser = new NzbParserService): ?self
    {
        if (trim($nzbXml) === '') {
            return null;
        }

        $document = new DOMDocument;
        // Normalising the whole document on the way out keeps a repaired NZB looking like a
        // freshly written one (NzbService writes with XMLWriter::setIndent), rather than a
        // tidy file with a block of unindented segments bolted on.
        $document->preserveWhiteSpace = false;
        $document->formatOutput = true;

        // LIBXML_NONET: the NZB DTD is declared with an external URL we must never fetch.
        $loaded = @$document->loadXML(str_replace("\x0F", '', $nzbXml), LIBXML_NONET);

        if ($loaded === false || $document->documentElement === null) {
            return null;
        }

        if (strtolower($document->documentElement->localName ?? '') !== 'nzb') {
            return null;
        }

        return new self($document, self::fileElementsOf($document), $parser);
    }

    /**
     * How many `<file>` elements the document holds.
     */
    public function fileCount(): int
    {
        return \count($this->files);
    }

    /**
     * File index => `subject` attribute, document order.
     *
     * @return array<int, string>
     */
    public function subjects(): array
    {
        return array_map(static fn (DOMElement $file): string => $file->getAttribute('subject'), $this->files);
    }

    /**
     * What a file added by the header re-scan has to look like to belong to this release.
     *
     * Poster, date and groups are collection-wide in a stored NZB -- the writer stamps every
     * `<file>` from the same collection row -- so the first file speaks for all of them.
     */
    public function envelope(): ?NzbFileEnvelope
    {
        $first = $this->files[array_key_first($this->files) ?? -1] ?? null;

        if ($first === null) {
            return null;
        }

        $groups = [];

        foreach ($first->getElementsByTagNameNS('*', 'group') as $group) {
            $name = trim($group->textContent);

            if ($name !== '') {
                $groups[] = $name;
            }
        }

        return new NzbFileEnvelope(
            poster: $first->getAttribute('poster'),
            date: $first->getAttribute('date'),
            groups: array_values(array_unique($groups)),
        );
    }

    /**
     * Work out, per file, which segments are missing and whether their message-IDs are derivable.
     */
    public function plan(): ReleaseRepairPlan
    {
        $plans = [];
        $withoutTemplate = 0;
        $withNoSegments = 0;

        foreach ($this->files as $index => $file) {
            $subject = $file->getAttribute('subject');
            $declaredTotal = max(0, $this->parser->extractPartsTotal($subject));
            $segments = $this->segmentsOf($file);

            if ($segments === []) {
                // Nothing to derive a template from: this file's token is unknowable from the
                // NZB alone. Recovering it needs a header re-scan, not synthesis.
                $withNoSegments++;

                continue;
            }

            $missing = array_values(array_diff(range(1, max($declaredTotal, 1)), array_keys($segments)));

            if ($declaredTotal <= 0 || $missing === []) {
                continue;
            }

            $template = MessageIdTemplate::detect($segments);

            if ($template === null) {
                $withoutTemplate++;

                continue;
            }

            $synthesized = [];

            foreach ($missing as $number) {
                $synthesized[$number] = $template->render($number);
            }

            $plans[] = new FileRepairPlan(
                fileIndex: $index,
                subject: $subject,
                declaredTotal: $declaredTotal,
                presentCount: count($segments),
                synthesized: $synthesized,
            );
        }

        return new ReleaseRepairPlan(
            files: $plans,
            filesWithoutTemplate: $withoutTemplate,
            filesWithNoSegments: $withNoSegments,
        );
    }

    /**
     * Write verified segments into their files.
     *
     * @param  array<int, array<int, string>>  $accepted  File index => (segment number => message-ID).
     * @return int Segments actually added.
     */
    public function addSegments(array $accepted): int
    {
        $added = 0;

        foreach ($accepted as $fileIndex => $segments) {
            $file = $this->files[$fileIndex] ?? null;

            if ($file === null || $segments === []) {
                continue;
            }

            $container = $this->segmentsContainer($file);

            if ($container === null) {
                continue;
            }

            $bytes = $this->averageSegmentBytes($file);

            foreach ($segments as $number => $messageId) {
                $segment = $this->document->createElementNS($container->namespaceURI, 'segment');
                // createElement()'s value argument does not escape; a text node does.
                $segment->appendChild($this->document->createTextNode($messageId));
                $segment->setAttribute('bytes', (string) $bytes);
                $segment->setAttribute('number', (string) $number);
                $container->appendChild($segment);
                $added++;
            }

            $this->sortSegments($container);
        }

        return $added;
    }

    /**
     * @param  int|null  $declaredFiles  The resolved declared file count, where the caller has one.
     *                                   Without it the count is read from the subjects themselves,
     *                                   which only sees the files the NZB still holds.
     */
    public function measure(?int $declaredFiles = null): CompletionSignals
    {
        $tally = new CompletionTally;

        foreach ($this->files as $file) {
            $subject = $file->getAttribute('subject');

            $tally->addFile(
                count($this->segmentsOf($file)),
                $this->parser->extractPartsTotal($subject),
                $declaredFiles ?? $this->parser->extractFilesTotal($subject),
            );
        }

        return $tally->signals();
    }

    /**
     * Append whole files the header re-scan found, in file-index order.
     *
     * These are files with no seen segment at all, so there was no `<file>` element to repair --
     * they are built from the overview lines themselves and from the collection-wide envelope the
     * NZB already carries.
     *
     * @param  array<int, RecoveredFile>  $files  Keyed by file index; ignored where the index is
     *                                            already present.
     * @return int Segments actually added.
     */
    public function addFiles(array $files, NzbFileEnvelope $envelope): int
    {
        $root = $this->document->documentElement;

        if ($root === null || $files === []) {
            return 0;
        }

        ksort($files);
        $added = 0;

        foreach ($files as $recovered) {
            if ($recovered->segments === []) {
                continue;
            }

            $file = $this->document->createElementNS($root->namespaceURI, 'file');
            $file->setAttribute('poster', $envelope->poster);
            $file->setAttribute('date', $envelope->date);
            $file->setAttribute('subject', $recovered->subject);

            $groups = $this->document->createElementNS($root->namespaceURI, 'groups');

            foreach ($envelope->groups as $groupName) {
                $group = $this->document->createElementNS($root->namespaceURI, 'group');
                $group->appendChild($this->document->createTextNode($groupName));
                $groups->appendChild($group);
            }

            $file->appendChild($groups);

            $segments = $this->document->createElementNS($root->namespaceURI, 'segments');
            $numbers = $recovered->segments;
            ksort($numbers);

            foreach ($numbers as $number => $segment) {
                $element = $this->document->createElementNS($root->namespaceURI, 'segment');
                // createElement()'s value argument does not escape; a text node does.
                $element->appendChild($this->document->createTextNode($segment->messageId));
                $element->setAttribute('bytes', (string) $segment->bytes);
                $element->setAttribute('number', (string) $number);
                $segments->appendChild($element);
                $added++;
            }

            $file->appendChild($segments);
            $root->appendChild($file);
        }

        // Re-read the document: plan() and measure() must see what was just appended.
        $this->files = self::fileElementsOf($this->document);

        return $added;
    }

    /**
     * @return array<int, DOMElement>
     */
    private static function fileElementsOf(DOMDocument $document): array
    {
        $files = [];

        foreach ($document->getElementsByTagNameNS('*', 'file') as $index => $file) {
            if ($file instanceof DOMElement) {
                $files[$index] = $file;
            }
        }

        return $files;
    }

    public function toXml(): string
    {
        return (string) $this->document->saveXML();
    }

    /**
     * Segment number => message-ID for one file, ignoring unusable entries.
     *
     * @return array<int, string>
     */
    private function segmentsOf(DOMElement $file): array
    {
        $segments = [];

        foreach ($file->getElementsByTagNameNS('*', 'segment') as $segment) {
            if (! $segment instanceof DOMElement) {
                continue;
            }

            $number = (int) $segment->getAttribute('number');
            $messageId = trim($segment->textContent);

            if ($number > 0 && $messageId !== '') {
                $segments[$number] = $messageId;
            }
        }

        return $segments;
    }

    private function segmentsContainer(DOMElement $file): ?DOMElement
    {
        $container = $file->getElementsByTagNameNS('*', 'segments')->item(0);

        return $container instanceof DOMElement ? $container : null;
    }

    /**
     * Advisory `bytes` for a synthesized segment: what its siblings average.
     *
     * NZB readers use `bytes` for progress display and size estimates, not for correctness,
     * and the true size is unknowable without fetching the article.
     */
    private function averageSegmentBytes(DOMElement $file): int
    {
        $sizes = [];

        foreach ($file->getElementsByTagNameNS('*', 'segment') as $segment) {
            if ($segment instanceof DOMElement) {
                $bytes = (int) $segment->getAttribute('bytes');

                if ($bytes > 0) {
                    $sizes[] = $bytes;
                }
            }
        }

        return $sizes === [] ? 0 : (int) round(array_sum($sizes) / count($sizes));
    }

    /**
     * Put the segments back in numeric order after appending.
     *
     * Readers are supposed to honour the `number` attribute, but some concatenate in document
     * order, and a release that downloads as scrambled bytes is not a repaired release.
     */
    private function sortSegments(DOMElement $container): void
    {
        $segments = [];

        foreach ($container->getElementsByTagNameNS('*', 'segment') as $segment) {
            if ($segment instanceof DOMElement) {
                $segments[] = $segment;
            }
        }

        usort(
            $segments,
            static fn (DOMElement $a, DOMElement $b): int => (int) $a->getAttribute('number') <=> (int) $b->getAttribute('number'),
        );

        foreach ($segments as $segment) {
            $container->appendChild($segment);
        }
    }
}
