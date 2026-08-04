<?php

use App\Models\Level;
use App\Models\RevisionBatch;
use App\Models\RevisionItem;
use App\Models\RppMaterialCatalogItem;
use App\Models\RppWeekItem;
use App\Services\GgbOutlineService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ggb_items', function (Blueprint $table) {
            $table->string('rpp_role', 20)->default('material')->after('kind')->index();
            $table->string('rpp_role_source', 20)->default('auto')->after('rpp_role');
        });

        Schema::table('rpp_material_catalog_items', function (Blueprint $table) {
            $table->boolean('is_schedulable')->default(true)->after('source_kind')->index();
            $table->boolean('is_active')->default(true)->after('auto_include');
            $table->boolean('rotation_enabled')->default(false)->after('is_active');
            $table->string('origin_key')->nullable()->after('rotation_enabled');
            $table->unique(['level_id', 'origin_key'], 'rpp_catalog_origin_unique');
        });

        if (! Schema::hasTable('levels') || ! DB::table('levels')->exists()) {
            return;
        }

        $outline = app(GgbOutlineService::class);
        Level::query()->orderBy('sort_order')->each(fn (Level $level) => $outline->classifyLevel($level, false));

        RppMaterialCatalogItem::query()->where('source_kind', 'ggb')->update(['is_schedulable' => true]);
        RppMaterialCatalogItem::query()->where('source_kind', 'ggb')
            ->whereHas('ggbItem', fn ($query) => $query->where('rpp_role', '!=', 'material'))
            ->update(['is_schedulable' => false, 'auto_include' => false]);

        $obsolete = RppWeekItem::query()
            ->whereNull('syllabus_item_id')
            ->whereHas('materials', fn ($query) => $query->where('is_schedulable', false))
            ->whereDoesntHave('materials', fn ($query) => $query->where('is_schedulable', true))
            ->with('materials:id')
            ->lockForUpdate()
            ->get();

        if ($obsolete->isNotEmpty()) {
            DB::transaction(function () use ($obsolete) {
                $batch = RevisionBatch::query()->create([
                    'uuid' => (string) Str::uuid(),
                    'user_id' => null,
                    'action' => 'normalize',
                    'reason' => 'Normalisasi otomatis: subjudul dan artefak GGB dikeluarkan dari RPP.',
                    'item_count' => $obsolete->count(),
                ]);
                foreach ($obsolete as $item) {
                    RevisionItem::query()->create([
                        'revision_batch_id' => $batch->id,
                        'revisable_type' => 'rpp',
                        'revisable_id' => $item->id,
                        'before_values' => $item->only([
                            'rpp_plan_id', 'calendar_week_id', 'syllabus_item_id', 'source_fingerprint',
                            'occurrence_no', 'rpp_matrix_column_id', 'strand', 'content', 'source',
                            'is_locked', 'position', 'progress_start', 'progress_end', 'progress_kind',
                        ]) + ['material_catalog_ids' => $item->materials->pluck('id')->all()],
                        'after_values' => ['removed_by_normalization' => true],
                        'before_lock_version' => (int) $item->lock_version,
                        'after_lock_version' => (int) $item->lock_version + 1,
                    ]);
                    $item->delete();
                }
            });
        }

        DB::table('rpp_plans')->update(['status' => 'draft', 'validated_at' => null]);
        DB::table('rpp_annual_validations')->update([
            'status' => 'draft', 'validated_at' => null, 'validated_by' => null,
        ]);
    }

    public function down(): void
    {
        Schema::table('rpp_material_catalog_items', function (Blueprint $table) {
            $table->dropUnique('rpp_catalog_origin_unique');
            $table->dropColumn(['is_schedulable', 'is_active', 'rotation_enabled', 'origin_key']);
        });

        Schema::table('ggb_items', function (Blueprint $table) {
            $table->dropIndex(['rpp_role']);
            $table->dropColumn(['rpp_role', 'rpp_role_source']);
        });

    }
};
