<?php

declare(strict_types=1);

namespace App\Services\Nzb;

use RuntimeException;

/** Bounds unfinished lexical tokens before handing chunks to the SAX parser. */
final class NzbSummaryXmlGuard
{
    private const int LIMIT = 65536;

    private string $token = '';

    private string $quote = '';

    private int $textBytes = 0;

    public function accept(string $xml): void
    {
        if (str_contains($xml, "\0")) {
            throw new RuntimeException('Unsupported NZB encoding.');
        }
        $length = strlen($xml);
        for ($position = 0; $position < $length; $position++) {
            $character = $xml[$position];
            if ($this->token === '') {
                if ($character !== '<') {
                    $run = strcspn($xml, '<', $position);
                    $this->textBytes += $run;
                    $position += $run - 1;
                    if ($this->textBytes > self::LIMIT) {
                        throw new RuntimeException('NZB text exceeds 64 KiB.');
                    }

                    continue;
                }
                $this->textBytes = 0;
                $this->token = '<';

                continue;
            }
            $this->token .= $character;
            $comment = str_starts_with($this->token, '<!--');
            $cdata = str_starts_with($this->token, '<![CDATA[');
            $processingInstruction = str_starts_with($this->token, '<?');
            $overhead = $comment ? 7 : ($cdata ? 12 : 0);
            if (strlen($this->token) > self::LIMIT + $overhead) {
                throw new RuntimeException('NZB token exceeds 64 KiB.');
            }
            if ($comment || $cdata || $processingInstruction) {
                $end = $comment ? '-->' : ($cdata ? ']]>' : '?>');
                if (str_ends_with($this->token, $end)) {
                    $this->token = '';
                }

                continue;
            }
            if ($this->quote !== '') {
                if ($character === $this->quote) {
                    $this->quote = '';
                }

                continue;
            }
            if ($character === '"' || $character === "'") {
                $this->quote = $character;
            } elseif ($character === '[' && str_starts_with($this->token, '<!DOCTYPE')) {
                throw new RuntimeException('NZB internal subsets are not allowed.');
            } elseif ($character === '>') {
                $this->token = '';
            }
        }
    }
}
