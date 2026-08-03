<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('levels', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('stage');
            $table->unsignedTinyInteger('grade')->nullable();
            $table->string('age')->nullable();
            $table->unsignedTinyInteger('sort_order');
            $table->timestamps();
        });

        Schema::create('source_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('level_id')->constrained()->cascadeOnDelete();
            $table->string('source_key')->unique();
            $table->string('type', 20);
            $table->string('title');
            $table->string('path');
            $table->char('sha256', 64);
            $table->unsignedSmallInteger('page_count');
            $table->timestamps();
            $table->unique(['level_id', 'type']);
        });

        Schema::create('ggb_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('level_id')->constrained()->cascadeOnDelete();
            $table->foreignId('source_document_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('ggb_items')->nullOnDelete();
            $table->string('source_key')->unique();
            $table->string('stable_code')->index();
            $table->string('kind', 30);
            $table->string('aspect');
            $table->string('subaspect');
            $table->text('title');
            $table->text('raw_text');
            $table->unsignedSmallInteger('source_page');
            $table->unsignedInteger('sort_order');
            $table->timestamps();
            $table->index(['level_id', 'sort_order']);
        });

        Schema::create('syllabus_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('level_id')->constrained()->cascadeOnDelete();
            $table->foreignId('source_document_id')->constrained()->cascadeOnDelete();
            $table->string('source_key')->unique();
            $table->string('stable_code')->index();
            $table->text('category');
            $table->text('title');
            $table->text('description');
            $table->text('allocation_text')->nullable();
            $table->text('reference_text')->nullable();
            $table->text('assessment_text')->nullable();
            $table->unsignedSmallInteger('recommended_sessions')->nullable();
            $table->boolean('needs_allocation')->default(false);
            $table->boolean('is_duplicate')->default(false);
            $table->unsignedSmallInteger('source_page');
            $table->unsignedInteger('sort_order');
            $table->unsignedSmallInteger('group_number')->nullable();
            $table->timestamps();
            $table->index(['level_id', 'sort_order']);
            $table->index(['level_id', 'is_duplicate']);
        });

        Schema::create('ggb_syllabus_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ggb_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('syllabus_item_id')->constrained()->cascadeOnDelete();
            $table->string('status', 30);
            $table->decimal('confidence', 6, 4)->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['ggb_item_id', 'syllabus_item_id']);
            $table->index(['status', 'confidence']);
        });

        Schema::create('academic_years', function (Blueprint $table) {
            $table->id();
            $table->string('label')->unique();
            $table->date('starts_on');
            $table->date('ends_on');
            $table->boolean('is_active')->default(false);
            $table->timestamps();
        });

        Schema::create('calendar_weeks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('academic_year_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('week_number');
            $table->date('starts_on');
            $table->string('month_label');
            $table->string('type', 30)->default('effective');
            $table->string('label')->nullable();
            $table->boolean('is_effective')->default(true);
            $table->timestamps();
            $table->unique(['academic_year_id', 'week_number']);
        });

        Schema::create('rpp_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('academic_year_id')->constrained()->cascadeOnDelete();
            $table->foreignId('level_id')->constrained()->cascadeOnDelete();
            $table->string('status', 30)->default('draft');
            $table->decimal('coverage_percent', 5, 2)->default(0);
            $table->timestamp('validated_at')->nullable();
            $table->timestamps();
            $table->unique(['academic_year_id', 'level_id']);
        });

        Schema::create('rpp_week_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rpp_plan_id')->constrained()->cascadeOnDelete();
            $table->foreignId('calendar_week_id')->constrained()->cascadeOnDelete();
            $table->foreignId('syllabus_item_id')->constrained()->cascadeOnDelete();
            $table->text('strand');
            $table->text('content');
            $table->string('source', 20)->default('auto');
            $table->boolean('is_locked')->default(false);
            $table->unsignedSmallInteger('position')->default(1);
            $table->timestamps();
            $table->unique(['rpp_plan_id', 'calendar_week_id', 'syllabus_item_id'], 'rpp_week_item_unique');
            $table->index(['rpp_plan_id', 'calendar_week_id'], 'rpp_week_lookup');
        });

        Schema::create('audit_findings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('level_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('type', 40);
            $table->string('severity', 20)->default('warning');
            $table->string('status', 20)->default('open');
            $table->text('message');
            $table->json('data')->nullable();
            $table->timestamps();
            $table->index(['status', 'severity']);
        });

        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action');
            $table->json('details')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_logs');
        Schema::dropIfExists('audit_findings');
        Schema::dropIfExists('rpp_week_items');
        Schema::dropIfExists('rpp_plans');
        Schema::dropIfExists('calendar_weeks');
        Schema::dropIfExists('academic_years');
        Schema::dropIfExists('ggb_syllabus_links');
        Schema::dropIfExists('syllabus_items');
        Schema::dropIfExists('ggb_items');
        Schema::dropIfExists('source_documents');
        Schema::dropIfExists('levels');
    }
};
