<?php

namespace App\Services;

use App\Models\Level;
use App\Models\RppMatrixColumn;
use App\Models\RppMatrixMapping;
use App\Models\SyllabusItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class RppMatrixPresetService
{
    private array $rules;

    public function __construct(private readonly RppSchedulePatternService $patterns)
    {
        $path = database_path('data/rpp_matrix_presets.json');
        $data = is_file($path) ? json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR) : [];
        if (($data['schema_version'] ?? 0) !== 1) {
            throw new RuntimeException('Preset matriks RPP tidak valid.');
        }
        $this->rules = $data['columns'] ?? [];
    }

    public function syncAll(): void
    {
        Level::query()->orderBy('sort_order')->each(fn (Level $level) => $this->syncLevel($level));
    }

    public function syncLevel(Level $level): void
    {
        DB::transaction(function () use ($level) {
            $allItems = $level->syllabusItems()->where('is_duplicate', false)->orderBy('sort_order')->get();
            $artifacts = $allItems->filter(fn (SyllabusItem $item) => $this->isSourceArtifact($item));
            SyllabusItem::query()->whereIn('id', $artifacts->pluck('id'))->update(['is_source_artifact' => true]);
            SyllabusItem::query()->whereIn('id', $allItems->pluck('id')->diff($artifacts->pluck('id')))->update(['is_source_artifact' => false]);
            RppMatrixMapping::query()->whereIn('syllabus_item_id', $artifacts->pluck('id'))
                ->whereNull('last_edited_by')->delete();
            $items = $allItems->reject(fn (SyllabusItem $item) => $this->isSourceArtifact($item));
            foreach ($items as $item) {
                $rule = $this->ruleFor($item);
                $column = RppMatrixColumn::query()->firstOrCreate(
                    ['level_id' => $level->id, 'stable_key' => $rule['key']],
                    [
                        'aspect_label' => $rule['aspect'], 'subaspect_label' => $rule['subaspect'],
                        'label' => $this->labelFor($rule, $item, $level), 'sort_order' => $rule['order'],
                        'width' => $rule['width'], 'is_active' => true,
                    ]
                );
                $mapping = RppMatrixMapping::query()->firstOrNew(['syllabus_item_id' => $item->id]);
                if (! $mapping->exists || $mapping->last_edited_by === null) {
                    $mapping->rpp_matrix_column_id = $column->id;
                    $mapping->save();
                }
                if (! $item->schedule_pattern || $item->schedule_pattern_source === 'auto') {
                    $detected = $this->patterns->detect($item->allocation_text);
                    if ($item->schedule_pattern !== $detected) {
                        $item->forceFill(['schedule_pattern' => $detected, 'schedule_pattern_source' => 'auto'])->saveQuietly();
                    }
                }
            }

            DB::table('rpp_week_items')
                ->whereIn('syllabus_item_id', $items->pluck('id'))
                ->where('source', 'auto')
                ->where('is_locked', false)
                ->update([
                    'rpp_matrix_column_id' => DB::raw('(SELECT rpp_matrix_column_id FROM rpp_matrix_mappings WHERE rpp_matrix_mappings.syllabus_item_id = rpp_week_items.syllabus_item_id LIMIT 1)'),
                ]);
            RppMatrixColumn::query()->where('level_id', $level->id)->whereNull('last_edited_by')
                ->whereDoesntHave('mappings')->whereDoesntHave('placements')->delete();
        });
    }

    public function isSourceArtifact(SyllabusItem $item): bool
    {
        $normalize = fn ($value) => Str::of((string) $value)->lower()->ascii()->replaceMatches('/[^a-z0-9]+/', '')->toString();

        return $normalize($item->category) === 'senin'
            && $normalize($item->title) === 'rabu'
            && $normalize($item->allocation_text) === 'jumat'
            && $normalize($item->reference_text) === 'sabtu'
            && $normalize($item->assessment_text) === 'minggu';
    }

    private function ruleFor(SyllabusItem $item): array
    {
        foreach ($this->rules as $rule) {
            if (! in_array(trim((string) $item->category), $rule['categories'] ?? [], true)) {
                continue;
            }
            $needles = $rule['title_contains'] ?? [];
            if ($needles !== [] && ! collect($needles)->contains(fn ($needle) => str_contains(mb_strtolower($item->title), mb_strtolower($needle)))) {
                continue;
            }

            return $rule;
        }

        $linked = $item->ggbItems()->orderByPivot('confidence', 'desc')->first();
        $aspect = $linked?->aspect ?: 'IV. Materi Tambahan';
        $subaspect = $linked?->subaspect ?: 'Perlu Verifikasi';

        return [
            'key' => 'tambahan-'.Str::slug($item->category), 'aspect' => $this->numberedAspect($aspect),
            'subaspect' => $subaspect, 'label' => trim((string) $item->category) ?: 'Materi Tambahan',
            'order' => 900 + ($item->sort_order % 90), 'width' => 24,
        ];
    }

    private function labelFor(array $rule, SyllabusItem $item, Level $level): string
    {
        if ($rule['key'] === 'tilawati') {
            return $level->code === 'PAUD' ? 'Tilawati PAUD' : 'Tilawati';
        }

        return $rule['label'];
    }

    private function numberedAspect(string $aspect): string
    {
        $normalized = mb_strtolower($aspect);
        if (str_contains($normalized, 'alim')) {
            return 'I. Alim-Faqih';
        }
        if (str_contains($normalized, 'akhlaq')) {
            return 'II. Akhlaqul Karimah';
        }
        if (str_contains($normalized, 'mandiri')) {
            return 'III. Kemandirian';
        }

        return str_starts_with($aspect, 'IV.') ? $aspect : 'IV. '.$aspect;
    }
}
