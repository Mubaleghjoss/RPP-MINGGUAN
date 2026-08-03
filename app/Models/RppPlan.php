<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RppPlan extends Model
{
    protected $guarded = [];
    protected $casts = ['validated_at' => 'datetime', 'coverage_percent' => 'decimal:2'];
    public function level() { return $this->belongsTo(Level::class); }
    public function academicYear() { return $this->belongsTo(AcademicYear::class); }
    public function items() { return $this->hasMany(RppWeekItem::class); }
}
