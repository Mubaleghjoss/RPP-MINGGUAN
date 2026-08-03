<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class RppProgressTarget extends Model
{
    use SoftDeletes;

    protected $guarded = [];

    public function plan()
    {
        return $this->belongsTo(RppPlan::class, 'rpp_plan_id');
    }

    public function syllabusItem()
    {
        return $this->belongsTo(SyllabusItem::class);
    }

    public function placements()
    {
        return $this->hasMany(RppWeekItem::class);
    }

    public function editor()
    {
        return $this->belongsTo(User::class, 'last_edited_by');
    }
}
