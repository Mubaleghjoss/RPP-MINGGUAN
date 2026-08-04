<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AcademicSemester extends Model
{
    protected $guarded = [];

    protected $casts = ['starts_on' => 'date', 'ends_on' => 'date'];

    public function academicYear()
    {
        return $this->belongsTo(AcademicYear::class);
    }
}
