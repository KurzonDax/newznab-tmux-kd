<?php

declare(strict_types=1);

namespace App\Services\Par2Sidecar;

use DOMDocument;
use DOMElement;
use RuntimeException;

final class SidecarNzb
{
    public function complete(string $xml): bool
    {
        $document = $this->document($xml);
        $files = $document->getElementsByTagName('file');
        if ($files->length === 0) {
            return false;
        }
        $messageIds = [];
        foreach ($files as $file) {
            if (preg_match('/\([0-9]+\/([1-9][0-9]*)\)$/D', $file->getAttribute('subject'), $match) !== 1) {
                return false;
            }
            $total = (int) $match[1];
            $segments = $file->getElementsByTagName('segment');
            if ($total !== $segments->length) {
                return false;
            }
            $numbers = [];
            foreach ($segments as $segment) {
                $raw = $segment->getAttribute('number');
                $number = (int) $raw;
                $id = trim($segment->textContent);
                if (! ctype_digit($raw) || $number < 1 || $number > $total || isset($numbers[$number]) || $id === '' || isset($messageIds[$id])) {
                    return false;
                }
                $numbers[$number] = true;
                $messageIds[$id] = true;
            }
        }

        return true;
    }

    public function append(string $targetXml, string $sourceXml): string
    {
        $target = $this->document($targetXml);
        $source = $this->document($sourceXml);
        $ids = [];
        foreach ($target->getElementsByTagName('segment') as $segment) {
            $ids[trim($segment->textContent)] = true;
        }
        foreach ($source->getElementsByTagName('segment') as $segment) {
            if (isset($ids[trim($segment->textContent)])) {
                throw new RuntimeException('overlapping_membership');
            }
        }
        foreach ($source->documentElement->childNodes as $file) {
            if ($file instanceof DOMElement && $file->localName === 'file') {
                $target->documentElement->appendChild($target->importNode($file, true));
            }
        }
        $xml = $target->saveXML();
        if ($xml === false) {
            throw new RuntimeException('invalid_nzb');
        }

        return $xml;
    }

    private function document(string $xml): DOMDocument
    {
        $document = new DOMDocument;
        if (! @$document->loadXML($xml, LIBXML_NONET) || $document->documentElement?->localName !== 'nzb'
            || ($document->doctype->internalSubset ?? '') !== '') {
            throw new RuntimeException('invalid_nzb');
        }

        return $document;
    }
}
