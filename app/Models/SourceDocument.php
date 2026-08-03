<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SourceDocument extends Model
{
    protected $guarded = [];
    public function level() { return $this->belongsTo(Level::class); }
    public function getIsAvailableAttribute(): bool { return is_file(base_path($this->path)); }
}
