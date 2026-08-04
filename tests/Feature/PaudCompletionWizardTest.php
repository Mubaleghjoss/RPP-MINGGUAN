<?php

namespace Tests\Feature;

use App\Livewire\ExportPreview;
use App\Models\AcademicSemester;
use App\Models\AcademicYear;
use App\Models\CalendarWeek;
use App\Models\Level;
use App\Models\RppAnnualValidation;
use App\Models\RppMaterialCatalogItem;
use App\Models\RppMatrixColumn;
use App\Models\RppPlan;
use App\Models\RppProgressTarget;
use App\Models\RppWeekItem;
use App\Models\SourceDocument;
use App\Models\SyllabusItem;
use App\Models\User;
use App\Services\RppAnnualGgbService;
use App\Services\RppCompletionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class PaudCompletionWizardTest extends TestCase
{
    use RefreshDatabase;

    public function test_balanced_confirmation_assigns_130_materials_65_per_semester_and_preserves_manual_decisions(): void
    {
        [$level, $year, $plans, $columns] = $this->fixtureWithCatalog();
        $user = User::factory()->create();
        $manual = $level->materialCatalogItems()->where('sort_order', 130)->firstOrFail();
        $manual->update(['semester_scope' => '2', 'semester_confirmed' => true]);
        $suggested = $level->materialCatalogItems()->where('sort_order', 70)->firstOrFail();
        $suggested->update(['mapping_status' => 'unmapped']);
        $plans->each->update(['status' => 'validated', 'validated_at' => now()]);
        $service = app(RppAnnualGgbService::class);

        $preview = $service->balancedPreview($level);
        $this->assertSame(65, $preview['semester_1']);
        $this->assertSame(65, $preview['semester_2']);
        $this->assertSame(1, $preview['suggested_mapping_count']);

        try {
            $service->confirmGeneralBalanced($level, 'Pembagian awal PAUD', false, $user->id);
            $this->fail('Saran kolom harus dikonfirmasi Admin.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('Centang persetujuan', collect($exception->errors())->flatten()->first());
        }

        $result = $service->confirmGeneralBalanced($level, 'Pembagian awal PAUD', true, $user->id);
        $this->assertSame(129, $result['changed']);
        $this->assertSame(65, $result['semester_1']);
        $this->assertSame(65, $result['semester_2']);
        $this->assertSame('2', $manual->fresh()->semester_scope);
        $this->assertTrue($manual->fresh()->semester_confirmed);
        $this->assertSame('mapped', $suggested->fresh()->mapping_status);
        $this->assertSame(129, $level->materialCatalogItems()->where('semester_confirmed', true)->where('id', '!=', $manual->id)->count());
        $this->assertSame(129, $this->getConnection()->table('revision_items')->where('revisable_type', 'material_catalog')->count());
        $this->assertTrue($plans->every(fn ($plan) => $plan->fresh()->status === 'draft'));

        foreach ($columns as $column) {
            $automatic = $level->materialCatalogItems()->where('rpp_matrix_column_id', $column->id)->where('id', '!=', $manual->id)->orderBy('sort_order')->get();
            $lastSemesterOne = $automatic->where('semester_scope', '1')->max('sort_order');
            $firstSemesterTwo = $automatic->where('semester_scope', '2')->min('sort_order');
            if ($lastSemesterOne && $firstSemesterTwo) {
                $this->assertLessThan($firstSemesterTwo, $lastSemesterOne);
            }
        }

        $repeat = $service->confirmGeneralBalanced($level, 'Uji pembagian idempoten', true, $user->id);
        $this->assertSame(0, $repeat['changed']);
        $this->assertSame(65, $repeat['semester_1']);
        $this->assertSame(65, $repeat['semester_2']);
    }

    public function test_unresolved_mapping_blocks_balanced_confirmation_atomically(): void
    {
        [$level] = $this->fixtureWithCatalog();
        $unresolved = $level->materialCatalogItems()->where('sort_order', 1)->firstOrFail();
        $unresolved->update(['rpp_matrix_column_id' => null, 'mapping_status' => 'unmapped']);

        try {
            app(RppAnnualGgbService::class)->confirmGeneralBalanced($level, 'Pembagian awal PAUD', true, null);
            $this->fail('Materi tanpa saran kolom seharusnya ditolak.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('belum mempunyai saran kolom', collect($exception->errors())->flatten()->first());
        }

        $this->assertSame(0, $level->materialCatalogItems()->where('semester_confirmed', true)->count());
        $this->assertSame(0, $this->getConnection()->table('revision_batches')->count());
    }

    public function test_empty_reason_is_localized_and_livewire_focuses_the_related_field(): void
    {
        [$level] = $this->fixtureWithCatalog();
        $user = User::factory()->create();

        try {
            app(RppAnnualGgbService::class)->confirmGeneralBalanced($level, '', true, $user->id);
            $this->fail('Alasan kosong seharusnya ditolak.');
        } catch (ValidationException $exception) {
            $message = collect($exception->errors())->flatten()->first();
            $this->assertSame('Alasan tindakan wajib diisi. Contoh: Penyusunan awal RPP PAUD.', $message);
            $this->assertStringNotContainsString('validation.', $message);
        }

        Livewire::actingAs($user)->test(ExportPreview::class)
            ->set('levelId', $level->id)
            ->set('detail', 'ggb')
            ->call('balancePaudGgb')
            ->assertHasErrors(['ggbReason'])
            ->assertDispatched('focus-validation-field')
            ->assertSee('Alasan tindakan wajib diisi');
    }

    public function test_completion_report_reaches_100_only_after_all_five_checks_are_complete(): void
    {
        [$level, $year, $plans, $columns, $weeks] = $this->fixtureWithCatalog();
        $service = app(RppAnnualGgbService::class);
        $service->confirmGeneralBalanced($level, 'Pembagian awal PAUD', true, null);
        $before = app(RppCompletionService::class)->report($year, $level);
        $this->assertFalse($before['complete']);
        $this->assertSame(40, $before['percent']);

        $document = SourceDocument::query()->create([
            'level_id' => $level->id, 'source_key' => 'silabus:paud:test', 'type' => 'silabus',
            'title' => 'Silabus PAUD Test', 'path' => 'silabus-paud.pdf', 'sha256' => str_repeat('c', 64), 'page_count' => 2,
        ]);
        $syllabus = SyllabusItem::query()->create([
            'level_id' => $level->id, 'source_document_id' => $document->id, 'source_key' => 'silabus:paud:tilawati',
            'stable_code' => 'PAUD / TILAWATI / S001', 'category' => 'Tilawati', 'title' => 'Tilawati PAUD',
            'description' => 'Membaca Tilawati', 'allocation_text' => 'Setiap minggu', 'recommended_sessions' => 1,
            'schedule_pattern' => 'weekly', 'schedule_pattern_source' => 'auto', 'needs_allocation' => false,
            'is_duplicate' => false, 'source_page' => 1, 'sort_order' => 1, 'group_number' => 1,
            'source_semester' => 'both', 'semester_scope' => 'both',
        ]);

        foreach ([1 => [1, 22], 2 => [23, 44]] as $semester => $range) {
            $target = RppProgressTarget::query()->create([
                'rpp_plan_id' => $plans[$semester]->id, 'syllabus_item_id' => $syllabus->id,
                'unit_label' => 'halaman', 'range_start' => $range[0], 'range_end' => $range[1],
                'strategy' => 'even', 'source' => 'auto',
            ]);
            $placement = RppWeekItem::query()->create([
                'rpp_plan_id' => $plans[$semester]->id, 'calendar_week_id' => $weeks[$semester]->id,
                'syllabus_item_id' => $syllabus->id, 'rpp_progress_target_id' => $target->id,
                'source_fingerprint' => 'syllabus:'.$syllabus->id, 'occurrence_no' => 1,
                'rpp_matrix_column_id' => $columns->first()->id, 'strand' => 'Tilawati PAUD',
                'content' => "Tilawati halaman {$range[0]}–{$range[1]}", 'progress_start' => $range[0],
                'progress_end' => $range[1], 'progress_kind' => 'materi_baru', 'source' => 'auto',
                'is_locked' => false, 'position' => 1,
            ]);
            if ($semester === 1) {
                $placement->materials()->attach($level->materialCatalogItems()->pluck('id'));
            }
            $plans[$semester]->update(['status' => 'validated', 'coverage_percent' => 100, 'validated_at' => now()]);
        }
        RppAnnualValidation::query()->updateOrCreate(
            ['academic_year_id' => $year->id, 'level_id' => $level->id],
            ['status' => 'validated', 'coverage_percent' => 100, 'validated_at' => now()]
        );

        $after = app(RppCompletionService::class)->report($year, $level);
        $this->assertTrue($after['complete']);
        $this->assertSame(100, $after['percent']);
        $this->assertSame(5, $after['completed_steps']);
        $this->assertTrue(collect($after['steps'])->every('complete'));
    }

    private function fixtureWithCatalog(): array
    {
        $level = Level::query()->create(['code' => 'PAUD', 'name' => 'PAUD', 'stage' => 'PAUD', 'sort_order' => 1]);
        $columns = collect([
            RppMatrixColumn::query()->create(['level_id' => $level->id, 'stable_key' => 'alim', 'aspect_label' => 'I. Alim-Faqih', 'subaspect_label' => 'A. Alim', 'label' => "Do'a-do'a Harian", 'sort_order' => 1, 'width' => 24, 'is_active' => true]),
            RppMatrixColumn::query()->create(['level_id' => $level->id, 'stable_key' => 'adab', 'aspect_label' => 'II. Akhlaqul Karimah', 'subaspect_label' => 'B. Adab', 'label' => 'Adab', 'sort_order' => 2, 'width' => 24, 'is_active' => true]),
        ]);
        foreach (range(1, 130) as $number) {
            RppMaterialCatalogItem::query()->create([
                'level_id' => $level->id,
                'rpp_matrix_column_id' => $number <= 70 ? $columns[0]->id : $columns[1]->id,
                'source_kind' => 'ggb', 'display_code' => 'PAUD '.str_pad((string) $number, 3, '0', STR_PAD_LEFT),
                'title' => 'Materi GGB '.$number, 'semester_scope' => 'both', 'source_semester_scope' => 'general',
                'semester_confirmed' => false, 'auto_include' => false, 'mapping_status' => 'mapped', 'sort_order' => $number,
            ]);
        }

        $year = AcademicYear::query()->create([
            'label' => '2026/2027', 'starts_on' => '2026-07-06', 'ends_on' => '2027-07-04', 'is_active' => true,
        ]);
        AcademicSemester::query()->create(['academic_year_id' => $year->id, 'semester' => 1, 'starts_on' => '2026-07-06', 'ends_on' => '2027-01-03']);
        AcademicSemester::query()->create(['academic_year_id' => $year->id, 'semester' => 2, 'starts_on' => '2027-01-04', 'ends_on' => '2027-07-04']);
        $weeks = collect();
        foreach ([1 => '2026-07-06', 2 => '2027-01-04'] as $semester => $date) {
            $weeks[$semester] = CalendarWeek::query()->create([
                'academic_year_id' => $year->id, 'week_number' => $semester, 'semester' => $semester,
                'starts_on' => $date, 'month_label' => $semester === 1 ? 'Juli' : 'Januari',
                'type' => 'effective', 'is_effective' => true,
            ]);
        }
        $plans = collect();
        foreach ([1, 2] as $semester) {
            $plans[$semester] = RppPlan::query()->create([
                'academic_year_id' => $year->id, 'level_id' => $level->id, 'semester' => $semester,
                'status' => 'draft', 'coverage_percent' => 0,
            ]);
        }

        return [$level, $year, $plans, $columns, $weeks];
    }
}
