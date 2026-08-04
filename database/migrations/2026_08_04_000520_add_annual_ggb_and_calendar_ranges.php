<?php

use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('academic_semesters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('academic_year_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('semester');
            $table->date('starts_on');
            $table->date('ends_on');
            $table->unsignedInteger('lock_version')->default(0);
            $table->foreignId('last_edited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['academic_year_id', 'semester']);
        });

        Schema::create('calendar_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('academic_year_id')->constrained()->cascadeOnDelete();
            $table->string('type', 30);
            $table->string('title');
            $table->text('details')->nullable();
            $table->date('starts_on');
            $table->date('ends_on');
            $table->boolean('applies_to_all')->default(true);
            $table->unsignedInteger('lock_version')->default(0);
            $table->foreignId('last_edited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['academic_year_id', 'starts_on', 'ends_on']);
        });

        Schema::create('calendar_event_level', function (Blueprint $table) {
            $table->foreignId('calendar_event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('level_id')->constrained()->cascadeOnDelete();
            $table->primary(['calendar_event_id', 'level_id']);
        });

        Schema::create('rpp_annual_validations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('academic_year_id')->constrained()->cascadeOnDelete();
            $table->foreignId('level_id')->constrained()->cascadeOnDelete();
            $table->string('status', 30)->default('draft');
            $table->decimal('coverage_percent', 5, 2)->default(0);
            $table->timestamp('validated_at')->nullable();
            $table->foreignId('validated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['academic_year_id', 'level_id']);
        });

        Schema::table('rpp_material_catalog_items', function (Blueprint $table) {
            $table->string('source_semester_scope', 10)->nullable()->after('semester_scope');
            $table->boolean('semester_confirmed')->default(false)->after('source_semester_scope');
            $table->boolean('auto_include')->default(false)->after('semester_confirmed');
            $table->index(['level_id', 'source_kind', 'auto_include'], 'rpp_catalog_auto_lookup');
        });

        $now = now();
        DB::table('academic_years')->orderBy('id')->each(function ($year) use ($now) {
            foreach ([1, 2] as $semester) {
                $weeks = DB::table('calendar_weeks')
                    ->where('academic_year_id', $year->id)
                    ->where('semester', $semester)
                    ->orderBy('starts_on')
                    ->get();
                if ($weeks->isEmpty()) {
                    continue;
                }
                DB::table('academic_semesters')->insert([
                    'academic_year_id' => $year->id,
                    'semester' => $semester,
                    'starts_on' => $weeks->first()->starts_on,
                    'ends_on' => CarbonImmutable::parse($weeks->last()->starts_on)->addDays(6)->toDateString(),
                    'lock_version' => 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            DB::table('calendar_weeks')
                ->where('academic_year_id', $year->id)
                ->where('is_effective', false)
                ->orderBy('week_number')
                ->each(function ($week) use ($year, $now) {
                    DB::table('calendar_events')->insert([
                        'academic_year_id' => $year->id,
                        'type' => in_array($week->type, ['holiday', 'religious_holiday', 'evaluation', 'exam'], true) ? $week->type : 'holiday',
                        'title' => $week->label ?: match ($week->type) {
                            'evaluation' => 'Evaluasi',
                            'religious_holiday' => 'Hari Raya',
                            'exam' => 'Ujian',
                            default => 'Libur',
                        },
                        'details' => 'Dimigrasikan dari pengaturan minggu lama.',
                        'starts_on' => $week->starts_on,
                        'ends_on' => CarbonImmutable::parse($week->starts_on)->addDays(6)->toDateString(),
                        'applies_to_all' => true,
                        'lock_version' => 0,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                });
        });

        DB::table('rpp_material_catalog_items')->orderBy('id')->each(function ($item) {
            $explicit = in_array((string) $item->semester_scope, ['1', '2'], true);
            DB::table('rpp_material_catalog_items')->where('id', $item->id)->update([
                'source_semester_scope' => $explicit ? (string) $item->semester_scope : 'general',
                'semester_confirmed' => $explicit,
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('rpp_material_catalog_items', function (Blueprint $table) {
            $table->dropIndex('rpp_catalog_auto_lookup');
            $table->dropColumn(['source_semester_scope', 'semester_confirmed', 'auto_include']);
        });
        Schema::dropIfExists('rpp_annual_validations');
        Schema::dropIfExists('calendar_event_level');
        Schema::dropIfExists('calendar_events');
        Schema::dropIfExists('academic_semesters');
    }
};
