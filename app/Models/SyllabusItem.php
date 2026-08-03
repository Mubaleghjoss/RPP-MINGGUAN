<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SyllabusItem extends Model
{
    protected $guarded = [];
    protected $casts = ['needs_allocation' => 'boolean', 'is_duplicate' => 'boolean', 'source_payload' => 'array'];
    public function level() { return $this->belongsTo(Level::class); }
    public function document() { return $this->belongsTo(SourceDocument::class, 'source_document_id'); }
    public function ggbItems() { return $this->belongsToMany(GgbItem::class, 'ggb_syllabus_links')->withPivot(['id', 'status', 'confidence', 'notes', 'lock_version'])->wherePivotNull('deleted_at'); }
    public function placements() { return $this->hasMany(RppWeekItem::class); }
    public function editor() { return $this->belongsTo(User::class, 'last_edited_by'); }
}
