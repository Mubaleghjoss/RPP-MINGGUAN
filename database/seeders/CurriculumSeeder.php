<?php

namespace Database\Seeders;

use App\Models\AcademicYear;
use App\Models\AuditFinding;
use App\Models\CalendarWeek;
use App\Models\Level;
use App\Models\RppPlan;
use App\Models\SourceDocument;
use App\Services\RppPlanner;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class CurriculumSeeder extends Seeder
{
    public function run(): void
    {
        if (SourceDocument::query()->exists()) {
            return;
        }

        $path = database_path('data/curriculum.json');
        if (! is_file($path)) {
            throw new RuntimeException('Data ekstraksi belum tersedia. Jalankan: python scripts/extract_curriculum.py');
        }
        $data = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        if (($data['meta']['schema_version'] ?? 0) !== 2 || ($data['meta']['document_count'] ?? 0) !== 34 || ($data['meta']['level_count'] ?? 0) !== 17) {
            throw new RuntimeException('Audit sumber gagal: diperlukan curriculum.json schema 2 dengan 17 jenjang dan 34 dokumen.');
        }

        DB::transaction(function () use ($data) {
            $now = now();
            foreach ($data['levels'] as $row) {
                Level::query()->create($row);
            }
            $levelIds = Level::query()->pluck('id', 'code');

            foreach ($data['documents'] as $row) {
                SourceDocument::query()->create([
                    'level_id' => $levelIds[$row['level_code']],
                    'source_key' => $row['source_key'],
                    'type' => $row['type'],
                    'title' => $row['title'],
                    'path' => $row['path'],
                    'sha256' => $row['sha256'],
                    'page_count' => $row['page_count'],
                ]);
            }
            $documentIds = SourceDocument::query()->pluck('id', 'source_key');

            collect($data['ggb_items'])->chunk(400)->each(function ($chunk) use ($levelIds, $documentIds, $now) {
                DB::table('ggb_items')->insert($chunk->map(fn ($row) => [
                    'level_id' => $levelIds[$row['level_code']],
                    'source_document_id' => $documentIds[$row['document_key']],
                    'parent_id' => null,
                    'source_key' => $row['source_key'],
                    'stable_code' => $row['stable_code'],
                    'kind' => $row['kind'],
                    'aspect' => $row['aspect'],
                    'subaspect' => $row['subaspect'],
                    'title' => $row['title'],
                    'target_text' => null,
                    'raw_text' => $row['raw_text'],
                    'source_payload' => json_encode([
                        'aspect' => $row['aspect'],
                        'subaspect' => $row['subaspect'],
                        'title' => $row['title'],
                        'target_text' => null,
                        'sort_order' => $row['sort_order'],
                    ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                    'source_page' => $row['source_page'],
                    'sort_order' => $row['sort_order'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->all());
            });

            collect($data['syllabus_items'])->chunk(400)->each(function ($chunk) use ($levelIds, $documentIds, $now) {
                DB::table('syllabus_items')->insert($chunk->map(fn ($row) => [
                    'level_id' => $levelIds[$row['level_code']],
                    'source_document_id' => $documentIds[$row['document_key']],
                    'source_key' => $row['source_key'],
                    'stable_code' => $row['stable_code'],
                    'category' => $row['category'],
                    'title' => $row['title'],
                    'description' => $row['description'],
                    'source_payload' => json_encode([
                        'category' => $row['category'],
                        'title' => $row['title'],
                        'description' => $row['description'],
                        'allocation_text' => $row['allocation_text'],
                        'recommended_sessions' => $row['recommended_sessions'],
                        'reference_text' => $row['reference_text'],
                        'assessment_text' => $row['assessment_text'],
                        'is_duplicate' => (bool) ($row['is_duplicate'] ?? false),
                        'source_semester' => $row['source_semester'],
                        'semester_scope' => $row['semester_scope'],
                        'sort_order' => $row['sort_order'],
                    ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                    'allocation_text' => $row['allocation_text'],
                    'reference_text' => $row['reference_text'],
                    'assessment_text' => $row['assessment_text'],
                    'recommended_sessions' => $row['recommended_sessions'],
                    'needs_allocation' => $row['needs_allocation'],
                    'is_duplicate' => $row['is_duplicate'] ?? false,
                    'source_page' => $row['source_page'],
                    'sort_order' => $row['sort_order'],
                    'group_number' => $row['group_number'],
                    'source_semester' => $row['source_semester'],
                    'semester_scope' => $row['semester_scope'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->all());
            });

            $ggbIds = DB::table('ggb_items')->pluck('id', 'source_key');
            collect($data['ggb_items'])
                ->filter(fn ($row) => ! empty($row['parent_source_key']))
                ->each(function ($row) use ($ggbIds) {
                    DB::table('ggb_items')->where('id', $ggbIds[$row['source_key']])->update([
                        'parent_id' => $ggbIds[$row['parent_source_key']] ?? null,
                    ]);
                });
            $syllabusIds = DB::table('syllabus_items')->pluck('id', 'source_key');
            collect($data['links'])->chunk(400)->each(function ($chunk) use ($ggbIds, $syllabusIds, $now) {
                DB::table('ggb_syllabus_links')->insert($chunk->map(fn ($row) => [
                    'ggb_item_id' => $ggbIds[$row['ggb_source_key']],
                    'syllabus_item_id' => $syllabusIds[$row['syllabus_source_key']],
                    'status' => $row['status'],
                    'confidence' => $row['confidence'],
                    'notes' => $row['notes'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->all());
            });

            $year = AcademicYear::query()->create([
                'label' => '2026/2027',
                'starts_on' => '2026-07-06',
                'ends_on' => '2027-07-04',
                'is_active' => true,
            ]);
            $start = CarbonImmutable::parse('2026-07-06');
            for ($week = 1; $week <= 52; $week++) {
                $date = $start->addWeeks($week - 1);
                CalendarWeek::query()->create([
                    'academic_year_id' => $year->id,
                    'week_number' => $week,
                    'semester' => $week <= 26 ? 1 : 2,
                    'starts_on' => $date,
                    'month_label' => $date->locale('id')->translatedFormat('F'),
                    'type' => 'effective',
                    'label' => null,
                    'is_effective' => true,
                ]);
            }

            foreach (Level::query()->orderBy('sort_order')->get() as $level) {
                foreach ([1, 2] as $semester) {
                    RppPlan::query()->create([
                        'academic_year_id' => $year->id,
                        'level_id' => $level->id,
                        'semester' => $semester,
                        'status' => 'draft',
                    ]);
                }

                $counts = DB::table('ggb_syllabus_links')
                    ->join('syllabus_items', 'syllabus_items.id', '=', 'ggb_syllabus_links.syllabus_item_id')
                    ->where('syllabus_items.level_id', $level->id)
                    ->selectRaw('status, count(*) as total')
                    ->groupBy('status')
                    ->pluck('total', 'status');
                foreach (['sebagian', 'perlu_verifikasi'] as $status) {
                    if (($counts[$status] ?? 0) > 0) {
                        AuditFinding::query()->create([
                            'level_id' => $level->id,
                            'type' => 'ggb_syllabus',
                            'severity' => $status === 'perlu_verifikasi' ? 'warning' : 'info',
                            'status' => 'open',
                            'message' => ($counts[$status]).' butir silabus berstatus '.str_replace('_', ' ', $status).'.',
                            'data' => ['link_status' => $status, 'count' => (int) $counts[$status]],
                        ]);
                    }
                }
                $duplicateCount = $level->syllabusItems()->where('is_duplicate', true)->count();
                if ($duplicateCount > 0) {
                    AuditFinding::query()->create([
                        'level_id' => $level->id,
                        'type' => 'duplicate',
                        'severity' => 'info',
                        'status' => 'open',
                        'message' => $duplicateCount.' butir silabus berulang di dokumen sumber dan tidak digandakan ke RPP.',
                        'data' => ['count' => $duplicateCount],
                    ]);
                }
            }
        });

        app(RppPlanner::class)->generateAll();
    }
}
