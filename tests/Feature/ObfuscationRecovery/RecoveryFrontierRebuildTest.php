<?php

declare(strict_types=1);

namespace Tests\Feature\ObfuscationRecovery;

use Tests\Support\IsolatedSqliteDatabase;
use Tests\Support\ObfuscationRecovery\ChecksRetainedFrontierRebuild;
use Tests\TestCase;

final class RecoveryFrontierRebuildTest extends TestCase
{
    use ChecksRetainedFrontierRebuild;
    use IsolatedSqliteDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootIsolatedDatabase();
        $this->seedRetainedFrontierFixture();
    }

    protected function tearDown(): void
    {
        $this->stopRecoveryServers();
        $this->travelBack();
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }
}
