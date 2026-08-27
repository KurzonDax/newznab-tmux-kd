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
 * itself in a listing, so their bands and their two repair labels are pinned.
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
            'green boundary' => [95, 'bg-green-100'],
            'just below green is yellow' => [94.99, 'bg-yellow-100'],
            'yellow boundary' => [80, 'bg-yellow-100'],
            'just below yellow is red' => [79.99, 'bg-red-100'],
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

    public function test_the_repair_chip_reports_pending_until_both_machines_are_final(): void
    {
        $this->assertStringContainsString(ReleaseCompletion::PENDING_LABEL, $this->renderChips([
            'completion' => 42,
            'repair_outcome' => ReleaseRepairOutcome::Failed->value,
        ]));

        $complete = $this->renderChips([
            'completion' => 42,
            'repair_outcome' => ReleaseRepairOutcome::Failed->value,
            'rescan_outcome' => ReleaseRepairOutcome::SkippedBudget->value,
        ]);

        $this->assertStringContainsString(ReleaseCompletion::COMPLETE_LABEL, $complete);
        $this->assertStringContainsString('bg-gray-100', $complete);
    }

    public function test_covers_tiles_stay_clean_art_at_full_completion(): void
    {
        $this->assertSame('', trim($this->renderChips(['completion' => 100], onlyWhenIncomplete: true)));
        $this->assertStringContainsString('42%', $this->renderChips(['completion' => 42], onlyWhenIncomplete: true));
    }

    public function test_every_chip_colour_carries_a_dark_variant(): void
    {
        $html = $this->renderChips([
            'completion' => 42,
            'repair_outcome' => ReleaseRepairOutcome::Failed->value,
            'rescan_outcome' => ReleaseRepairOutcome::Failed->value,
        ]);

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

    public function test_the_details_page_shows_completion_and_repair_status(): void
    {
        $sidebar = file_get_contents(resource_path('views/details/partials/info-sidebar.blade.php'));

        $this->assertIsString($sidebar);
        $this->assertStringContainsString('Completion', $sidebar);
        $this->assertStringContainsString('Not measured', $sidebar);
        $this->assertStringContainsString('Repair status', $sidebar);
        $this->assertStringContainsString('ReleaseCompletion::isIncomplete', $sidebar);
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
                    ['completion' => 0, 'repair_outcome' => null, 'rescan_outcome' => null],
                    $attributes
                ),
                'onlyWhenIncomplete' => $onlyWhenIncomplete,
            ]
        );
    }
}
