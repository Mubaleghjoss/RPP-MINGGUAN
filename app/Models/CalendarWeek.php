<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CalendarWeek extends Model
{
    protected $guarded = [];
    protected $casts = ['starts_on' => 'date', 'is_effective' => 'boolean'];
    public function academicYear() { return $this->belongsTo(AcademicYear::class); }
    public function placements() { return $this->hasMany(RppWeekItem::class); }
}
