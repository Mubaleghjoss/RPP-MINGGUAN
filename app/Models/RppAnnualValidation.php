<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RppAnnualValidation extends Model
{
    protected $guarded = [];

    protected $casts = ['coverage_percent' => 'decimal:2', 'validated_at' => 'datetime'];

    public function academicYear()
    {
        return $this->belongsTo(AcademicYear::class);
    }

    public function level()
    {
        return $this->belongsTo(Level::class);
    }

    public function validator()
    {
        return $this->belongsTo(User::class, 'validated_by');
    }
}
