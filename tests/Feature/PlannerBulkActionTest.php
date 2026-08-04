<?php

namespace Tests\Feature;

use App\Livewire\Planner;
use App\Models\AcademicYear;
use App\Models\CalendarWeek;
use App\Models\Level;
use App\Models\RppPlan;
use App\Models\RppWeekItem;
use App\Models\SourceDocument;
use App\Models\SyllabusItem;
use App\Models\User;
use App\Services\RppBulkActionService;
use App\Services\RppPlanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class PlannerBulkActionTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Level $level;

    private RppPlan $plan;

    private CalendarWeek $weekOne;

    private CalendarWeek $weekTwo;

    private CalendarWeek $holiday;

    private SyllabusItem $scheduled;

    private SyllabusItem $ready;

    private SyllabusItem $needsAllocation;

    private RppWeekItem $placement;

    private RppWeekItem $foreignPlacement;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create();
        $year = AcademicYear::query()->create(['label' => '2026/2027', 'starts_on' => '2026-07-06', 'ends_on' => '2027-07-04', 'is_active' => true]);
        $this->weekOne = $this->week($year, 1, true);
        $this->weekTwo = $this->week($year, 2, true);
        $this->holiday = $this->week($year, 3, false);

        $this->level = Level::query()->create(['code' => 'UJI', 'name' => 'Kelas Uji', 'stage' => 'Uji', 'sort_order' => 1]);
        $document = $this->document($this->level, 'uji');
        $this->scheduled = $this->syllabus($this->level, $document, 's1', 'Materi terjadwal', false, false, 1);
        $this->ready = $this->syllabus($this->level, $document, 's2', 'Materi siap', false, false, 2);
        $this->needsAllocation = $this->syllabus($this->level, $document, 's3', 'Materi perlu alokasi', true, false, 3);
        $this->syllabus($this->level, $document, 's4', 'Materi duplikat', false, true, 4);
        $this->plan = RppPlan::query()->create([
            'academic_year_id' => $year->id, 'level_id' => $this->level->id,
            'status' => 'validated', 'validated_at' => now(), 'coverage_percent' => 33.33,
        ]);
        $this->placement = RppWeekItem::query()->create([
            'rpp_plan_id' => $this->plan->id, 'calendar_week_id' => $this->weekOne->id,
            'syllabus_item_id' => $this->scheduled->id, 'strand' => 'Faqih', 'content' => $this->scheduled->title,
            'source_fingerprint' => 'syllabus:'.$this->scheduled->id, 'occurrence_no' => 1,
            'source' => 'auto', 'is_locked' => false, 'position' => 1,
        ]);

        $foreignLevel = Level::query()->create(['code' => 'ASING', 'name' => 'Kelas Asing', 'stage' => 'Uji', 'sort_order' => 2]);
        $foreignDocument = $this->document($foreignLevel, 'asing');
        $foreignSyllabus = $this->syllabus($foreignLevel, $foreignDocument, 'asing-s1', 'Materi asing', false, false, 1);
        $foreignPlan = RppPlan::query()->create(['academic_year_id' => $year->id, 'level_id' => $foreignLevel->id]);
        $this->foreignPlacement = RppWeekItem::query()->create([
            'rpp_plan_id' => $foreignPlan->id, 'calendar_week_id' => $this->weekOne->id,
            'syllabus_item_id' => $foreignSyllabus->id, 'strand' => 'Faqih', 'content' => 'Materi asing',
            'source_fingerprint' => 'syllabus:'.$foreignSyllabus->id, 'occurrence_no' => 1,
            'source' => 'auto', 'is_locked' => false, 'position' => 1,
        ]);
    }

    public function test_metric_links_open_filtered_detail_and_only_select_schedulable_visible_items(): void
    {
        Livewire::actingAs($this->admin);
        $component = Livewire::withQueryParams(['detail' => 'unplanned'])
            ->test(Planner::class, ['level' => $this->level])
            ->assertSet('detail', 'unplanned')
            ->assertSee('Materi belum dijadwalkan')
            ->assertSee('Materi siap')
            ->assertSee('Materi perlu alokasi')
            ->assertSee('Lihat Preview RPP')
            ->assertSee('ekspor?level='.$this->level->id.'&amp;semester=1', false)
            ->assertDontSee('S4')
            ->assertSee(route('planner.show', ['level' => $this->level, 'detail' => 'allocation']).'#planner-detail', false);

        $component->call('selectVisibleSyllabus', [$this->ready->id, $this->needsAllocation->id, $this->foreignPlacement->syllabus_item_id])
            ->assertSet('selectedSyllabus', [(string) $this->ready->id]);
    }

    public function test_bulk_move_lock_and_unlock_are_scoped_and_invalidate_validation(): void
    {
        Livewire::actingAs($this->admin)->test(Planner::class, ['level' => $this->level])
            ->set('selectedPlacements', [(string) $this->placement->id])
            ->set('bulkWeekId', $this->weekTwo->id)
            ->set('bulkReason', 'Penyesuaian minggu hasil rapat')
            ->call('applyPlacementBulk', 'move')
            ->assertSet('errorMessage', '')
            ->assertSet('selectedPlacements', []);

        $this->placement->refresh();
        $this->assertSame($this->weekTwo->id, $this->placement->calendar_week_id);
        $this->assertSame('manual', $this->placement->source);
        $this->assertTrue($this->placement->is_locked);
        $this->assertSame(1, $this->placement->lock_version);
        $this->assertSame('draft', $this->plan->fresh()->status);
        $this->assertNull($this->plan->fresh()->validated_at);

        Livewire::actingAs($this->admin)->test(Planner::class, ['level' => $this->level])
            ->set('selectedPlacements', [(string) $this->placement->id])
            ->set('bulkReason', 'Buka kunci untuk koreksi lanjutan')
            ->call('applyPlacementBulk', 'unlock');

        $this->assertFalse($this->placement->fresh()->is_locked);
        $this->assertSame(2, $this->placement->fresh()->lock_version);
        $this->assertDatabaseHas('activity_logs', ['action' => 'rpp.bulk_unlock']);
    }

    public function test_bulk_schedule_creates_manual_locked_item_and_generate_preserves_it(): void
    {
        Livewire::actingAs($this->admin)->test(Planner::class, ['level' => $this->level])
            ->set('selectedSyllabus', [(string) $this->ready->id])
            ->set('bulkWeekId', $this->weekTwo->id)
            ->set('bulkReason', 'Jadwalkan materi siap secara manual')
            ->call('scheduleSelected')
            ->assertSet('errorMessage', '')
            ->assertSet('selectedSyllabus', []);

        $created = RppWeekItem::query()->where('rpp_plan_id', $this->plan->id)->where('syllabus_item_id', $this->ready->id)->firstOrFail();
        $this->assertSame($this->weekTwo->id, $created->calendar_week_id);
        $this->assertSame('manual', $created->source);
        $this->assertTrue($created->is_locked);
        $this->assertSame(1, $created->lock_version);

        app(RppPlanner::class)->generate($this->plan);
        $this->assertDatabaseHas('rpp_week_items', ['id' => $created->id, 'calendar_week_id' => $this->weekTwo->id, 'is_locked' => true]);
    }

    public function test_invalid_syllabus_selection_and_holiday_week_roll_back_entire_schedule(): void
    {
        Livewire::actingAs($this->admin)->test(Planner::class, ['level' => $this->level])
            ->set('selectedSyllabus', [(string) $this->ready->id, (string) $this->needsAllocation->id])
            ->set('bulkWeekId', $this->weekTwo->id)
            ->set('bulkReason', 'Percobaan bulk yang harus ditolak')
            ->call('scheduleSelected')
            ->assertSet('notice', '')
            ->assertSet('errorMessage', 'Sebagian materi duplikat, perlu alokasi, sudah dijadwalkan, atau bukan milik jenjang ini. Tidak ada perubahan diterapkan.');

        $this->assertDatabaseMissing('rpp_week_items', ['rpp_plan_id' => $this->plan->id, 'syllabus_item_id' => $this->ready->id]);

        Livewire::actingAs($this->admin)->test(Planner::class, ['level' => $this->level])
            ->set('selectedSyllabus', [(string) $this->ready->id])
            ->set('bulkWeekId', $this->holiday->id)
            ->set('bulkReason', 'Percobaan ke minggu libur')
            ->call('scheduleSelected')
            ->assertSet('errorMessage', 'Minggu tujuan tidak efektif atau bukan bagian dari tahun ajaran ini.');

        $this->assertDatabaseMissing('rpp_week_items', ['rpp_plan_id' => $this->plan->id, 'syllabus_item_id' => $this->ready->id]);
    }

    public function test_foreign_placement_rejects_whole_bulk_action(): void
    {
        $service = app(RppBulkActionService::class);
        try {
            $service->updatePlacements(
                $this->plan,
                [$this->placement->id, $this->foreignPlacement->id],
                'lock',
                null,
                'Pilihan lintas RPP harus ditolak',
                $this->admin->id,
            );
            $this->fail('Pilihan asing seharusnya membatalkan transaksi.');
        } catch (ValidationException $exception) {
            $this->assertSame('Pilihan tidak valid atau berasal dari RPP lain. Muat ulang halaman.', $exception->errors()['selection'][0]);
        }

        $this->assertFalse($this->placement->fresh()->is_locked);
        $this->assertFalse($this->foreignPlacement->fresh()->is_locked);
        $this->assertDatabaseCount('activity_logs', 0);
    }

    public function test_tentative_material_can_be_scheduled_automatically_without_moving_other_items(): void
    {
        $this->ready->update(['allocation_text' => 'Tentatif (Sabtu/Minggu)', 'recommended_sessions' => 1, 'needs_allocation' => false]);
        $originalWeek = $this->placement->calendar_week_id;

        Livewire::actingAs($this->admin)
            ->withQueryParams(['detail' => 'unplanned'])
            ->test(Planner::class, ['level' => $this->level])
            ->assertSee('Tentatif adalah catatan waktu dari sumber dan tidak menghalangi penjadwalan.')
            ->assertSee('Jadwalkan Otomatis')
            ->call('scheduleAutomatically', $this->ready->id)
            ->assertSet('errorMessage', '')
            ->assertSee('Materi dijadwalkan otomatis ke Minggu 2')
            ->assertDontSee('Tentatif adalah catatan waktu dari sumber dan tidak menghalangi penjadwalan.');

        $created = RppWeekItem::query()
            ->where('rpp_plan_id', $this->plan->id)
            ->where('syllabus_item_id', $this->ready->id)
            ->firstOrFail();

        $this->assertSame($this->weekTwo->id, $created->calendar_week_id);
        $this->assertSame('auto', $created->source);
        $this->assertFalse($created->is_locked);
        $this->assertSame($originalWeek, $this->placement->fresh()->calendar_week_id);
        $this->assertSame('auto', $this->placement->fresh()->source);
        $this->assertSame('draft', $this->plan->fresh()->status);
        $this->assertNull($this->plan->fresh()->validated_at);
        $this->assertEquals(66.67, $this->plan->fresh()->coverage_percent);
        $this->assertDatabaseHas('activity_logs', ['action' => 'rpp.item_scheduled_auto']);
    }

    public function test_manual_inline_schedule_requires_effective_week_and_reason_then_locks_material(): void
    {
        Livewire::actingAs($this->admin)
            ->withQueryParams(['detail' => 'unplanned'])
            ->test(Planner::class, ['level' => $this->level])
            ->call('openManualScheduling', $this->ready->id)
            ->assertSet('manualSyllabusId', $this->ready->id)
            ->assertSee('Jadwalkan &amp; Kunci', false)
            ->set('manualWeekId', $this->weekTwo->id)
            ->set('manualReason', 'Dijadwalkan sesuai hasil rapat')
            ->call('scheduleManual', $this->ready->id)
            ->assertSet('errorMessage', '')
            ->assertSet('manualSyllabusId', null)
            ->assertSet('manualWeekId', null)
            ->assertSee('Materi dijadwalkan manual ke Minggu 2 dan dikunci.');

        $created = RppWeekItem::query()
            ->where('rpp_plan_id', $this->plan->id)
            ->where('syllabus_item_id', $this->ready->id)
            ->firstOrFail();
        $this->assertSame($this->weekTwo->id, $created->calendar_week_id);
        $this->assertSame('manual', $created->source);
        $this->assertTrue($created->is_locked);
        $this->assertDatabaseHas('activity_logs', ['action' => 'rpp.item_scheduled_manual']);
    }

    public function test_manual_inline_schedule_rejects_short_reason_and_holiday_week(): void
    {
        Livewire::actingAs($this->admin)
            ->test(Planner::class, ['level' => $this->level])
            ->call('openManualScheduling', $this->ready->id)
            ->set('manualWeekId', $this->weekTwo->id)
            ->set('manualReason', 'x')
            ->call('scheduleManual', $this->ready->id)
            ->assertSet('errorMessage', 'Alasan tindakan minimal 5 karakter.');

        Livewire::actingAs($this->admin)
            ->test(Planner::class, ['level' => $this->level])
            ->call('openManualScheduling', $this->ready->id)
            ->set('manualWeekId', $this->holiday->id)
            ->set('manualReason', 'Mencoba minggu libur')
            ->call('scheduleManual', $this->ready->id)
            ->assertSet('errorMessage', 'Minggu tujuan tidak efektif atau bukan bagian dari tahun ajaran ini.');

        $this->assertDatabaseMissing('rpp_week_items', [
            'rpp_plan_id' => $this->plan->id,
            'syllabus_item_id' => $this->ready->id,
        ]);
    }

    public function test_automatic_single_schedule_rejects_ineligible_foreign_and_already_scheduled_materials(): void
    {
        $component = Livewire::actingAs($this->admin)->test(Planner::class, ['level' => $this->level]);

        $component->call('scheduleAutomatically', $this->needsAllocation->id)
            ->assertSet('errorMessage', 'Lengkapi alokasi dan jumlah pertemuan minimal 1 sebelum menjadwalkan materi.');
        $component->call('scheduleAutomatically', $this->foreignPlacement->syllabus_item_id)
            ->assertSet('errorMessage', 'Materi bukan milik jenjang RPP ini.');
        $component->call('scheduleAutomatically', $this->scheduled->id)
            ->assertSet('errorMessage', 'Materi ini sudah dijadwalkan. Muat ulang halaman untuk melihat jadwal terbaru.');

        $duplicate = $this->level->syllabusItems()->where('is_duplicate', true)->firstOrFail();
        $component->call('scheduleAutomatically', $duplicate->id)
            ->assertSet('errorMessage', 'Materi duplikat tidak dapat dijadwalkan.');

        $this->assertDatabaseMissing('rpp_week_items', [
            'rpp_plan_id' => $this->plan->id,
            'syllabus_item_id' => $this->ready->id,
        ]);
    }

    public function test_automatic_single_schedule_rejects_when_no_effective_week_exists(): void
    {
        CalendarWeek::query()->where('academic_year_id', $this->plan->academic_year_id)->update(['is_effective' => false, 'type' => 'holiday']);

        Livewire::actingAs($this->admin)->test(Planner::class, ['level' => $this->level])
            ->call('scheduleAutomatically', $this->ready->id)
            ->assertSet('errorMessage', 'Tidak ada minggu efektif yang tersedia pada tahun ajaran ini.');

        $this->assertDatabaseMissing('rpp_week_items', [
            'rpp_plan_id' => $this->plan->id,
            'syllabus_item_id' => $this->ready->id,
        ]);
    }

    private function week(AcademicYear $year, int $number, bool $effective): CalendarWeek
    {
        return CalendarWeek::query()->create([
            'academic_year_id' => $year->id,
            'week_number' => $number,
            'starts_on' => '2026-07-'.match ($number) {
                1 => '06', 2 => '13', default => '20'
            },
            'month_label' => 'Juli',
            'type' => $effective ? 'effective' : 'holiday',
            'is_effective' => $effective,
        ]);
    }

    private function document(Level $level, string $key): SourceDocument
    {
        return SourceDocument::query()->create([
            'level_id' => $level->id, 'source_key' => 'silabus:'.$key, 'type' => 'silabus',
            'title' => 'Silabus '.$key, 'path' => $key.'.pdf', 'sha256' => str_repeat('a', 64), 'page_count' => 2,
        ]);
    }

    private function syllabus(Level $level, SourceDocument $document, string $key, string $title, bool $needsAllocation, bool $duplicate, int $order): SyllabusItem
    {
        return SyllabusItem::query()->create([
            'level_id' => $level->id, 'source_document_id' => $document->id,
            'source_key' => $key, 'stable_code' => strtoupper($key), 'category' => 'Faqih',
            'title' => $title, 'description' => $title,
            'allocation_text' => $needsAllocation ? null : '1 pertemuan',
            'recommended_sessions' => $needsAllocation ? null : 1,
            'needs_allocation' => $needsAllocation, 'is_duplicate' => $duplicate,
            'source_page' => 1, 'sort_order' => $order, 'group_number' => 1,
        ]);
    }
}
