<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RppMonthFocus extends Model
{
    protected $table = 'rpp_month_focuses';

    protected $guarded = [];

    protected $casts = ['is_locked' => 'boolean'];

    public function plan()
    {
        return $this->belongsTo(RppPlan::class, 'rpp_plan_id');
    }

    public function editor()
    {
        return $this->belongsTo(User::class, 'last_edited_by');
    }
}
