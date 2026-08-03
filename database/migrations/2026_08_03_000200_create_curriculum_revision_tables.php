<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ggb_items', function (Blueprint $table) {
            $table->text('target_text')->nullable()->after('title');
            $table->json('source_payload')->nullable()->after('raw_text');
            $table->unsignedInteger('lock_version')->default(0)->after('sort_order');
            $table->foreignId('last_edited_by')->nullable()->after('lock_version')->constrained('users')->nullOnDelete();
        });

        Schema::table('syllabus_items', function (Blueprint $table) {
            $table->json('source_payload')->nullable()->after('description');
            $table->unsignedInteger('lock_version')->default(0)->after('group_number');
            $table->foreignId('last_edited_by')->nullable()->after('lock_version')->constrained('users')->nullOnDelete();
        });

        Schema::table('ggb_syllabus_links', function (Blueprint $table) {
            $table->unsignedInteger('lock_version')->default(0)->after('notes');
            $table->foreignId('last_edited_by')->nullable()->after('lock_version')->constrained('users')->nullOnDelete();
            $table->softDeletes();
        });

        Schema::table('rpp_week_items', function (Blueprint $table) {
            $table->unsignedInteger('lock_version')->default(0)->after('position');
            $table->foreignId('last_edited_by')->nullable()->after('lock_version')->constrained('users')->nullOnDelete();
        });

        Schema::create('revision_batches', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action', 20)->default('edit');
            $table->uuid('source_batch_uuid')->nullable();
            $table->text('reason');
            $table->unsignedInteger('item_count')->default(0);
            $table->timestamps();
            $table->index(['created_at', 'action']);
        });

        Schema::create('revision_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('revision_batch_id')->constrained()->cascadeOnDelete();
            $table->string('revisable_type', 30);
            $table->unsignedBigInteger('revisable_id');
            $table->json('before_values');
            $table->json('after_values');
            $table->unsignedInteger('before_lock_version');
            $table->unsignedInteger('after_lock_version');
            $table->timestamps();
            $table->index(['revisable_type', 'revisable_id']);
        });

        $this->backfillSnapshots();
    }

    private function backfillSnapshots(): void
    {
        DB::table('ggb_items')->orderBy('id')->chunkById(250, function ($items) {
            foreach ($items as $item) {
                DB::table('ggb_items')->where('id', $item->id)->update([
                    'source_payload' => json_encode([
                        'aspect' => $item->aspect,
                        'subaspect' => $item->subaspect,
                        'title' => $item->title,
                        'target_text' => null,
                        'sort_order' => $item->sort_order,
                    ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                ]);
            }
        });

        DB::table('syllabus_items')->orderBy('id')->chunkById(250, function ($items) {
            foreach ($items as $item) {
                DB::table('syllabus_items')->where('id', $item->id)->update([
                    'source_payload' => json_encode([
                        'category' => $item->category,
                        'title' => $item->title,
                        'description' => $item->description,
                        'allocation_text' => $item->allocation_text,
                        'recommended_sessions' => $item->recommended_sessions,
                        'reference_text' => $item->reference_text,
                        'assessment_text' => $item->assessment_text,
                        'is_duplicate' => (bool) $item->is_duplicate,
                        'sort_order' => $item->sort_order,
                    ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                ]);
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('revision_items');
        Schema::dropIfExists('revision_batches');

        Schema::table('rpp_week_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('last_edited_by');
            $table->dropColumn('lock_version');
        });
        Schema::table('ggb_syllabus_links', function (Blueprint $table) {
            $table->dropConstrainedForeignId('last_edited_by');
            $table->dropColumn(['lock_version', 'deleted_at']);
        });
        Schema::table('syllabus_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('last_edited_by');
            $table->dropColumn(['source_payload', 'lock_version']);
        });
        Schema::table('ggb_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('last_edited_by');
            $table->dropColumn(['target_text', 'source_payload', 'lock_version']);
        });
    }
};
