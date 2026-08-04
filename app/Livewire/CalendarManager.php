<?php

namespace App\Livewire;

use App\Models\AcademicYear;
use App\Models\Level;
use App\Services\AcademicCalendarService;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.app')]
#[Title('Kalender Akademik')]
class CalendarManager extends Component
{
    public AcademicYear $year;

    public function mount(): void
    {
        $this->year = AcademicYear::query()->where('is_active', true)->firstOrFail();
    }

    public function render(AcademicCalendarService $calendar)
    {
        return view('livewire.calendar-manager', [
            'semesters' => collect([1, 2])->map(fn ($semester) => $calendar->semester($this->year, $semester)),
            'events' => $this->year->calendarEvents()->with('levels:id,name')->orderBy('starts_on')->get(),
            'firstLevelId' => Level::query()->orderBy('sort_order')->value('id'),
        ]);
    }
}
