<?php

declare(strict_types=1);

namespace App\Services\CollectionReconciliation;

use Carbon\CarbonImmutable;
use DOMDocument;
use DOMElement;
use UnexpectedValueException;

/** General NZB identity; proof continues to use the narrower PostingFile grammar. */
final class ArtifactInventory
{
    /** @param array<string, list<array<int, array{number: int, messageid: string, bytes: int}>>> $files */
    private function __construct(public readonly string $xml, private readonly array $files, private readonly bool $ambiguous) {}

    public static function load(string $xml): self
    {
        $document = new DOMDocument;
        if (! @$document->loadXML($xml, LIBXML_NONET) || $document->documentElement?->localName !== 'nzb') {
            throw new UnexpectedValueException('invalid_artifact_xml');
        }
        $files = [];
        $ambiguous = false;
        foreach ($document->documentElement->childNodes as $file) {
            if (! $file instanceof DOMElement || $file->localName !== 'file') {
                continue;
            }
            $groups = [];
            foreach ($file->getElementsByTagNameNS('*', 'group') as $group) {
                $groups[] = $group->textContent;
            }
            $groups = array_values(array_unique($groups));
            sort($groups, SORT_STRING);
            $date = filter_var($file->getAttribute('date'), FILTER_VALIDATE_INT);
            $subject = $file->getAttribute('subject');
            $total = preg_match('/\([0-9]+\/([1-9][0-9]*)\)\s*$/D', $subject, $counter) === 1
                ? filter_var($counter[1], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : null;
            $ambiguous = $ambiguous || $date === false || $total === false;
            $key = hash('sha256', json_encode([$subject, $file->getAttribute('poster'), $date, $groups, $total], JSON_THROW_ON_ERROR));
            $segments = [];
            foreach ($file->getElementsByTagNameNS('*', 'segment') as $segment) {
                $number = filter_var($segment->getAttribute('number'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                $bytes = filter_var($segment->getAttribute('bytes'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
                $id = trim($segment->textContent);
                if (str_starts_with($id, '<') && str_ends_with($id, '>')) {
                    $id = substr($id, 1, -1);
                }
                if ($number === false || $bytes === false || $id === '') {
                    $ambiguous = true;
                }
                $segments[] = ['number' => $number === false ? 0 : $number, 'messageid' => $id, 'bytes' => $bytes === false ? -1 : $bytes];
            }
            usort($segments, static fn (array $a, array $b): int => [$a['number'], $a['messageid'], $a['bytes']] <=> [$b['number'], $b['messageid'], $b['bytes']]);
            $numbers = array_column($segments, 'number');
            $ambiguous = $ambiguous || count(array_unique($numbers)) !== count($numbers);
            $files[$key][] = $segments;
        }
        ksort($files);
        foreach ($files as &$copies) {
            sort($copies);
        }

        return new self($xml, $files, $ambiguous);
    }

    /** @return array<string, list<array<int, array{number: int, messageid: string, bytes: int}>>> */
    public function files(): array
    {
        return $this->files;
    }

    /** @return array{added: list<string>, changed: list<string>, removed: list<string>} */
    public function deltaAgainst(self $before): array
    {
        $changed = [];
        foreach ($this->files as $key => $files) {
            if (isset($before->files[$key]) && $before->files[$key] !== $files) {
                $changed[] = $key;
            }
        }

        return ['added' => array_values(array_diff(array_keys($this->files), array_keys($before->files))),
            'changed' => $changed, 'removed' => array_values(array_diff(array_keys($before->files), array_keys($this->files)))];
    }

    /** @return array<string, int|string|null> */
    public function discovery(?int $groupId): array
    {
        $empty = ['discovery_group_id' => null, 'discovery_count' => null, 'discovery_postdate' => null,
            'discovery_poster' => null, 'discovery_base' => null];
        try {
            $files = $this->proofCandidates(['files' => [], 'components' => []]);
            $bases = array_values(array_filter($files, static fn (PostingFile $file): bool => $file->isBasePar2()));
            if (count($bases) !== 1 || $groupId === null) {
                return $empty;
            }
            $base = $bases[0];

            return ['discovery_group_id' => $groupId, 'discovery_count' => $base->total,
                'discovery_postdate' => CarbonImmutable::createFromTimestamp($base->date, config('app.timezone'))->toDateTimeString(), 'discovery_poster' => $base->poster,
                'discovery_base' => $base->firstArticle()];
        } catch (UnexpectedValueException) {
            return $empty;
        }
    }

    public function bytes(): int
    {
        $bytes = 0;
        foreach ($this->files as $copies) {
            foreach ($copies as $segments) {
                foreach ($segments as $segment) {
                    if ($segment['bytes'] < 0 || $segment['bytes'] > PHP_INT_MAX - $bytes) {
                        throw new UnexpectedValueException('artifact_size_overflow');
                    }
                    $bytes += $segment['bytes'];
                }
            }
        }

        return $bytes;
    }

    /**
     * @param  array{files: array<string, list<string>>, components: list<list<string>>}  $provenance
     * @return list<PostingFile>
     */
    public function proofCandidates(array $provenance): array
    {
        if (count($this->files) > 1024 || strlen($this->xml) > 32 * 1024 * 1024) {
            return [];
        }
        $document = new DOMDocument;
        $document->loadXML($this->xml, LIBXML_NONET);
        $candidates = [];
        $unsupported = [];
        foreach ($document->documentElement->childNodes as $node) {
            if (! $node instanceof DOMElement || $node->localName !== 'file') {
                continue;
            }
            $fragment = new DOMDocument;
            $root = $fragment->createElementNS('http://www.newzbin.com/DTD/2003/nzb', 'nzb');
            $fragment->appendChild($root);
            $root->appendChild($fragment->importNode($node, true));
            $xml = $fragment->saveXML();
            $key = array_key_first(self::load($xml)->files());
            $groups = $provenance['files'][$key] ?? ['opaque:'.$key];
            do {
                $previous = $groups;
                foreach ($provenance['components'] as $component) {
                    if (array_intersect($groups, $component) !== []) {
                        $groups = array_values(array_unique(array_merge($groups, $component)));
                    }
                }
            } while ($groups !== $previous);
            sort($groups);
            $source = 'component:'.hash('sha256', json_encode($groups, JSON_THROW_ON_ERROR));
            try {
                $file = (new PostingNzb)->parse($xml, $source)[0];
                if (! $file->hasCompleteSegments() || count($this->files[$key]) !== 1) {
                    throw new UnexpectedValueException('incomplete_component');
                }
                $candidates[] = new PostingFile($source, 'current:'.$key, $file->subject, $file->group,
                    $file->poster, $file->date, $file->declaredParts, $file->segments, $file->groups);
            } catch (UnexpectedValueException) {
                $unsupported[$source] = true;
            }
        }

        return array_values(array_filter($candidates, static fn (PostingFile $file): bool => ! isset($unsupported[$file->sourceId])));
    }

    /** @param list<PostingFile> $additions */
    public function append(array $additions): string
    {
        $document = new DOMDocument;
        $document->loadXML($this->xml, LIBXML_NONET);
        foreach ($additions as $file) {
            $additionXml = (new PostingNzb)->render([$file]);
            $incoming = self::load($additionXml);
            $key = array_key_first($incoming->files());
            if (isset($this->files[$key])) {
                if (count($this->files[$key]) !== 1 || $this->files[$key] !== $incoming->files()[$key]) {
                    throw new UnexpectedValueException('current_file_collision');
                }

                continue;
            }
            foreach ($document->documentElement->childNodes as $node) {
                if (! $node instanceof DOMElement || $node->localName !== 'file') {
                    continue;
                }
                $subject = $node->getAttribute('subject');
                if (preg_match('/"([^"\r\n]+)"/', $subject, $name) !== 1 || $name[1] === $file->filename
                    || (preg_match('/\[([0-9]+)\/[0-9]+\]/', $subject, $ordinal) === 1 && (int) $ordinal[1] === $file->ordinal)) {
                    throw new UnexpectedValueException('opaque_or_conflicting_file');
                }
                foreach ($node->getElementsByTagNameNS('*', 'segment') as $segment) {
                    if (in_array(trim($segment->textContent, "<> \t\r\n"), array_map(static fn (array $s): string => trim($s['messageid'], "<> \t\r\n"), $file->segments), true)) {
                        throw new UnexpectedValueException('current_article_collision');
                    }
                }
            }
            $addition = new DOMDocument;
            $addition->loadXML($additionXml, LIBXML_NONET);
            foreach ($addition->documentElement->childNodes as $node) {
                if ($node instanceof DOMElement && $node->localName === 'file') {
                    $document->documentElement->appendChild($document->importNode($node, true));
                }
            }
        }

        return $document->saveXML();
    }

    public function classifyAgainst(self $before): string
    {
        if ($this->files === $before->files && ! $this->ambiguous && ! $before->ambiguous) {
            return 'serialization';
        }
        if ($this->ambiguous || $before->ambiguous
            || array_any($this->files, static fn (array $copies): bool => count($copies) !== 1)
            || array_any($before->files, static fn (array $copies): bool => count($copies) !== 1)) {
            return 'replacement';
        }
        foreach ($before->files as $key => $copies) {
            if (! isset($this->files[$key])) {
                return 'replacement';
            }
            foreach ($copies[0] as $segment) {
                if (! in_array($segment, $this->files[$key][0], true)) {
                    return 'replacement';
                }
            }
        }

        return 'additive';
    }
}
