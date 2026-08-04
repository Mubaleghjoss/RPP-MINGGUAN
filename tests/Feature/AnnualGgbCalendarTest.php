<?php

namespace Tests\Feature;

use App\Livewire\ExportPreview;
use App\Models\AcademicSemester;
use App\Models\AcademicYear;
use App\Models\CalendarEvent;
use App\Models\CalendarWeek;
use App\Models\GgbItem;
use App\Models\GgbSyllabusLink;
use App\Models\Level;
use App\Models\RppAnnualValidation;
use App\Models\RppMatrixColumn;
use App\Models\RppMatrixMapping;
use App\Models\RppPlan;
use App\Models\RppWeekItem;
use App\Models\SourceDocument;
use App\Models\SyllabusItem;
use App\Models\User;
use App\Services\AcademicCalendarService;
use App\Services\RppAnnualGgbService;
use App\Services\RppMaterialCatalogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class AnnualGgbCalendarTest extends TestCase
{
    use RefreshDatabase;

    public function test_annual_coverage_schedules_ready_items_and_waits_for_general_confirmation(): void
    {
        [$level, $year, $plans] = $this->fixture();
        $catalog = app(RppMaterialCatalogService::class);
        $catalog->syncLevel($level);
        $items = $level->materialCatalogItems()->where('source_kind', 'ggb')->get();
        $explicit = $items->firstWhere('source_semester_scope', '1');
        $general = $items->firstWhere('source_semester_scope', 'general');
        $this->assertNotNull($explicit);
        $this->assertNotNull($general);
        $user = User::factory()->create();
        $service = app(RppAnnualGgbService::class);

        $first = $service->enableReadyAndSchedule($year, $level, 'Masukkan materi yang sudah jelas', $user->id);
        $this->assertSame(50.0, $first['coverage']['percent']);
        $this->assertDatabaseHas('rpp_week_items', ['source_fingerprint' => 'catalog:'.$explicit->id, 'source' => 'ggb_auto', 'is_locked' => false, 'content' => $explicit->title]);
        $this->assertDatabaseMissing('rpp_week_items', ['source_fingerprint' => 'catalog:'.$general->id]);

        $service->confirm($level, [$general->id], 2, $general->rpp_matrix_column_id, 'Konfirmasi semester dan kolom', $user->id);
        $second = $service->enableReadyAndSchedule($year, $level, 'Lengkapi cakupan tahunan', $user->id);
        $this->assertSame(100.0, $second['coverage']['percent']);
        $this->assertDatabaseHas('rpp_week_items', ['rpp_plan_id' => $plans[2]->id, 'source_fingerprint' => 'catalog:'.$general->id, 'source' => 'ggb_auto', 'is_locked' => false, 'content' => $general->title]);
        $this->assertTrue($service->validateAnnual($year, $level, $user->id));
        $this->assertDatabaseHas('rpp_annual_validations', ['academic_year_id' => $year->id, 'level_id' => $level->id, 'status' => 'validated']);

        $service->enableReadyAndSchedule($year, $level, 'Uji penjadwalan idempoten', $user->id);
        $this->assertSame(2, RppWeekItem::query()->where('source', 'ggb_auto')->count());

        Livewire::actingAs($user)->test(ExportPreview::class)
            ->set('levelId', $level->id)->set('detail', 'ggb')
            ->assertSee('Cakupan GGB 1 Tahun')->assertSee('Lengkapi GGB 1 Tahun')->assertSee($general->title);
    }

    public function test_calendar_range_blocks_partial_week_and_moves_locked_material_after_confirmation(): void
    {
        [$level, $year, $plans, $weeks, $column, $syllabus] = $this->fixture();
        $locked = $this->placement($plans[1], $weeks[0], $column, $syllabus, true, 'Materi minggu pertama');
        $later = $this->placement($plans[1], $weeks[1], $column, $syllabus, false, 'Materi minggu kedua', 2);
        $calendar = app(AcademicCalendarService::class);
        $user = User::factory()->create();
        $payload = [
            'type' => 'exam', 'title' => 'Ujian serentak', 'details' => 'Seluruh kegiatan belajar dihentikan.',
            'starts_on' => '2026-07-08', 'ends_on' => '2026-07-09', 'applies_to_all' => false,
            'level_ids' => [$level->id], 'confirm_impact' => true,
        ];
        $preview = $calendar->previewEvent($year, $payload);
        $this->assertSame(1, $preview['weeks']->count());
        $this->assertSame(1, $preview['locked_count']);
        $event = $calendar->saveEvent($year, $payload, $user->id);

        $this->assertFalse($calendar->isEffective($plans[1], $weeks[0]));
        $this->assertSame($weeks[1]->id, $locked->fresh()->calendar_week_id);
        $this->assertSame($weeks[2]->id, $later->fresh()->calendar_week_id);
        $this->assertTrue($locked->fresh()->is_locked);
        $this->assertDatabaseHas('revision_items', ['revisable_type' => 'calendar_event', 'revisable_id' => $event->id]);

        $calendar->deleteEvent($event, $user->id);
        $this->assertTrue($calendar->isEffective($plans[1], $weeks[0]));
        $this->assertSame($weeks[1]->id, $locked->fresh()->calendar_week_id, 'Menghapus acara tidak menarik materi mundur.');
    }

    public function test_calendar_range_compresses_materials_when_fewer_effective_weeks_remain(): void
    {
        [$level, $year, $plans, $weeks, $column, $syllabus] = $this->fixture();
        foreach ($weeks->take(4) as $index => $week) {
            $this->placement($plans[1], $week, $column, $syllabus, $index === 0, 'Materi '.($index + 1), $index + 1, 'syllabus:shared');
        }
        $calendar = app(AcademicCalendarService::class);
        $payload = [
            'type' => 'holiday', 'title' => 'Libur awal semester', 'details' => 'Uji kapasitas.',
            'starts_on' => '2026-07-06', 'ends_on' => '2026-07-12', 'applies_to_all' => true,
            'level_ids' => [], 'confirm_impact' => true,
        ];

        $calendar->saveEvent($year, $payload, null);

        $this->assertSame(1, CalendarEvent::query()->count());
        $this->assertSame($weeks[1]->id, $plans[1]->items()->orderBy('position')->first()->calendar_week_id);
        $this->assertSame(2, $plans[1]->items()->where('calendar_week_id', $weeks[3]->id)->count());
        $this->assertSame([1, 2], $plans[1]->items()->where('calendar_week_id', $weeks[3]->id)->orderBy('occurrence_no')->pluck('occurrence_no')->all());
        $this->assertSame(4, $plans[1]->items()->count());
    }

    public function test_calendar_range_is_rejected_atomically_only_when_no_effective_week_remains(): void
    {
        [$level, $year, $plans, $weeks, $column, $syllabus] = $this->fixture();
        $this->placement($plans[1], $weeks[0], $column, $syllabus, true, 'Materi pertama');
        $calendar = app(AcademicCalendarService::class);
        $payload = [
            'type' => 'holiday', 'title' => 'Libur satu semester', 'details' => 'Tidak menyisakan minggu efektif.',
            'starts_on' => '2026-07-06', 'ends_on' => '2026-08-02', 'applies_to_all' => true,
            'level_ids' => [], 'confirm_impact' => true,
        ];

        try {
            $calendar->saveEvent($year, $payload, null);
            $this->fail('Rentang seharusnya ditolak ketika tidak ada minggu efektif.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('tidak cukup', collect($exception->errors())->flatten()->first());
        }
        $this->assertSame(0, CalendarEvent::query()->count());
        $this->assertSame($weeks[0]->id, $plans[1]->items()->first()->calendar_week_id);
    }

    public function test_calendar_reflow_moves_repeated_material_from_latest_week_first_without_unique_collision(): void
    {
        [$level, $year, $plans, $weeks, $column, $syllabus] = $this->fixture();
        $first = $this->placement($plans[1], $weeks[0], $column, $syllabus, true, 'Materi berulang', 1, 'syllabus:'.$syllabus->id);
        $second = $this->placement($plans[1], $weeks[1], $column, $syllabus, false, 'Materi berulang', 2, 'syllabus:'.$syllabus->id);
        $calendar = app(AcademicCalendarService::class);
        $user = User::factory()->create();

        $event = $calendar->saveEvent($year, [
            'type' => 'holiday', 'title' => 'Libur minggu pertama', 'details' => 'Menguji materi berulang.',
            'starts_on' => '2026-07-06', 'ends_on' => '2026-07-12', 'applies_to_all' => false,
            'level_ids' => [$level->id], 'confirm_impact' => true,
        ], $user->id);

        $this->assertSame($weeks[1]->id, $first->fresh()->calendar_week_id);
        $this->assertSame($weeks[2]->id, $second->fresh()->calendar_week_id);
        $this->assertTrue($first->fresh()->is_locked);
        $this->assertDatabaseHas('calendar_events', ['id' => $event->id, 'title' => 'Libur minggu pertama']);
    }

    public function test_calendar_change_preserves_validated_annual_ggb_when_coverage_stays_complete(): void
    {
        [$level, $year, $plans, $weeks, $column, $syllabus] = $this->fixture();
        $catalog = app(RppMaterialCatalogService::class);
        $catalog->syncLevel($level);
        $placement = $this->placement($plans[1], $weeks[0], $column, $syllabus, true, 'Materi tercakup');
        $placement->materials()->sync($level->materialCatalogItems()->where('source_kind', 'ggb')->where('is_schedulable', true)->pluck('id'));
        RppAnnualValidation::query()->create([
            'academic_year_id' => $year->id, 'level_id' => $level->id,
            'status' => 'validated', 'coverage_percent' => 100,
            'validated_at' => now(), 'validated_by' => null,
        ]);

        app(AcademicCalendarService::class)->saveEvent($year, [
            'type' => 'exam', 'title' => 'Evaluasi awal', 'details' => 'Materi hanya bergeser.',
            'starts_on' => '2026-07-06', 'ends_on' => '2026-07-12', 'applies_to_all' => false,
            'level_ids' => [$level->id], 'confirm_impact' => true,
        ], null);

        $validation = RppAnnualValidation::query()->where('academic_year_id', $year->id)->where('level_id', $level->id)->firstOrFail();
        $this->assertSame('validated', $validation->status);
        $this->assertSame('100.00', (string) $validation->coverage_percent);
        $this->assertSame('draft', $plans[1]->fresh()->status);
    }

    public function test_shortening_semester_reuses_week_identity_per_semester_and_never_moves_semester_two_material(): void
    {
        [$level, $year, $plans, $weeks, $column, $syllabus] = $this->fixture();
        foreach ($weeks->take(4) as $index => $week) {
            $this->placement($plans[1], $week, $column, $syllabus, $index === 0, 'Semester satu '.($index + 1), $index + 1);
        }
        $semesterTwo = $this->placement($plans[2], $weeks[4], $column, $syllabus, true, 'Jangkar Semester 2');
        foreach ([1, 2] as $semester) {
            $semesterWeeks = $weeks->where('semester', $semester);
            AcademicSemester::query()->create([
                'academic_year_id' => $year->id,
                'semester' => $semester,
                'starts_on' => $semesterWeeks->first()->starts_on,
                'ends_on' => $semesterWeeks->last()->starts_on->copy()->addDays(6),
            ]);
        }

        $result = app(AcademicCalendarService::class)->saveSemesterRanges($year, [
            'semester_1_start' => '2026-07-06', 'semester_1_end' => '2026-07-19',
            'semester_2_start' => '2026-08-03', 'semester_2_end' => '2026-08-30',
        ], null);

        $this->assertGreaterThan(0, $result['combined_groups']);
        $this->assertSame($plans[2]->id, $semesterTwo->fresh()->rpp_plan_id);
        $this->assertSame($weeks[4]->id, $semesterTwo->fresh()->calendar_week_id);
        $this->assertSame(2, $semesterTwo->fresh()->week->semester);
        $this->assertSame(2, $plans[1]->fresh()->academicYear->weeks()->where('semester', 1)->count());
        $this->assertSame(4, $plans[2]->fresh()->academicYear->weeks()->where('semester', 2)->count());
    }

    public function test_calendar_validation_dispatches_persistent_notification_with_cause_and_recovery(): void
    {
        [$level] = $this->fixture();
        $user = User::factory()->create();

        Livewire::actingAs($user)->withQueryParams(['level' => $level->id, 'semester' => 1])
            ->test(ExportPreview::class)
            ->set('calendarTitle', '')
            ->set('calendarStartsOn', '2026-07-06')
            ->set('calendarEndsOn', '2026-07-12')
            ->call('saveCalendarEvent')
            ->assertDispatched('app-notification', function (string $name, array $params): bool {
                $notification = $params['notification'] ?? [];

                return $notification['type'] === 'error'
                    && $notification['title'] === 'Rentang kalender tidak dapat disimpan'
                    && $notification['focus_field'] === 'calendarTitle'
                    && count($notification['details'] ?? []) >= 1
                    && count($notification['suggestions'] ?? []) >= 1;
            });
    }

    private function fixture(): array
    {
        $level = Level::query()->create(['code' => 'UJI', 'name' => 'Jenjang Uji', 'stage' => 'PAUD', 'sort_order' => 1]);
        $ggbDocument = SourceDocument::query()->create(['level_id' => $level->id, 'source_key' => 'ggb:uji', 'type' => 'ggb', 'title' => 'GGB Uji', 'path' => 'ggb-uji.pdf', 'sha256' => str_repeat('a', 64), 'page_count' => 2]);
        $syllabusDocument = SourceDocument::query()->create(['level_id' => $level->id, 'source_key' => 'silabus:uji', 'type' => 'silabus', 'title' => 'Silabus Uji', 'path' => 'silabus-uji.pdf', 'sha256' => str_repeat('b', 64), 'page_count' => 2]);
        $parent = $this->ggb($level, $ggbDocument, null, 1, 'subaspect', 'Adab');
        $explicit = $this->ggb($level, $ggbDocument, $parent, 2, 'topic', 'Mengucapkan salam');
        $this->ggb($level, $ggbDocument, $parent, 3, 'topic', 'Bersikap sopan');
        $syllabus = SyllabusItem::query()->create([
            'level_id' => $level->id, 'source_document_id' => $syllabusDocument->id, 'source_key' => 'silabus:uji:1',
            'stable_code' => 'UJI / ADAB / S001', 'category' => 'Praktik Adab', 'title' => 'Mengucapkan dan menjawab salam',
            'description' => 'Pembiasaan salam', 'allocation_text' => '1 pertemuan / minggu', 'recommended_sessions' => 1,
            'schedule_pattern' => 'weekly', 'schedule_pattern_source' => 'auto', 'needs_allocation' => false, 'is_duplicate' => false,
            'source_page' => 1, 'sort_order' => 1, 'group_number' => 1, 'source_semester' => '1', 'semester_scope' => '1',
        ]);
        GgbSyllabusLink::query()->create(['ggb_item_id' => $explicit->id, 'syllabus_item_id' => $syllabus->id, 'status' => 'sesuai', 'confidence' => 1]);
        $column = RppMatrixColumn::query()->create(['level_id' => $level->id, 'stable_key' => 'adab', 'aspect_label' => 'II. Akhlaqul Karimah', 'subaspect_label' => 'B. Adab', 'label' => 'Praktik Adab', 'sort_order' => 1, 'width' => 24, 'is_active' => true]);
        RppMatrixMapping::query()->create(['syllabus_item_id' => $syllabus->id, 'rpp_matrix_column_id' => $column->id]);
        $year = AcademicYear::query()->create(['label' => '2026/2027', 'starts_on' => '2026-07-06', 'ends_on' => '2026-08-30', 'is_active' => true]);
        $weeks = collect();
        foreach (range(1, 8) as $number) {
            $date = now()->setDate(2026, 7, 6)->startOfDay()->addWeeks($number - 1);
            $weeks->push(CalendarWeek::query()->create(['academic_year_id' => $year->id, 'week_number' => $number, 'semester' => $number <= 4 ? 1 : 2, 'starts_on' => $date->toDateString(), 'month_label' => $date->translatedFormat('F'), 'type' => 'effective', 'is_effective' => true]));
        }
        $plans = [];
        foreach ([1, 2] as $semester) {
            $plans[$semester] = RppPlan::query()->create(['academic_year_id' => $year->id, 'level_id' => $level->id, 'semester' => $semester, 'status' => 'draft']);
        }

        return [$level, $year, $plans, $weeks, $column, $syllabus];
    }

    private function ggb(Level $level, SourceDocument $document, ?GgbItem $parent, int $order, string $kind, string $title): GgbItem
    {
        return GgbItem::query()->create(['level_id' => $level->id, 'source_document_id' => $document->id, 'parent_id' => $parent?->id, 'source_key' => 'ggb:uji:'.$order, 'stable_code' => 'UJI / ADAB / '.str_pad((string) $order, 3, '0', STR_PAD_LEFT), 'kind' => $kind, 'aspect' => 'Akhlaqul Karimah', 'subaspect' => 'Adab', 'title' => $title, 'raw_text' => $title, 'source_page' => 1, 'sort_order' => $order]);
    }

    private function placement(RppPlan $plan, CalendarWeek $week, RppMatrixColumn $column, SyllabusItem $syllabus, bool $locked, string $content, int $position = 1, ?string $fingerprint = null): RppWeekItem
    {
        return RppWeekItem::query()->create(['rpp_plan_id' => $plan->id, 'calendar_week_id' => $week->id, 'syllabus_item_id' => $syllabus->id, 'source_fingerprint' => $fingerprint ?? 'test:'.$position, 'occurrence_no' => 1, 'rpp_matrix_column_id' => $column->id, 'strand' => $column->label, 'content' => $content, 'source' => $locked ? 'manual' : 'auto', 'is_locked' => $locked, 'position' => $position, 'lock_version' => 0]);
    }
}
