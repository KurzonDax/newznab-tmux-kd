<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Category;
use App\Services\Runners\PostProcessRunner;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\IsolatedSqliteDatabase;
use Tests\TestCase;

class PostProcessRunnerBooksGateTest extends TestCase
{
    use IsolatedSqliteDatabase;

    /**
     * @return array<string, string>
     */
    protected function bootstrapSettings(): array
    {
        return ['lookupbooks' => '1', 'postthreadsnon' => '1'];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootIsolatedDatabase();

        DB::table('settings')->upsert([
            ['name' => 'lookupbooks', 'value' => '1'],
            ['name' => 'postthreadsnon', 'value' => '1'],
        ], ['name'], ['value']);

        $this->createSchema();
    }

    protected function tearDown(): void
    {
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    public function test_process_books_treats_obfuscated_book_rows_as_pending_work(): void
    {
        DB::table('releases')->insert([
            'id' => 1,
            'name' => 'N_NZB_[2_5]_-_History_of_War_-_Issue_158_2026.rar',
            'searchname' => 'N_NZB_[2_5]_-_History_of_War_-_Issue_158_2026.rar',
            'groups_id' => 1,
            'size' => 1,
            'postdate' => now(),
            'adddate' => now(),
            'guid' => str_repeat('a', 40),
            'leftguid' => 'a',
            'fromname' => 'poster@example.com',
            'categories_id' => Category::BOOKS_MAGAZINES,
            'bookinfo_id' => -2,
        ]);

        $runner = new class extends PostProcessRunner
        {
            public array $captured = [];

            public function headerNone(): void {}

            protected function headerStart(string $workType, int $count, int $maxProcesses): void {}

            protected function executeCommand(string $command): string
            {
                $this->captured[] = $command;

                return '';
            }
        };

        $runner->processBooks();

        $this->assertCount(1, $runner->captured);
        $this->assertStringContainsString('artisan postprocess:guid books a', $runner->captured[0]);
    }

    public function test_renamed_only_mode_skips_unrenamed_pending_books(): void
    {
        DB::table('settings')->where('name', 'lookupbooks')->update(['value' => '2']);
        DB::table('releases')->insert([
            'id' => 2,
            'name' => 'A Plain Unrenamed Book',
            'searchname' => 'A Plain Unrenamed Book',
            'groups_id' => 1,
            'size' => 1,
            'postdate' => now(),
            'adddate' => now(),
            'guid' => str_repeat('b', 40),
            'leftguid' => 'b',
            'fromname' => 'poster@example.com',
            'categories_id' => Category::BOOKS_EBOOK,
            'bookinfo_id' => null,
            'isrenamed' => 0,
        ]);

        $runner = new class extends PostProcessRunner
        {
            public array $captured = [];

            public function headerNone(): void {}

            protected function headerStart(string $workType, int $count, int $maxProcesses): void {}

            protected function executeCommand(string $command): string
            {
                $this->captured[] = $command;

                return '';
            }
        };

        $runner->processBooks();

        $this->assertSame([], $runner->captured);
    }

    public function test_renamed_only_mode_skips_unrenamed_obfuscated_books(): void
    {
        DB::table('settings')->where('name', 'lookupbooks')->update(['value' => '2']);
        DB::table('releases')->insert([
            'id' => 2,
            'name' => 'N_NZB_Unrenamed_Book',
            'searchname' => 'N:/NZB Unrenamed Book',
            'groups_id' => 1,
            'size' => 1,
            'postdate' => now(),
            'adddate' => now(),
            'guid' => str_repeat('b', 40),
            'leftguid' => 'b',
            'fromname' => 'poster@example.com',
            'categories_id' => Category::BOOKS_EBOOK,
            'bookinfo_id' => null,
            'isrenamed' => 0,
        ]);

        $runner = new class extends PostProcessRunner
        {
            public array $captured = [];

            public function headerNone(): void {}

            protected function headerStart(string $workType, int $count, int $maxProcesses): void {}

            protected function executeCommand(string $command): string
            {
                $this->captured[] = $command;

                return '';
            }
        };

        $runner->processBooks();

        $this->assertSame([], $runner->captured);
    }

    private function createSchema(): void
    {
        if (! Schema::hasTable('settings')) {
            Schema::create('settings', function (Blueprint $table): void {
                $table->string('name')->primary();
                $table->text('value')->nullable();
            });
        }

        if (! Schema::hasTable('releases')) {
            Schema::create('releases', function (Blueprint $table): void {
                $table->increments('id');
                $table->string('name')->default('');
                $table->string('searchname')->default('');
                $table->unsignedInteger('groups_id')->default(0);
                $table->unsignedBigInteger('size')->default(0);
                $table->dateTime('postdate')->nullable();
                $table->dateTime('adddate')->nullable();
                $table->string('guid', 40);
                $table->char('leftguid', 1);
                $table->string('fromname')->nullable();
                $table->integer('categories_id')->default(Category::OTHER_MISC);
                $table->integer('bookinfo_id')->nullable();
                $table->tinyInteger('isrenamed')->default(0);
            });
        }
    }
}
