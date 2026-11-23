<?php

declare(strict_types=1);

namespace Tests\Unit\MusicIdentity;

use App\Services\MusicIdentity\MusicIdentityRetryPolicy;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** A release with no album yet is looked at again at twice its age, at least a day later, until it is 28 days old (#1277). */
final class MusicIdentityRetryPolicyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-11-23 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    #[Test]
    public function a_young_release_is_looked_at_again_no_sooner_than_a_day_later(): void
    {
        $this->assertSame('2026-11-24 12:00:00', $this->recheckAt(now()->subHours(12)));
    }

    #[Test]
    public function an_older_release_is_looked_at_again_when_it_is_twice_as_old(): void
    {
        $this->assertSame('2026-11-26 12:00:00', $this->recheckAt(now()->subDays(3)));
    }

    #[Test]
    public function a_look_that_would_fall_on_the_28th_day_is_kept_and_one_past_it_is_not(): void
    {
        $this->assertSame('2026-12-07 12:00:00', $this->recheckAt(now()->subDays(14)));
        $this->assertNull($this->recheckAt(now()->subDays(14)->subSecond()));
    }

    #[Test]
    public function no_post_date_gives_no_look_and_a_future_one_counts_as_new(): void
    {
        $this->assertNull($this->recheckAt(null));
        $this->assertSame('2026-11-24 12:00:00', $this->recheckAt(now()->addHour()));
    }

    #[Test]
    public function a_post_date_string_reads_as_the_same_date(): void
    {
        $postdate = now()->subDays(3);

        $this->assertSame($this->recheckAt($postdate), $this->recheckAt($postdate->format('Y-m-d H:i:s')));
    }

    #[Test]
    public function a_zero_age_limit_schedules_no_later_look(): void
    {
        config(['music-identity.recheck.maximum_age_seconds' => 0]);

        $this->assertNull($this->recheckAt(now()->subHours(12)));
    }

    #[Test]
    public function a_failed_later_look_is_retried_on_the_retry_delay_until_the_release_is_too_old(): void
    {
        $policy = new MusicIdentityRetryPolicy;

        $this->assertSame(
            $policy->nextAttemptAt(1)->format('Y-m-d H:i:s'),
            $policy->recheckRetryAt(now()->subDays(27), 1)?->format('Y-m-d H:i:s'),
        );
        $this->assertNull($policy->recheckRetryAt(now()->subDays(28)->subSecond(), 1));
        $this->assertNull($policy->recheckRetryAt(null, 1));
    }

    private function recheckAt(mixed $postdate): ?string
    {
        return (new MusicIdentityRetryPolicy)->recheckAt($postdate)?->format('Y-m-d H:i:s');
    }
}
