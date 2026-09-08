<?php

declare(strict_types=1);

namespace App\Services\CollectionReconciliation;

use UnexpectedValueException;

final class PostingNzb
{
    /** @param list<PostingFile> $files */
    public function render(array $files): string
    {
        $writer = new \XMLWriter;
        $writer->openMemory();
        $writer->startDocument('1.0', 'UTF-8');
        $writer->startElementNS(null, 'nzb', 'http://www.newzbin.com/DTD/2003/nzb');
        foreach ($files as $file) {
            $writer->startElement('file');
            $writer->writeAttribute('poster', $file->poster);
            $writer->writeAttribute('date', (string) $file->date);
            $writer->writeAttribute('subject', preg_match('/\(\d+\/\d+\)$/D', $file->subject) === 1
                ? $file->subject : $file->subject.' (1/'.$file->declaredParts.')');
            $writer->startElement('groups');
            foreach ($file->groups as $group) {
                $writer->writeElement('group', $group);
            }
            $writer->endElement();
            $writer->startElement('segments');
            foreach ($file->segments as $segment) {
                $writer->startElement('segment');
                $writer->writeAttribute('bytes', (string) $segment['bytes']);
                $writer->writeAttribute('number', (string) $segment['number']);
                $writer->text(trim($segment['messageid'], '<>'));
                $writer->endElement();
            }
            $writer->endElement();
            $writer->endElement();
        }
        $writer->endElement();
        $writer->endDocument();
        $xml = $writer->outputMemory();
        if (count($this->parse($xml, 'verify')) !== count($files)) {
            throw new UnexpectedValueException('nzb_validation');
        }

        return $xml;
    }

    /** @return list<PostingFile> */
    public function parse(string $xml, string $sourceId, ?string $primaryGroup = null): array
    {
        if (strlen($xml) > 32 * 1024 * 1024 || stripos($xml, '<!ENTITY') !== false) {
            throw new UnexpectedValueException('nzb_limit_or_entity');
        }
        $previous = libxml_use_internal_errors(true);
        try {
            $document = simplexml_load_string($xml, \SimpleXMLElement::class, LIBXML_NONET);
            if ($document === false || $document->getName() !== 'nzb') {
                throw new UnexpectedValueException('invalid_nzb');
            }
            $files = [];
            foreach ($document->file as $i => $entry) {
                if (count($files) >= 1024 || count($entry->groups->group) < 1 || count($entry->groups->group) > 64) {
                    throw new UnexpectedValueException('unsupported_nzb_inventory');
                }
                $groups = array_map(static fn ($group): string => (string) $group, iterator_to_array($entry->groups->group, false));
                if ($primaryGroup !== null && ! in_array($primaryGroup, $groups, true)) {
                    throw new UnexpectedValueException('nzb_primary_group_mismatch');
                }
                $segments = [];
                foreach ($entry->segments->segment as $segment) {
                    $segments[] = ['number' => (int) $segment['number'], 'messageid' => (string) $segment, 'bytes' => (int) $segment['bytes']];
                }
                $subject = (string) $entry['subject'];
                if (! preg_match('/\((\d+)\/(\d+)\)$/D', $subject, $parts)) {
                    throw new UnexpectedValueException('missing_segment_total');
                }
                $files[] = new PostingFile($sourceId, $sourceId.':'.count($files), $subject,
                    $primaryGroup ?? (string) $entry->groups->group, (string) $entry['poster'], (int) $entry['date'], (int) $parts[2], $segments, $groups);
            }

            return $files;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }
}
