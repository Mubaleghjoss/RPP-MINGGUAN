<?php

use App\Services\RppMatrixPresetService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rpp_matrix_columns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('level_id')->constrained()->cascadeOnDelete();
            $table->string('stable_key');
            $table->string('aspect_label');
            $table->string('subaspect_label')->nullable();
            $table->string('label');
            $table->unsignedInteger('sort_order');
            $table->unsignedSmallInteger('width')->default(22);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('lock_version')->default(0);
            $table->foreignId('last_edited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['level_id', 'stable_key']);
            $table->index(['level_id', 'sort_order']);
        });

        Schema::create('rpp_matrix_mappings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rpp_matrix_column_id')->constrained()->cascadeOnDelete();
            $table->foreignId('syllabus_item_id')->constrained()->cascadeOnDelete()->unique();
            $table->unsignedInteger('lock_version')->default(0);
            $table->foreignId('last_edited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('rpp_month_focuses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rpp_plan_id')->constrained()->cascadeOnDelete();
            $table->string('month_key', 7);
            $table->string('month_label');
            $table->text('focus_text')->nullable();
            $table->string('source', 20)->default('suggested');
            $table->boolean('is_locked')->default(false);
            $table->unsignedInteger('lock_version')->default(0);
            $table->foreignId('last_edited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['rpp_plan_id', 'month_key']);
        });

        Schema::table('syllabus_items', function (Blueprint $table) {
            $table->string('schedule_pattern', 30)->nullable()->after('recommended_sessions')->index();
            $table->string('schedule_pattern_source', 20)->default('auto')->after('schedule_pattern');
        });
        Schema::table('rpp_week_items', function (Blueprint $table) {
            $table->foreignId('rpp_matrix_column_id')->nullable()->after('rpp_progress_target_id')->constrained('rpp_matrix_columns')->nullOnDelete();
        });

        if (Schema::hasTable('levels') && DB::table('levels')->exists()) {
            app(RppMatrixPresetService::class)->syncAll();
        }
    }

    public function down(): void
    {
        Schema::table('rpp_week_items', fn (Blueprint $table) => $table->dropConstrainedForeignId('rpp_matrix_column_id'));
        Schema::table('syllabus_items', fn (Blueprint $table) => $table->dropColumn(['schedule_pattern', 'schedule_pattern_source']));
        Schema::dropIfExists('rpp_month_focuses');
        Schema::dropIfExists('rpp_matrix_mappings');
        Schema::dropIfExists('rpp_matrix_columns');
    }
};
