<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use Tests\TestCase;

class IrcScraperCommandTest extends TestCase
{
    public function test_an_empty_username_names_the_env_key(): void
    {
        // Empty, not null: a null username skips the check and opens a live IRC connection.
        config(['irc_settings.scrape_irc_username' => '']);

        $this->artisan('irc:scrape')
            ->expectsOutput('ERROR! Set SCRAPE_IRC_USERNAME in .env.')
            ->assertFailed();
    }
}
