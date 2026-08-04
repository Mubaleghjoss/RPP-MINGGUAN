<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AcademicYear extends Model
{
    protected $guarded = [];

    protected $casts = ['starts_on' => 'date', 'ends_on' => 'date', 'is_active' => 'boolean'];

    public function weeks()
    {
        return $this->hasMany(CalendarWeek::class);
    }

    public function plans()
    {
        return $this->hasMany(RppPlan::class);
    }

    public function semesters()
    {
        return $this->hasMany(AcademicSemester::class);
    }

    public function calendarEvents()
    {
        return $this->hasMany(CalendarEvent::class);
    }

    public function annualValidations()
    {
        return $this->hasMany(RppAnnualValidation::class);
    }
}
