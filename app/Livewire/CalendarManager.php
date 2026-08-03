<?php

namespace App\Livewire;

use App\Models\AcademicYear;
use App\Models\CalendarWeek;
use App\Models\RppWeekItem;
use App\Services\RppPlanner;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Illuminate\Support\Facades\DB;

#[Layout('layouts.app')]
#[Title('Kalender Akademik')]
class CalendarManager extends Component
{
    public AcademicYear $year;
    public string $notice = '';

    public function mount(): void
    {
        $this->year = AcademicYear::query()->where('is_active', true)->firstOrFail();
    }

    public function setType(int $weekId, string $type, RppPlanner $planner): void
    {
        abort_unless(in_array($type, ['effective', 'evaluation', 'holiday', 'religious_holiday'], true), 422);
        $week = CalendarWeek::query()->where('academic_year_id', $this->year->id)->findOrFail($weekId);
        if ($type !== 'effective' && RppWeekItem::query()->where('calendar_week_id', $week->id)->where('is_locked', true)->exists()) {
            $this->notice = 'Minggu belum dapat diubah karena memiliki materi yang dikunci.';
            return;
        }
        if ($type !== 'effective') {
            RppWeekItem::query()->where('calendar_week_id', $week->id)->where('is_locked', false)->delete();
        }
        $week->update(['type' => $type, 'is_effective' => $type === 'effective']);
        $planner->generateAll();
        DB::table('activity_logs')->insert([
            'user_id' => auth()->id(),
            'action' => 'calendar.week_type_changed',
            'details' => json_encode(['week_id' => $week->id, 'week_number' => $week->week_number, 'type' => $type], JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->notice = 'Jenis minggu diperbarui dan seluruh draf otomatis diseimbangkan kembali.';
    }

    public function render()
    {
        $weeks = $this->year->weeks()->withCount('placements')->orderBy('week_number')->get();
        return view('livewire.calendar-manager', compact('weeks'));
    }
}
