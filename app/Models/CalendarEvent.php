<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CalendarEvent extends Model
{
    protected $guarded = [];

    protected $casts = [
        'starts_on' => 'date',
        'ends_on' => 'date',
        'applies_to_all' => 'boolean',
    ];

    public function academicYear()
    {
        return $this->belongsTo(AcademicYear::class);
    }

    public function levels()
    {
        return $this->belongsToMany(Level::class, 'calendar_event_level');
    }

    public function editor()
    {
        return $this->belongsTo(User::class, 'last_edited_by');
    }
}
