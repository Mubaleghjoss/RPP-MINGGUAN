<?php

use App\Models\Level;
use App\Services\RppMaterialCatalogService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('syllabus_items', function (Blueprint $table) {
            $table->boolean('is_source_artifact')->default(false)->after('is_duplicate')->index();
        });

        if (Schema::hasTable('levels') && DB::table('levels')->exists()) {
            Level::query()->orderBy('sort_order')->each(fn (Level $level) => app(RppMaterialCatalogService::class)->syncLevel($level));
            DB::table('rpp_plans')->update(['status' => 'draft', 'validated_at' => null]);
        }
    }

    public function down(): void
    {
        Schema::table('syllabus_items', function (Blueprint $table) {
            $table->dropIndex(['is_source_artifact']);
            $table->dropColumn('is_source_artifact');
        });
    }
};
