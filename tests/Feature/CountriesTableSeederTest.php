<?php

namespace Tests\Feature;

use App\Models\Country;
use Database\Seeders\CountriesTableSeeder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\Support\IsolatedSqliteDatabase;
use Tests\TestCase;

final class CountriesTableSeederTest extends TestCase
{
    use IsolatedSqliteDatabase;

    /**
     * @return array<string, string>
     */
    protected function bootstrapSettings(): array
    {
        return [
            'categorizeforeign' => '0',
            'catwebdl' => '0',
            'delaytime' => '2',
            'crossposttime' => '2',
            'maxnzbsprocessed' => '1000',
            'completionpercent' => '100',
            'collection_timeout' => '30',
            'maxsizetoformrelease' => '10737418240',
            'minsizetoformrelease' => '0',
            'minfilestoformrelease' => '1',
            'releaseretentiondays' => '0',
            'deletepasswordedrelease' => '0',
            'miscotherretentionhours' => '0',
            'mischashedretentionhours' => '0',
            'partretentionhours' => '0',
            'last_run_time' => '',
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootIsolatedDatabase();

        Schema::dropIfExists('countries');

        Schema::create('countries', function (Blueprint $table): void {
            $table->char('iso_3166_2', 2)->primary();
            $table->string('name');
            $table->string('full_name')->nullable();
            $table->index('name');
            $table->index('full_name');
        });
    }

    protected function tearDown(): void
    {
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    public function test_it_seeds_countries_using_iso_codes_as_the_primary_key(): void
    {
        $this->seed(CountriesTableSeeder::class);

        $country = Country::query()->find('US');

        $this->assertNotNull($country);
        $this->assertSame('United States', $country->name);
    }

    public function test_it_resolves_country_codes_from_country_names_and_full_names(): void
    {
        $this->seed(CountriesTableSeeder::class);

        $this->assertSame('DE', countryCode('Germany'));
        $this->assertSame('US', countryCode('United States of America'));
        $this->assertSame('', countryCode('Atlantis'));
    }
}
