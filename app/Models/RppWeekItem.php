<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RppWeekItem extends Model
{
    protected $guarded = [];
    protected $casts = ['is_locked' => 'boolean'];
    public function plan() { return $this->belongsTo(RppPlan::class, 'rpp_plan_id'); }
    public function week() { return $this->belongsTo(CalendarWeek::class, 'calendar_week_id'); }
    public function syllabusItem() { return $this->belongsTo(SyllabusItem::class); }
    public function editor() { return $this->belongsTo(User::class, 'last_edited_by'); }
}
