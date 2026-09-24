<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The text pairs every page relies on meet WCAG AA (4.5:1) in both themes,
 * computed from the token values in resources/css/app.css.
 */
class DesignTokenContrastTest extends TestCase
{
    /** @var array<string, string>|null */
    private static ?array $tokens = null;

    /** @return array<string, array{string, string}> */
    public static function textPairs(): array
    {
        return [
            'body text on the page ground' => ['--text-default', '--surface-body'],
            'muted text on the page ground' => ['--text-muted', '--surface-body'],
            'muted text on a card' => ['--text-muted', '--surface-card'],
            'accent text on the accent surface' => ['--accent-on', '--accent-surface'],
        ];
    }

    #[DataProvider('textPairs')]
    public function test_light_theme_pair_meets_aa(string $foreground, string $background): void
    {
        $this->assertMeetsAa($foreground, $background);
    }

    #[DataProvider('textPairs')]
    public function test_dark_theme_pair_meets_aa(string $foreground, string $background): void
    {
        $this->assertMeetsAa($foreground.'-dark', $background.'-dark');
    }

    public function test_white_on_the_dark_accent_surface_is_the_failure_the_pair_avoids(): void
    {
        $this->assertLessThan(4.5, $this->contrast('#ffffff', $this->token('--accent-surface-dark')));
    }

    public function test_the_coral_ramp_is_pinned_to_the_approved_corals(): void
    {
        $this->assertSame('#ff5a3c', $this->token('--color-primary-500'));
        $this->assertSame('#c8331a', $this->token('--color-primary-700'));
        $this->assertSame($this->token('--color-primary-700'), $this->token('--accent-surface'));
        $this->assertSame($this->token('--color-primary-500'), $this->token('--accent-surface-dark'));
    }

    private function assertMeetsAa(string $foreground, string $background): void
    {
        $ratio = $this->contrast($this->token($foreground), $this->token($background));

        $this->assertGreaterThanOrEqual(4.5, $ratio, sprintf('%s on %s is %.2f:1', $foreground, $background, $ratio));
    }

    private function token(string $name): string
    {
        if (self::$tokens === null) {
            $css = (string) file_get_contents(dirname(__DIR__, 2).'/resources/css/app.css');
            preg_match_all('/(--[a-z0-9-]+):\s*(#[0-9a-f]{6})\s*;/i', $css, $matches, PREG_SET_ORDER);
            self::$tokens = [];
            foreach ($matches as [, $token, $value]) {
                self::$tokens[$token] ??= strtolower($value);
            }
        }

        $this->assertArrayHasKey($name, self::$tokens, "app.css declares {$name} as a hex colour");

        return self::$tokens[$name];
    }

    private function contrast(string $first, string $second): float
    {
        $lighter = max($this->luminance($first), $this->luminance($second));
        $darker = min($this->luminance($first), $this->luminance($second));

        return ($lighter + 0.05) / ($darker + 0.05);
    }

    private function luminance(string $hex): float
    {
        $channels = array_map(static function (string $pair): float {
            $value = hexdec($pair) / 255;

            return $value <= 0.03928 ? $value / 12.92 : (($value + 0.055) / 1.055) ** 2.4;
        }, str_split(substr($hex, 1), 2));

        return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
    }
}
