<?php

namespace App\Services;

use App\Models\GgbItem;
use App\Models\GgbSyllabusLink;
use App\Models\Level;
use App\Models\RevisionBatch;
use App\Models\RevisionItem;
use App\Models\RppPlan;
use App\Models\RppWeekItem;
use App\Models\SyllabusItem;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class CurriculumRevisionService
{
    private const EDITABLE = [
        'ggb' => ['aspect', 'subaspect', 'title', 'target_text', 'sort_order'],
        'syllabus' => ['category', 'title', 'description', 'allocation_text', 'recommended_sessions', 'reference_text', 'assessment_text', 'is_duplicate', 'sort_order'],
        'link' => ['status', 'notes'],
        'rpp' => ['calendar_week_id', 'strand', 'content', 'position', 'is_locked'],
    ];

    public function applyBatch(array $patches, string $reason, User $user): RevisionBatch
    {
        Validator::make(['reason' => $reason, 'patches' => $patches], [
            'reason' => ['required', 'string', 'min:5', 'max:500'],
            'patches' => ['required', 'array', 'min:1', 'max:500'],
        ], ['reason.min' => 'Alasan revisi minimal 5 karakter.'])->validate();

        return DB::transaction(function () use ($patches, $reason, $user) {
            $batch = RevisionBatch::query()->create([
                'uuid' => (string) Str::uuid(),
                'user_id' => $user->id,
                'action' => 'edit',
                'reason' => trim($reason),
            ]);
            $levels = [];

            foreach ($patches as $index => $patch) {
                $domain = (string) ($patch['domain'] ?? '');
                $id = (int) ($patch['id'] ?? 0);
                $expectedVersion = (int) ($patch['version'] ?? -1);
                $changes = (array) ($patch['changes'] ?? []);
                $model = $this->findModel($domain, $id);

                if ((int) $model->lock_version !== $expectedVersion) {
                    throw new RuntimeException("Konflik pada baris {$index}: data telah diubah di tab lain. Muat ulang sebelum menyimpan.");
                }

                $changes = $this->validatedChanges($domain, $changes, $model);
                if ($changes === []) {
                    continue;
                }

                if ($domain === 'syllabus' && (array_key_exists('allocation_text', $changes) || array_key_exists('recommended_sessions', $changes))) {
                    $allocation = $changes['allocation_text'] ?? $model->allocation_text;
                    $sessions = $changes['recommended_sessions'] ?? $model->recommended_sessions;
                    $changes['needs_allocation'] = blank($allocation) || $sessions === null;
                }
                if ($domain === 'rpp' && array_intersect(array_keys($changes), ['calendar_week_id', 'strand', 'content', 'position'])) {
                    $changes['source'] = 'manual';
                    $changes['is_locked'] = true;
                }

                $before = collect(array_keys($changes))->mapWithKeys(fn ($field) => [$field => $model->getAttribute($field)])->all();
                $newVersion = $expectedVersion + 1;
                $model->forceFill($changes + ['lock_version' => $newVersion, 'last_edited_by' => $user->id])->save();

                RevisionItem::query()->create([
                    'revision_batch_id' => $batch->id,
                    'revisable_type' => $domain,
                    'revisable_id' => $model->getKey(),
                    'before_values' => $before,
                    'after_values' => collect(array_keys($changes))->mapWithKeys(fn ($field) => [$field => $model->fresh()->getAttribute($field)])->all(),
                    'before_lock_version' => $expectedVersion,
                    'after_lock_version' => $newVersion,
                ]);
                $this->collectAffectedLevel($domain, $model, $levels);
            }

            if ($batch->items()->count() === 0) {
                throw ValidationException::withMessages(['grid' => 'Tidak ada nilai yang berubah.']);
            }

            $this->markPlansDraft(array_keys($levels));
            $batch->update(['item_count' => $batch->items()->count()]);
            $this->activity($user, 'curriculum.batch_saved', ['batch_uuid' => $batch->uuid, 'items' => $batch->item_count]);

            return $batch->fresh(['items', 'user']);
        });
    }

    public function addLink(Level $level, string $ggbCode, string $syllabusCode, string $status, ?string $notes, string $reason, User $user): RevisionBatch
    {
        Validator::make(compact('ggbCode', 'syllabusCode', 'status', 'reason'), [
            'ggbCode' => ['required', 'string'],
            'syllabusCode' => ['required', 'string'],
            'status' => ['required', 'in:sesuai,sebagian,perlu_verifikasi'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ])->validate();

        $ggb = $level->ggbItems()->where('stable_code', trim($ggbCode))->firstOrFail();
        $syllabus = $level->syllabusItems()->where('stable_code', trim($syllabusCode))->firstOrFail();

        return DB::transaction(function () use ($level, $ggb, $syllabus, $status, $notes, $reason, $user) {
            $link = GgbSyllabusLink::withTrashed()->where('ggb_item_id', $ggb->id)->where('syllabus_item_id', $syllabus->id)->first();
            if ($link && ! $link->trashed()) {
                throw ValidationException::withMessages(['relation' => 'Relasi tersebut sudah tersedia.']);
            }
            $beforeVersion = (int) ($link?->lock_version ?? 0);
            $before = ['deleted_at' => $link?->deleted_at?->toISOString() ?? now()->toISOString()];
            if ($link) {
                $link->restore();
                $link->update(['status' => $status, 'notes' => $notes, 'lock_version' => $link->lock_version + 1, 'last_edited_by' => $user->id]);
            } else {
                $link = GgbSyllabusLink::query()->create([
                    'ggb_item_id' => $ggb->id,
                    'syllabus_item_id' => $syllabus->id,
                    'status' => $status,
                    'confidence' => 1,
                    'notes' => $notes,
                    'lock_version' => 1,
                    'last_edited_by' => $user->id,
                ]);
            }
            $after = ['ggb_item_id' => $ggb->id, 'syllabus_item_id' => $syllabus->id, 'status' => $status, 'notes' => $notes, 'deleted_at' => null];
            return $this->recordStandalone($link, 'link', $before, $after, $beforeVersion, (int) $link->lock_version, $reason, $user, [$level->id]);
        });
    }

    public function deleteLink(GgbSyllabusLink $link, string $reason, User $user): RevisionBatch
    {
        Validator::make(['reason' => $reason], ['reason' => ['required', 'string', 'min:5', 'max:500']])->validate();
        return DB::transaction(function () use ($link, $reason, $user) {
            $link->load('syllabusItem');
            $beforeVersion = (int) $link->lock_version;
            $before = ['deleted_at' => null];
            $link->update(['lock_version' => $beforeVersion + 1, 'last_edited_by' => $user->id]);
            $link->delete();
            $after = ['deleted_at' => $link->deleted_at?->toISOString()];
            return $this->recordStandalone($link, 'link', $before, $after, $beforeVersion, $beforeVersion + 1, $reason, $user, [$link->syllabusItem->level_id]);
        });
    }

    public function restoreBatch(RevisionBatch $source, string $reason, User $user): RevisionBatch
    {
        Validator::make(['reason' => $reason], ['reason' => ['required', 'string', 'min:5', 'max:500']])->validate();
        return DB::transaction(function () use ($source, $reason, $user) {
            $source->load('items');
            $batch = RevisionBatch::query()->create([
                'uuid' => (string) Str::uuid(), 'user_id' => $user->id, 'action' => 'restore',
                'source_batch_uuid' => $source->uuid, 'reason' => trim($reason),
            ]);
            $levels = [];
            foreach ($source->items->sortByDesc('id') as $sourceItem) {
                $model = $this->findModel($sourceItem->revisable_type, $sourceItem->revisable_id, true);
                if ((int) $model->lock_version !== (int) $sourceItem->after_lock_version) {
                    throw new RuntimeException('Pemulihan dibatalkan karena salah satu baris sudah memiliki revisi yang lebih baru.');
                }
                $before = collect(array_keys($sourceItem->before_values))->mapWithKeys(fn ($field) => [$field => $model->getAttribute($field)])->all();
                $newVersion = (int) $model->lock_version + 1;
                $restoreValues = $sourceItem->before_values;
                $model->forceFill($restoreValues + ['lock_version' => $newVersion, 'last_edited_by' => $user->id])->save();
                if ($model instanceof GgbSyllabusLink && array_key_exists('deleted_at', $restoreValues)) {
                    $restoreValues['deleted_at'] === null ? $model->restore() : $model->delete();
                }
                RevisionItem::query()->create([
                    'revision_batch_id' => $batch->id,
                    'revisable_type' => $sourceItem->revisable_type,
                    'revisable_id' => $model->getKey(),
                    'before_values' => $before,
                    'after_values' => $sourceItem->before_values,
                    'before_lock_version' => $sourceItem->after_lock_version,
                    'after_lock_version' => $newVersion,
                ]);
                $this->collectAffectedLevel($sourceItem->revisable_type, $model, $levels);
            }
            $this->markPlansDraft(array_keys($levels));
            $batch->update(['item_count' => $batch->items()->count()]);
            $this->activity($user, 'curriculum.batch_restored', ['batch_uuid' => $batch->uuid, 'source_batch_uuid' => $source->uuid]);
            return $batch;
        });
    }

    private function validatedChanges(string $domain, array $changes, Model $model): array
    {
        $allowed = self::EDITABLE[$domain] ?? throw ValidationException::withMessages(['grid' => 'Jenis tabel tidak valid.']);
        $changes = array_intersect_key($changes, array_flip($allowed));
        $rules = [
            'aspect' => ['required', 'string', 'max:255'], 'subaspect' => ['required', 'string', 'max:255'],
            'title' => ['required', 'string', 'max:5000'], 'target_text' => ['nullable', 'string', 'max:5000'],
            'sort_order' => ['required', 'integer', 'min:1', 'max:1000000'], 'category' => ['required', 'string', 'max:1000'],
            'description' => ['required', 'string', 'max:10000'], 'allocation_text' => ['nullable', 'string', 'max:5000'],
            'recommended_sessions' => ['nullable', 'integer', 'min:1', 'max:500'], 'reference_text' => ['nullable', 'string', 'max:5000'],
            'assessment_text' => ['nullable', 'string', 'max:5000'], 'is_duplicate' => ['boolean'],
            'status' => ['required', 'in:sesuai,sebagian,perlu_verifikasi'], 'notes' => ['nullable', 'string', 'max:5000'],
            'calendar_week_id' => ['required', 'integer', 'min:1'], 'strand' => ['required', 'string', 'max:1000'],
            'content' => ['required', 'string', 'max:10000'], 'position' => ['required', 'integer', 'min:1', 'max:1000'],
            'is_locked' => ['boolean'],
        ];
        $normalized = [];
        foreach ($changes as $field => $value) {
            if (in_array($field, ['sort_order', 'recommended_sessions', 'calendar_week_id', 'position'], true)) {
                $value = blank($value) && $field === 'recommended_sessions' ? null : (int) $value;
            }
            if (in_array($field, ['is_duplicate', 'is_locked'], true)) {
                $value = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? (bool) $value;
            }
            if (is_string($value)) {
                $value = trim($value);
                $value = $value === '' && str_starts_with((string) ($rules[$field][0] ?? ''), 'nullable') ? null : $value;
            }
            Validator::make(['value' => $value], ['value' => $rules[$field]])->validate();
            if ($model->getAttribute($field) != $value) {
                $normalized[$field] = $value;
            }
        }
        if ($domain === 'rpp' && isset($normalized['calendar_week_id'])) {
            $item = $model instanceof RppWeekItem ? $model : throw new RuntimeException('Baris RPP tidak valid.');
            $valid = $item->plan->academicYear->weeks()->whereKey($normalized['calendar_week_id'])->where('is_effective', true)->exists();
            if (! $valid) {
                throw ValidationException::withMessages(['grid' => 'Materi hanya dapat dipindahkan ke minggu efektif.']);
            }
        }
        return $normalized;
    }

    private function findModel(string $domain, int $id, bool $withTrashed = false): Model
    {
        return match ($domain) {
            'ggb' => GgbItem::query()->findOrFail($id),
            'syllabus' => SyllabusItem::query()->findOrFail($id),
            'link' => ($withTrashed ? GgbSyllabusLink::withTrashed() : GgbSyllabusLink::query())->findOrFail($id),
            'rpp' => RppWeekItem::query()->with('plan.academicYear')->findOrFail($id),
            default => throw ValidationException::withMessages(['grid' => 'Jenis tabel tidak valid.']),
        };
    }

    private function collectAffectedLevel(string $domain, Model $model, array &$levels): void
    {
        $levelId = match ($domain) {
            'ggb', 'syllabus' => $model->level_id,
            'link' => $model->syllabusItem()->value('level_id'),
            'rpp' => $model->plan()->value('level_id'),
            default => null,
        };
        if ($levelId) {
            $levels[$levelId] = true;
        }
    }

    private function markPlansDraft(array $levelIds): void
    {
        if ($levelIds !== []) {
            RppPlan::query()->whereIn('level_id', $levelIds)->update(['status' => 'draft', 'validated_at' => null]);
        }
    }

    private function recordStandalone(Model $model, string $domain, array $before, array $after, int $beforeVersion, int $afterVersion, string $reason, User $user, array $levels): RevisionBatch
    {
        $batch = RevisionBatch::query()->create([
            'uuid' => (string) Str::uuid(), 'user_id' => $user->id, 'action' => 'edit',
            'reason' => trim($reason), 'item_count' => 1,
        ]);
        RevisionItem::query()->create([
            'revision_batch_id' => $batch->id, 'revisable_type' => $domain, 'revisable_id' => $model->getKey(),
            'before_values' => $before, 'after_values' => $after,
            'before_lock_version' => $beforeVersion, 'after_lock_version' => $afterVersion,
        ]);
        $this->markPlansDraft($levels);
        $this->activity($user, 'curriculum.relation_changed', ['batch_uuid' => $batch->uuid]);
        return $batch;
    }

    private function activity(User $user, string $action, array $details): void
    {
        DB::table('activity_logs')->insert([
            'user_id' => $user->id, 'action' => $action,
            'details' => json_encode($details, JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
