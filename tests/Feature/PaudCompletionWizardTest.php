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
use App\Services\RppMaterialCatalogService;
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

    public function test_wizard_links_directly_to_the_consistent_column_confirmation_filter(): void
    {
        [$level, $year, $plans, $columns] = $this->fixtureWithCatalog();
        $user = User::factory()->create();
        $columns->each->update(['last_edited_by' => $user->id]);
        $suggested = $level->materialCatalogItems()->where('sort_order', 70)->firstOrFail();
        $suggested->update(['mapping_status' => 'needs_verification']);
        $missing = $level->materialCatalogItems()->where('sort_order', 71)->firstOrFail();
        $missing->update(['rpp_matrix_column_id' => null, 'mapping_status' => 'unmapped']);
        $mapped = $level->materialCatalogItems()->where('sort_order', 69)->firstOrFail();

        $report = app(RppCompletionService::class)->report($year, $level);
        $this->assertSame(2, $report['ggb']['needs_mapping']);
        $this->assertSame(
            $report['ggb']['needs_mapping'],
            $level->materialCatalogItems()->where('source_kind', 'ggb')->needsRppColumnConfirmation()->count(),
        );

        Livewire::actingAs($user)
            ->withQueryParams(['level' => $level->id, 'semester' => 1])
            ->test(ExportPreview::class)
            ->assertSee('Lihat 2 Materi')
            ->assertSee('ggb_status=mapping', false);

        $component = Livewire::actingAs($user)
            ->withQueryParams(['level' => $level->id, 'semester' => 1, 'detail' => 'ggb', 'ggb_status' => 'mapping'])
            ->test(ExportPreview::class)
            ->assertSet('detail', 'ggb')
            ->assertSet('ggbStatus', 'mapping')
            ->assertSee('Perlu Konfirmasi Kolom (2)')
            ->assertSee($suggested->title)
            ->assertSee($missing->title)
            ->assertSee('Perlu Konfirmasi Saran')
            ->assertSee('Belum Dipetakan')
            ->assertDontSee($mapped->display_code);

        $component
            ->set('selectedGgb', [(string) $suggested->id])
            ->set('ggbColumnId', $columns->first()->id)
            ->set('ggbReason', 'Konfirmasi saran kolom GGB')
            ->call('confirmGgb')
            ->assertSee('Perlu Konfirmasi Kolom (1)')
            ->assertDontSee($suggested->title);

        $component
            ->set('selectedGgb', [(string) $missing->id])
            ->set('ggbColumnId', $columns->last()->id)
            ->set('ggbReason', 'Pemetaan kolom GGB yang kosong')
            ->call('confirmGgb')
            ->assertSee('Perlu Konfirmasi Kolom (0)')
            ->assertSee('Semua kolom RPP sudah dikonfirmasi. Tidak ada materi yang tersisa pada filter ini.');

        $this->assertSame(0, app(RppCompletionService::class)->report($year, $level)['ggb']['needs_mapping']);
    }

    public function test_missing_filter_matches_annual_coverage_and_schedules_all_eighty_ready_materials(): void
    {
        [$level, $year, $plans, $columns, $weeks] = $this->fixtureWithCatalog();
        $user = User::factory()->create();
        $columns->each->update(['last_edited_by' => $user->id]);
        app(RppAnnualGgbService::class)->confirmGeneralBalanced($level, 'Pembagian GGB untuk uji cakupan', true, $user->id);

        $coveredMaterials = $level->materialCatalogItems()->orderBy('sort_order')->limit(50)->get();
        $coveredPlacement = RppWeekItem::query()->create([
            'rpp_plan_id' => $plans[1]->id,
            'calendar_week_id' => $weeks[1]->id,
            'rpp_matrix_column_id' => $columns->first()->id,
            'source_fingerprint' => 'test:annual-coverage',
            'occurrence_no' => 1,
            'strand' => 'Cakupan GGB',
            'content' => 'Materi GGB yang sudah masuk',
            'source' => 'manual',
            'is_locked' => true,
            'position' => 1,
        ]);
        $coveredPlacement->materials()->attach($coveredMaterials->pluck('id'));

        $otherYear = AcademicYear::query()->create([
            'label' => '2025/2026', 'starts_on' => '2025-07-07', 'ends_on' => '2026-07-05', 'is_active' => false,
        ]);
        $otherWeek = CalendarWeek::query()->create([
            'academic_year_id' => $otherYear->id, 'week_number' => 1, 'semester' => 1,
            'starts_on' => '2025-07-07', 'month_label' => 'Juli', 'type' => 'effective', 'is_effective' => true,
        ]);
        $otherPlan = RppPlan::query()->create([
            'academic_year_id' => $otherYear->id, 'level_id' => $level->id, 'semester' => 1,
            'status' => 'draft', 'coverage_percent' => 0,
        ]);
        $otherPlacement = RppWeekItem::query()->create([
            'rpp_plan_id' => $otherPlan->id,
            'calendar_week_id' => $otherWeek->id,
            'rpp_matrix_column_id' => $columns->last()->id,
            'source_fingerprint' => 'test:other-year',
            'occurrence_no' => 1,
            'strand' => 'Tahun lain',
            'content' => 'Tidak memenuhi cakupan tahun aktif',
            'source' => 'manual',
            'is_locked' => true,
            'position' => 1,
        ]);
        $otherPlacement->materials()->attach($level->materialCatalogItems()->orderBy('sort_order')->skip(50)->firstOrFail()->id);

        $catalog = app(RppMaterialCatalogService::class);
        $counts = $catalog->ggbStatusCounts($plans[1]);
        $this->assertSame([
            'all' => 130, 'used' => 50, 'missing' => 80, 'ready' => 80,
            'semester' => 0, 'mapping' => 0, 'conflict' => 0,
        ], $counts);
        $this->assertSame(80, $catalog->coverage($plans[1])['missing']);
        $this->assertSame(80, app(RppCompletionService::class)->report($year, $level)['ggb']['coverage']['missing']);

        Livewire::actingAs($user)
            ->withQueryParams(['level' => $level->id, 'semester' => 1])
            ->test(ExportPreview::class)
            ->assertSee('Lihat 80 Belum Masuk')
            ->assertSee('ggb_status=missing', false);

        $missingMaterial = $level->materialCatalogItems()->orderBy('sort_order')->skip(50)->firstOrFail();
        $component = Livewire::actingAs($user)
            ->withQueryParams(['level' => $level->id, 'semester' => 1, 'detail' => 'ggb', 'ggb_status' => 'missing'])
            ->test(ExportPreview::class)
            ->assertSet('ggbStatus', 'missing')
            ->assertSee('Semua Materi (130)')
            ->assertSee('Sudah Masuk RPP (50)')
            ->assertSee('Belum Masuk RPP (80)')
            ->assertSee('Siap Dijadwalkan (80)')
            ->assertSee('Perlu Semester (0)')
            ->assertSee('Perlu Konfirmasi Kolom (0)')
            ->assertSee('Konflik Ganda (0)')
            ->assertSee($missingMaterial->title)
            ->assertViewHas('ggbItems', fn ($items) => $items->contains('id', $missingMaterial->id)
                && ! $items->contains('id', $coveredMaterials->first()->id))
            ->assertSee('80 materi GGB belum masuk RPP Semester 1 atau 2.');

        $component
            ->set('ggbReason', 'Lengkapi delapan puluh materi GGB')
            ->call('completeAnnualGgb')
            ->assertSee('Belum Masuk RPP (0)')
            ->assertSee('Seluruh materi GGB sudah masuk RPP Semester 1 atau 2.');

        $this->assertSame(0, $catalog->coverage($plans[1]->fresh())['missing']);
    }

    public function test_wizard_routes_calendar_syllabus_and_target_blockers_to_their_exact_panels(): void
    {
        [$level, $year, $plans, $columns] = $this->fixtureWithCatalog();
        $user = User::factory()->create();
        $columns->each->update(['last_edited_by' => $user->id]);
        $document = SourceDocument::query()->create([
            'level_id' => $level->id, 'source_key' => 'silabus:paud:diagnostic', 'type' => 'silabus',
            'title' => 'Silabus PAUD Diagnostik', 'path' => 'silabus-paud.pdf', 'sha256' => str_repeat('d', 64), 'page_count' => 2,
        ]);
        SyllabusItem::query()->create([
            'level_id' => $level->id, 'source_document_id' => $document->id, 'source_key' => 'silabus:paud:diagnostic:tilawati',
            'stable_code' => 'PAUD / TILAWATI / DIAG', 'category' => 'Tilawati', 'title' => 'Tilawati PAUD',
            'description' => 'Target diagnostik', 'allocation_text' => 'Setiap minggu', 'recommended_sessions' => 1,
            'schedule_pattern' => 'weekly', 'schedule_pattern_source' => 'auto', 'needs_allocation' => false,
            'is_duplicate' => false, 'source_page' => 1, 'sort_order' => 1, 'group_number' => 1,
            'source_semester' => '1', 'semester_scope' => '1',
        ]);

        $report = app(RppCompletionService::class)->report($year, $level);
        $semesterOne = collect($report['steps'])->firstWhere('key', 'semester_1');
        $this->assertSame(1, $semesterOne['diagnostics']['syllabus_missing']);
        $this->assertSame(1, $semesterOne['diagnostics']['target_issue_count']);
        $this->assertFalse($semesterOne['diagnostics']['can_validate']);

        Livewire::actingAs($user)
            ->withQueryParams(['level' => $level->id, 'semester' => 1])
            ->test(ExportPreview::class)
            ->assertSee('detail=calendar', false)
            ->assertSee('Lihat 1 Silabus')
            ->assertSee('detail=unplanned', false)
            ->assertSee('Perbaiki 1 Target')
            ->assertSee('focus=targets', false);

        Livewire::actingAs($user)
            ->withQueryParams(['level' => $level->id, 'semester' => 1, 'focus' => 'targets'])
            ->test(ExportPreview::class)
            ->assertSet('focus', 'targets')
            ->assertSee('id="target-editor"', false)
            ->assertSee('open', false);
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
        }

        $readyForValidation = app(RppCompletionService::class)->report($year, $level);
        foreach ([1, 2] as $semester) {
            $step = collect($readyForValidation['steps'])->firstWhere('key', "semester_{$semester}");
            $this->assertTrue($step['diagnostics']['can_validate']);
            $this->assertTrue($step['diagnostics']['validation_pending']);
            $this->assertSame(0, $step['diagnostics']['syllabus_missing']);
            $this->assertSame(0, $step['diagnostics']['target_issue_count']);
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
