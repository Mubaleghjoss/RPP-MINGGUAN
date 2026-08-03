<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AuditFinding extends Model
{
    protected $guarded = [];
    protected $casts = ['data' => 'array'];
    public function level() { return $this->belongsTo(Level::class); }
}
