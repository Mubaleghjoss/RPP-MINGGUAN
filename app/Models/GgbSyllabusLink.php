<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class GgbSyllabusLink extends Model
{
    use SoftDeletes;

    protected $table = 'ggb_syllabus_links';
    protected $guarded = [];
    protected $casts = ['confidence' => 'decimal:4'];

    public function ggbItem() { return $this->belongsTo(GgbItem::class); }
    public function syllabusItem() { return $this->belongsTo(SyllabusItem::class); }
    public function editor() { return $this->belongsTo(User::class, 'last_edited_by'); }
}
