<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ReleaseRepairOutcome;
use App\Support\ReleaseCompletion;
use Illuminate\Support\Facades\Blade;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The chips are the only place a permanently-incomplete release announces
 * itself in a listing, so their bands and the one repair label are pinned. The
 * repair chip follows {@see ReleaseCompletion::stillRepairing()}: it shows while
 * an engine can still take the release and never once nothing will.
 */
final class ReleaseCompletionChipsTest extends TestCase
{
    /**
     * @return array<string, array{0: float|int, 1: string}>
     */
    public static function bandProvider(): array
    {
        return [
            'complete is green' => [100, 'bg-green-100'],
            'yellow upper boundary' => [99, 'bg-yellow-100'],
            'yellow lower boundary' => [95, 'bg-yellow-100'],
            'just below yellow is red' => [94.99, 'bg-red-100'],
            'lower completion is red' => [79.99, 'bg-red-100'],
            'far below is red' => [5, 'bg-red-100'],
        ];
    }

    #[DataProvider('bandProvider')]
    public function test_the_completion_chip_colour_follows_the_band(float|int $completion, string $expectedClass): void
    {
        $html = $this->renderChips(['completion' => $completion]);

        $this->assertStringContainsString($expectedClass, $html);
    }

    public function test_the_percent_is_floored_so_an_incomplete_release_never_reads_complete(): void
    {
        $this->assertStringContainsString('99%', $this->renderChips(['completion' => 99.7]));
    }

    public function test_a_never_measured_release_shows_no_chips(): void
    {
        $html = $this->renderChips(['completion' => 0]);

        $this->assertSame('', trim($html));
    }

    public function test_the_repair_chip_only_appears_between_zero_and_complete(): void
    {
        $this->assertStringNotContainsString('Repair Attempt', $this->renderChips(['completion' => 100]));
        $this->assertStringNotContainsString('Repair Attempt', $this->renderChips(['completion' => 0]));
        $this->assertStringContainsString(ReleaseCompletion::PENDING_LABEL, $this->renderChips(['completion' => 42]));
    }

    public function test_the_repair_chip_shows_while_an_engine_can_still_take_the_release_and_never_otherwise(): void
    {
        $this->assertStringContainsString(ReleaseCompletion::PENDING_LABEL, $this->renderChips([
            'completion' => 42,
            'repair_outcome' => ReleaseRepairOutcome::Failed->value,
        ]), 'Repair final, re-scan still owed.');

        $finished = $this->renderChips([
            'completion' => 42,
            'repair_outcome' => ReleaseRepairOutcome::Failed->value,
            'rescan_outcome' => ReleaseRepairOutcome::SkippedBudget->value,
        ]);
        $this->assertStringContainsString('42%', $finished);
        $this->assertStringNotContainsString('Repair Attempt', $finished, 'Recovery exhausted: no repair chip, pending or complete.');
        $this->assertStringNotContainsString('repair-badge', $finished);

        $aboveTarget = $this->renderChips(['completion' => 99]);
        $this->assertStringContainsString('99%', $aboveTarget);
        $this->assertStringNotContainsString('Repair Attempt', $aboveTarget, 'No engine selects a release at or above the target.');

        $this->assertStringNotContainsString('Repair Attempt', $this->renderChips(['completion' => 42, 'nzbstatus' => 0]), 'No NZB yet.');
    }

    public function test_covers_tiles_stay_clean_art_at_full_completion(): void
    {
        $this->assertSame('', trim($this->renderChips(['completion' => 100], onlyWhenIncomplete: true)));
        $this->assertStringContainsString('42%', $this->renderChips(['completion' => 42], onlyWhenIncomplete: true));
    }

    public function test_every_chip_colour_carries_a_dark_variant(): void
    {
        $html = $this->renderChips(['completion' => 42]);
        $this->assertStringContainsString('repair-badge', $html);

        preg_match_all('/class="([^"]*)"/', $html, $classAttributes);
        $this->assertNotEmpty($classAttributes[1]);

        foreach ($classAttributes[1] as $classList) {
            foreach (['bg', 'text'] as $utility) {
                if (preg_match('/(?<!dark:)\b'.$utility.'-(?:green|yellow|red|gray)-\d{2,3}\b/', $classList) !== 1) {
                    continue;
                }

                $this->assertMatchesRegularExpression(
                    '/dark:'.$utility.'-(?:green|yellow|red|gray)-\d{2,3}\b/',
                    $classList,
                    'Every light '.$utility.' utility needs a dark variant: '.$classList
                );
            }
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function renderChips(array $attributes, bool $onlyWhenIncomplete = false): string
    {
        return Blade::render(
            '<x-release-completion-chips :release="$release" :only-when-incomplete="$onlyWhenIncomplete" />',
            [
                'release' => (object) array_merge(
                    ['completion' => 0, 'repair_outcome' => null, 'rescan_outcome' => null, 'declaredfiles' => null, 'totalpart' => 0,
                        'nzbstatus' => 1, 'groups_id' => null, 'postdate' => null],
                    $attributes
                ),
                'onlyWhenIncomplete' => $onlyWhenIncomplete,
            ]
        );
    }
}
