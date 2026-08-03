<?php

namespace App\Services;

use App\Models\CalendarWeek;
use Illuminate\Support\Collection;

class RppSchedulePatternService
{
    public const PATTERNS = ['weekly', 'month_week_1', 'month_week_2', 'month_week_3', 'month_week_4', 'month_week_1_3', 'month_week_2_4', 'tentative', 'unknown'];

    public function detect(?string $allocation): string
    {
        $value = mb_strtolower(trim((string) $allocation));
        if ($value === '') {
            return 'unknown';
        }
        if (str_contains($value, 'tentatif')) {
            return 'tentative';
        }
        if (preg_match('/minggu\s+ke-?\s*1.*minggu\s+ke-?\s*3/u', $value)) {
            return 'month_week_1_3';
        }
        if (preg_match('/minggu\s+ke-?\s*2.*minggu\s+ke-?\s*4/u', $value)) {
            return 'month_week_2_4';
        }
        foreach ([1, 2, 3, 4] as $week) {
            if (preg_match('/minggu\s+ke-?\s*'.$week.'(?!\d)/u', $value)) {
                return 'month_week_'.$week;
            }
        }
        if (str_contains($value, 'minggu') || preg_match('/ditempuh\s+\d+\s+bulan/u', $value)) {
            return 'weekly';
        }

        return 'unknown';
    }

    public function slots(string $pattern, Collection $allWeeks): Collection
    {
        $effective = $allWeeks->where('is_effective', true)->values();
        if ($pattern === 'weekly') {
            return $effective;
        }
        if (in_array($pattern, ['tentative', 'unknown'], true)) {
            return collect();
        }

        $wanted = match ($pattern) {
            'month_week_1_3' => [1, 3],
            'month_week_2_4' => [2, 4],
            default => [(int) str_replace('month_week_', '', $pattern)],
        };
        $ordinals = [];
        foreach ($allWeeks->sortBy('week_number')->values() as $week) {
            $key = $week->starts_on->format('Y-m');
            $ordinals[$key] = ($ordinals[$key] ?? 0) + 1;
            $week->setAttribute('month_ordinal', $ordinals[$key]);
        }

        return $effective->filter(fn (CalendarWeek $week) => in_array((int) $week->month_ordinal, $wanted, true))->values();
    }

    public function label(string $pattern): string
    {
        return match ($pattern) {
            'weekly' => 'Setiap minggu efektif', 'month_week_1' => 'Minggu ke-1 tiap bulan',
            'month_week_2' => 'Minggu ke-2 tiap bulan', 'month_week_3' => 'Minggu ke-3 tiap bulan',
            'month_week_4' => 'Minggu ke-4 tiap bulan', 'month_week_1_3' => 'Minggu ke-1 dan ke-3',
            'month_week_2_4' => 'Minggu ke-2 dan ke-4', 'tentative' => 'Tentatif — jadwalkan manual',
            default => 'Perlu Pola Jadwal',
        };
    }
}
