<?php

declare(strict_types=1);

namespace Tests\Unit\Services\ReleaseRepair;

use App\Services\ReleaseRepair\MessageIdTemplate;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Deriving the message-IDs a header scan missed.
 *
 * This is the whole basis of repair: if the pattern is right, the missing articles are named
 * exactly, and if it is wrong the NZB gets filled with IDs that fail at download time. So the
 * detector's job is as much to say "no" for random-ID posters as to say "yes" for numbered ones.
 */
final class MessageIdTemplateTest extends TestCase
{
    #[Test]
    public function it_derives_a_powerpost_style_pattern_from_two_segments(): void
    {
        // The empirical case: a PowerPost release where only 2 of 211 segments were seen.
        $template = MessageIdTemplate::detect([
            5 => 'part5of211.AbCdEf@powerpost2000AA.local',
            17 => 'part17of211.AbCdEf@powerpost2000AA.local',
        ]);

        $this->assertNotNull($template);
        $this->assertSame('part200of211.AbCdEf@powerpost2000AA.local', $template->render(200));
        $this->assertSame('part1of211.AbCdEf@powerpost2000AA.local', $template->render(1));
    }

    #[Test]
    public function it_keeps_zero_padding_the_prefix_would_otherwise_swallow(): void
    {
        // `part005...` and `part017...` share the prefix `part0`, which would make the varying
        // field `05` rather than `005` and render segment 200 as `part0200of211`.
        $template = MessageIdTemplate::detect([
            5 => 'part005of211.Tok@host.local',
            17 => 'part017of211.Tok@host.local',
        ]);

        $this->assertNotNull($template);
        $this->assertSame('part005of211.Tok@host.local', $template->render(5));
        $this->assertSame('part200of211.Tok@host.local', $template->render(200));
    }

    #[Test]
    public function it_handles_a_number_that_is_a_prefix_of_another(): void
    {
        $template = MessageIdTemplate::detect([
            1 => 'p1s@h',
            11 => 'p11s@h',
        ]);

        $this->assertNotNull($template);
        $this->assertSame('p3s@h', $template->render(3));
    }

    #[Test]
    public function it_refuses_random_message_ids(): void
    {
        // Nyuu / ngPost mint a fresh random ID per article: there is no pattern to find, and
        // guessing one would write unverifiable IDs into the NZB.
        $this->assertNull(MessageIdTemplate::detect([
            1 => 'a4f1c9e2b7d3@ngPost',
            2 => '9e17b0d4a2c8@ngPost',
            3 => 'ff02ba7719cd@ngPost',
        ]));
    }

    #[Test]
    public function it_refuses_a_single_sample(): void
    {
        // One ID cannot say which of its digit runs is the segment number.
        $this->assertNull(MessageIdTemplate::detect([
            5 => 'part5of211.AbCdEf@powerpost2000AA.local',
        ]));
    }

    #[Test]
    public function it_refuses_when_the_varying_field_is_not_the_segment_number(): void
    {
        // The numbers vary but do not match the segment numbers, so whatever is changing here
        // is not the part counter.
        $this->assertNull(MessageIdTemplate::detect([
            5 => 'chunk900of211.Tok@host',
            17 => 'chunk901of211.Tok@host',
        ]));
    }

    #[Test]
    public function it_reproduces_every_sample_it_was_given(): void
    {
        $segments = [
            2 => 'x-2-of-99@poster.example',
            50 => 'x-50-of-99@poster.example',
            99 => 'x-99-of-99@poster.example',
        ];

        $template = MessageIdTemplate::detect($segments);

        $this->assertNotNull($template);

        foreach ($segments as $number => $id) {
            $this->assertSame($id, $template->render($number));
        }
    }

    #[Test]
    public function it_ignores_blank_segments_when_counting_samples(): void
    {
        $this->assertNull(MessageIdTemplate::detect([
            5 => 'part5of211.Tok@host',
            6 => '   ',
        ]));
    }
}
