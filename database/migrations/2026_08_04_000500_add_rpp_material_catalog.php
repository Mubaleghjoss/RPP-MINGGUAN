<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rpp_material_catalog_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('level_id')->constrained()->cascadeOnDelete();
            $table->foreignId('rpp_matrix_column_id')->nullable()->constrained('rpp_matrix_columns')->nullOnDelete();
            $table->foreignId('ggb_item_id')->nullable()->constrained()->cascadeOnDelete()->unique();
            $table->foreignId('syllabus_item_id')->nullable()->constrained()->cascadeOnDelete()->unique();
            $table->string('source_kind', 20);
            $table->string('display_code');
            $table->text('title');
            $table->string('semester_scope', 10)->default('both');
            $table->string('mapping_status', 30)->default('mapped');
            $table->unsignedInteger('sort_order');
            $table->unsignedInteger('lock_version')->default(0);
            $table->foreignId('last_edited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['level_id', 'display_code']);
            $table->index(['level_id', 'rpp_matrix_column_id', 'sort_order'], 'rpp_catalog_lookup');
        });

        Schema::create('rpp_week_item_materials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rpp_week_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('rpp_material_catalog_item_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['rpp_week_item_id', 'rpp_material_catalog_item_id'], 'rpp_week_catalog_unique');
        });

        Schema::table('rpp_week_items', function (Blueprint $table) {
            $table->dropUnique('rpp_week_item_unique');
            $table->string('source_fingerprint')->nullable()->after('syllabus_item_id');
            $table->unsignedSmallInteger('occurrence_no')->default(1)->after('source_fingerprint');
        });

        $occurrences = [];
        DB::table('rpp_week_items')->orderBy('id')->get()->each(function ($item) use (&$occurrences) {
            $fingerprint = 'syllabus:'.$item->syllabus_item_id;
            $key = $item->rpp_plan_id.':'.$item->calendar_week_id.':'.$fingerprint;
            $occurrences[$key] = ($occurrences[$key] ?? 0) + 1;
            DB::table('rpp_week_items')->where('id', $item->id)->update([
                'source_fingerprint' => $fingerprint,
                'occurrence_no' => $occurrences[$key],
            ]);
        });

        Schema::table('rpp_week_items', function (Blueprint $table) {
            $table->foreignId('syllabus_item_id')->nullable()->change();
            $table->unique(
                ['rpp_plan_id', 'calendar_week_id', 'source_fingerprint', 'occurrence_no'],
                'rpp_week_item_occurrence_unique'
            );
        });

        // Sinkronisasi katalog dijalankan setelah seluruh kolom katalog tersedia
        // pada migrasi normalisasi terbaru.
    }

    public function down(): void
    {
        DB::table('rpp_week_items')->whereNull('syllabus_item_id')->delete();
        Schema::table('rpp_week_items', function (Blueprint $table) {
            $table->dropUnique('rpp_week_item_occurrence_unique');
            $table->dropColumn(['source_fingerprint', 'occurrence_no']);
            $table->foreignId('syllabus_item_id')->nullable(false)->change();
            $table->unique(['rpp_plan_id', 'calendar_week_id', 'syllabus_item_id'], 'rpp_week_item_unique');
        });
        Schema::dropIfExists('rpp_week_item_materials');
        Schema::dropIfExists('rpp_material_catalog_items');
    }
};
