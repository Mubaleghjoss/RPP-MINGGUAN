<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\CalendarWeek;
use App\Models\GgbItem;
use App\Models\GgbSyllabusLink;
use App\Models\Level;
use App\Models\RppPlan;
use App\Models\SourceDocument;
use App\Models\SyllabusItem;
use App\Models\User;
use App\Services\RppMaterialCatalogService;
use App\Services\RppMaterialPlacementService;
use App\Services\RppPlanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class RppMaterialCatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_catalog_contains_leaf_ggb_and_unlinked_syllabus_with_stable_codes(): void
    {
        [$level] = $this->fixture();
        $catalog = app(RppMaterialCatalogService::class);
        $catalog->syncLevel($level);

        $items = $level->materialCatalogItems()->get();
        $this->assertSame(3, $items->count());
        $this->assertSame(2, $items->where('source_kind', 'ggb')->count());
        $this->assertSame(1, $items->where('source_kind', 'syllabus')->count());
        $this->assertSame(3, $items->pluck('display_code')->unique()->count());
        $this->assertTrue($items->every(fn ($item) => $item->rpp_matrix_column_id !== null));
        $codes = $items->pluck('display_code', 'id')->all();
        $supplemental = $items->firstWhere('source_kind', 'syllabus');
        $supplemental->syllabusItem->update(['title' => 'Adab menerima tamu']);

        $catalog->syncLevel($level->fresh());

        $this->assertSame($codes, $level->materialCatalogItems()->pluck('display_code', 'id')->all());
        $this->assertSame('Adab menerima tamu', $supplemental->fresh()->title);
        $columns = $level->matrixColumns()->where('is_active', true)->get();
        $tree = $catalog->headerTree($columns);
        $this->assertSame($columns->count(), $tree->sum('span'));
        $this->assertSame($columns->count(), $tree->flatMap(fn ($aspect) => $aspect['subaspects'])->sum('span'));

        $reordered = $catalog->headerTree(collect([
            (object) ['aspect_label' => 'A', 'subaspect_label' => 'Satu'],
            (object) ['aspect_label' => 'B', 'subaspect_label' => 'Dua'],
            (object) ['aspect_label' => 'A', 'subaspect_label' => 'Tiga'],
        ]));
        $this->assertSame(['A', 'B', 'A'], $reordered->pluck('label')->all());
        $this->assertSame(3, $reordered->sum('span'));

        $secondLeaf = $level->ggbItems()->whereDoesntHave('syllabusItems')->whereDoesntHave('children')->firstOrFail();
        GgbSyllabusLink::query()->create([
            'ggb_item_id' => $secondLeaf->id,
            'syllabus_item_id' => $supplemental->syllabus_item_id,
            'status' => 'sesuai',
            'confidence' => 1,
        ]);
        $catalog->syncLevel($level->fresh());
        $this->assertDatabaseMissing('rpp_material_catalog_items', ['id' => $supplemental->id]);
        $this->assertSame(2, $level->materialCatalogItems()->count());
    }

    public function test_picker_adds_new_and_repeated_material_atomically_and_planner_preserves_them(): void
    {
        [$level, $plan, $week, $linkedLeaf] = $this->fixture();
        $catalog = app(RppMaterialCatalogService::class);
        $catalog->syncLevel($level);
        $material = $level->materialCatalogItems()->where('ggb_item_id', $linkedLeaf->id)->firstOrFail();
        $user = User::factory()->create();
        $service = app(RppMaterialPlacementService::class);

        $service->addToCell($plan, $week->id, $material->rpp_matrix_column_id, [$material->id], 'Masukkan materi baru', $user->id);
        $service->addToCell($plan, $week->id, $material->rpp_matrix_column_id, [$material->id], 'Ulangi untuk penguatan', $user->id);

        $placements = $plan->items()->where('source_fingerprint', 'catalog:'.$material->id)->orderBy('occurrence_no')->get();
        $this->assertSame(2, $placements->count());
        $this->assertSame([1, 2], $placements->pluck('occurrence_no')->all());
        $this->assertSame(['materi_baru', 'penguatan'], $placements->pluck('progress_kind')->all());
        $this->assertTrue($placements->every(fn ($item) => $item->is_locked && $item->source === 'manual'));
        $this->assertNotNull($placements->first()->syllabus_item_id);
        $this->assertSame(2, $material->placements()->where('rpp_plan_id', $plan->id)->count());
        $this->assertDatabaseHas('activity_logs', ['action' => 'rpp.catalog_materials_added']);
        $this->assertSame(50.0, $catalog->coverage($plan)['percent']);

        $directGgb = $level->materialCatalogItems()->where('source_kind', 'ggb')
            ->whereNull('syllabus_item_id')->where('id', '!=', $material->id)->firstOrFail();
        $service->addToCell($plan, $week->id, $directGgb->rpp_matrix_column_id, [$directGgb->id], 'Masukkan GGB tanpa silabus', $user->id);
        $this->assertDatabaseHas('rpp_week_items', [
            'rpp_plan_id' => $plan->id,
            'source_fingerprint' => 'catalog:'.$directGgb->id,
            'syllabus_item_id' => null,
            'is_locked' => true,
        ]);
        $this->assertSame(100.0, $catalog->coverage($plan)['percent']);

        app(RppPlanner::class)->generate($plan->fresh());

        $this->assertSame(2, $plan->items()->where('source_fingerprint', 'catalog:'.$material->id)->count());
        $this->assertSame(1, $plan->items()->where('source_fingerprint', 'catalog:'.$directGgb->id)->count());
    }

    public function test_picker_rejects_non_effective_week_and_mixed_invalid_selection_atomically(): void
    {
        [$level, $plan, $week, $linkedLeaf] = $this->fixture();
        app(RppMaterialCatalogService::class)->syncLevel($level);
        $materials = $level->materialCatalogItems()->where('source_kind', 'ggb')->get();
        $valid = $materials->firstWhere('ggb_item_id', $linkedLeaf->id);
        $invalid = $materials->firstWhere('id', '!=', $valid->id);
        $service = app(RppMaterialPlacementService::class);

        $week->update(['is_effective' => false, 'type' => 'holiday']);
        try {
            $service->addToCell($plan, $week->id, $valid->rpp_matrix_column_id, [$valid->id], 'Uji minggu libur', null);
            $this->fail('Minggu non-efektif seharusnya ditolak.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('minggu efektif', collect($exception->errors())->flatten()->first());
        }
        $this->assertSame(0, $plan->items()->count());

        $week->update(['is_effective' => true, 'type' => 'effective']);
        $invalid->update(['rpp_matrix_column_id' => null, 'mapping_status' => 'unmapped']);
        try {
            $service->addToCell($plan, $week->id, $valid->rpp_matrix_column_id, [$valid->id, $invalid->id], 'Uji transaksi atomik', null);
            $this->fail('Pilihan campuran yang tidak valid seharusnya ditolak.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('belum dipetakan', collect($exception->errors())->flatten()->first());
        }
        $this->assertSame(0, $plan->items()->count());
    }

    private function fixture(): array
    {
        $level = Level::query()->create(['code' => 'UJI', 'name' => 'Jenjang Uji', 'stage' => 'PAUD', 'sort_order' => 1]);
        $ggbDocument = SourceDocument::query()->create([
            'level_id' => $level->id, 'source_key' => 'ggb:uji', 'type' => 'ggb', 'title' => 'GGB Uji',
            'path' => 'ggb-uji.pdf', 'sha256' => str_repeat('a', 64), 'page_count' => 2,
        ]);
        $syllabusDocument = SourceDocument::query()->create([
            'level_id' => $level->id, 'source_key' => 'silabus:uji', 'type' => 'silabus', 'title' => 'Silabus Uji',
            'path' => 'silabus-uji.pdf', 'sha256' => str_repeat('b', 64), 'page_count' => 2,
        ]);
        $parent = $this->ggb($level, $ggbDocument, null, 1, 'subaspect', 'Adab');
        $linkedLeaf = $this->ggb($level, $ggbDocument, $parent, 2, 'topic', 'Mengucapkan salam');
        $this->ggb($level, $ggbDocument, $parent, 3, 'topic', 'Bersikap sopan');
        $linkedSyllabus = $this->syllabus($level, $syllabusDocument, 1, 'Mengucapkan dan menjawab salam');
        $this->syllabus($level, $syllabusDocument, 2, 'Adab bertamu');
        GgbSyllabusLink::query()->create([
            'ggb_item_id' => $linkedLeaf->id,
            'syllabus_item_id' => $linkedSyllabus->id,
            'status' => 'sesuai',
            'confidence' => 1,
        ]);
        $year = AcademicYear::query()->create([
            'label' => '2026/2027', 'starts_on' => '2026-07-06', 'ends_on' => '2027-07-04', 'is_active' => true,
        ]);
        $week = CalendarWeek::query()->create([
            'academic_year_id' => $year->id, 'week_number' => 1, 'semester' => 1, 'starts_on' => '2026-07-06',
            'month_label' => 'Juli', 'type' => 'effective', 'is_effective' => true,
        ]);
        $plan = RppPlan::query()->create([
            'academic_year_id' => $year->id, 'level_id' => $level->id, 'semester' => 1, 'status' => 'draft',
        ]);

        return [$level, $plan, $week, $linkedLeaf];
    }

    private function ggb(Level $level, SourceDocument $document, ?GgbItem $parent, int $order, string $kind, string $title): GgbItem
    {
        return GgbItem::query()->create([
            'level_id' => $level->id, 'source_document_id' => $document->id, 'parent_id' => $parent?->id,
            'source_key' => 'ggb:uji:'.$order, 'stable_code' => 'UJI / ADAB / '.str_pad((string) $order, 3, '0', STR_PAD_LEFT),
            'kind' => $kind, 'aspect' => 'Akhlaqul Karimah', 'subaspect' => 'Adab', 'title' => $title,
            'raw_text' => $title, 'source_page' => 1, 'sort_order' => $order,
        ]);
    }

    private function syllabus(Level $level, SourceDocument $document, int $order, string $title): SyllabusItem
    {
        return SyllabusItem::query()->create([
            'level_id' => $level->id, 'source_document_id' => $document->id, 'source_key' => 'silabus:uji:'.$order,
            'stable_code' => 'UJI / ADAB / S'.str_pad((string) $order, 3, '0', STR_PAD_LEFT),
            'category' => 'Praktik Adab', 'title' => $title, 'description' => $title,
            'allocation_text' => '1 pertemuan / minggu', 'recommended_sessions' => 1,
            'schedule_pattern' => 'weekly', 'schedule_pattern_source' => 'auto', 'needs_allocation' => false,
            'is_duplicate' => false, 'source_page' => 1, 'sort_order' => $order, 'group_number' => 1,
            'source_semester' => 'both', 'semester_scope' => 'both',
        ]);
    }
}
