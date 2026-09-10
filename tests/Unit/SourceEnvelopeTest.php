<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\CollectionReconciliation\PostingFile;
use App\Services\CollectionReconciliation\PostingNzb;
use App\Services\CollectionReconciliation\SourceEnvelope;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

class SourceEnvelopeTest extends TestCase
{
    public function test_folded_mime_evidence_leaves_canonical_nzb_serialization_unchanged(): void
    {
        $subject = '[01/05] - "Example.par2" yEnc (1/1)';
        $file = new PostingFile('source', 'file', $subject, 'example.group', 'Synthetic Poster', 1767268800, 1,
            [['number' => 1, 'messageid' => 'part@example.invalid', 'bytes' => 100]]);
        $headers = 'From: =?UTF-8?B?'.base64_encode('Synthetic Poster')."?=\r\nSubject:\r\n "
            .'=?UTF-8?B?'.base64_encode($subject.' 76296')."?=\r\n"
            ."Date: Thu, 01 Jan 2026 12:00:00 +0000\r\nMessage-ID: <part@example.invalid>\r\n";
        $this->assertSame('Synthetic Poster', SourceEnvelope::validate($headers, $file)->poster);
        $nzb = new PostingNzb;
        $xml = $nzb->render([$file]);
        $roundTrip = $nzb->parse($xml, 'round-trip');
        $this->assertSame($subject, $roundTrip[0]->subject);
        $this->assertSame($xml, $nzb->render($roundTrip));
        $this->expectException(UnexpectedValueException::class);
        PostingFile::subject($subject.' 76296');
    }

    public function test_later_article_requires_its_own_message_id_and_counter(): void
    {
        $file = new PostingFile('source', 'file', '[01/05] - "Example.par2" yEnc (1/2)',
            'example.group', 'Synthetic Poster', 1767268800, 2,
            [['number' => 1, 'messageid' => 'first@example.invalid', 'bytes' => 100],
                ['number' => 2, 'messageid' => 'second@example.invalid', 'bytes' => 100]]);
        $headers = "From: Synthetic Poster\r\nSubject: [01/05] - \"Example.par2\" yEnc (2/2) 100\r\n"
            ."Date: Thu, 01 Jan 2026 12:00:00 +0000\r\nMessage-ID: <second@example.invalid>\r\n";
        $this->assertSame('<second@example.invalid>', SourceEnvelope::validate($headers, $file, 2)->messageId);
        $this->expectException(UnexpectedValueException::class);
        SourceEnvelope::validate(str_replace('(2/2)', '(1/2)', $headers), $file, 2);
    }

    #[DataProvider('contradictions')]
    public function test_contradictory_evidence_is_rejected(string $subject, string $extra): void
    {
        $file = new PostingFile('source', 'file', '[01/05] - "Example.par2" yEnc (1/1)',
            'example.group', 'Synthetic Poster', 1767268800, 1,
            [['number' => 1, 'messageid' => 'part@example.invalid', 'bytes' => 100]]);
        $headers = "From: Synthetic Poster\r\nSubject: {$subject}\r\n"
            ."Date: Thu, 01 Jan 2026 12:00:00 +0000\r\nMessage-ID: <part@example.invalid>\r\n".$extra;
        $this->expectException(UnexpectedValueException::class);
        SourceEnvelope::validate($headers, $file);
    }

    /** @return iterable<string, array{string, string}> */
    public static function contradictions(): iterable
    {
        foreach (['(2/999)', '(2/1)', '(1/999)', '(1/1) bytes', '(1/1) -10'] as $counter) {
            yield $counter => ['[01/05] - "Example.par2" yEnc '.$counter, ''];
        }
        foreach (['From: Other', 'Subject: unrelated', 'Date: Fri, 02 Jan 2026 12:00:00 +0000',
            'Message-ID: <other@example.invalid>', "X-Other: first\r\nX-Other: second", 'Malformed'] as $header) {
            yield $header => ['[01/05] - "Example.par2" yEnc (1/1)', $header."\r\n"];
        }
    }

    public function test_byte_suffix_and_differing_trace_headers_preserve_identity(): void
    {
        $file = new PostingFile('source', 'file', '[01/05] - "Example.par2" yEnc (1/1)',
            'example.group', 'Synthetic Poster', 1767268800, 1,
            [['number' => 1, 'messageid' => 'part@example.invalid', 'bytes' => 100]]);
        $headers = "From: Synthetic Poster\r\nSubject: [01/05] - \"Example.par2\" yEnc (1/1) 76296\r\n"
            ."Date: Thu, 01 Jan 2026 12:00:00 +0000\r\nMessage-ID: <part@example.invalid>\r\n"
            ."X-Trace: first\r\nX-Trace: second\r\n";

        $envelope = SourceEnvelope::validate($headers, $file);

        $this->assertSame('Synthetic Poster', $envelope->poster);
        $this->assertSame('<part@example.invalid>', $envelope->messageId);
        $this->assertSame(1767268800, $envelope->date);
    }
}
