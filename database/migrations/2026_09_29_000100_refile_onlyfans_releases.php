<?php

declare(strict_types=1);

use App\Services\Categorization\ReleaseRecategorizer;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Retires the XXX > OnlyFans category (6047). Its releases are refiled by the normal
 * rules first, because releases.categories_id has no foreign key; deleting the row then
 * drops users' exclusions of it through their ON DELETE CASCADE.
 */
return new class extends Migration
{
    public function up(): void
    {
        app(ReleaseRecategorizer::class)->refileCategory(6047);

        DB::table('categories')->where('id', 6047)->delete();
    }

    public function down(): void
    {
        // The refiled releases stay where the rules put them; the category is gone for good.
    }
};
