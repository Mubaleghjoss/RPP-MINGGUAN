<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GgbItem extends Model
{
    protected $guarded = [];
    protected $casts = ['source_payload' => 'array'];
    public function level() { return $this->belongsTo(Level::class); }
    public function document() { return $this->belongsTo(SourceDocument::class, 'source_document_id'); }
    public function parent() { return $this->belongsTo(self::class, 'parent_id'); }
    public function children() { return $this->hasMany(self::class, 'parent_id'); }
    public function syllabusItems() { return $this->belongsToMany(SyllabusItem::class, 'ggb_syllabus_links')->withPivot(['id', 'status', 'confidence', 'notes', 'lock_version'])->wherePivotNull('deleted_at'); }
    public function editor() { return $this->belongsTo(User::class, 'last_edited_by'); }
}
