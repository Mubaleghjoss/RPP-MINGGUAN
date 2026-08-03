<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SyllabusItem extends Model
{
    protected $guarded = [];

    protected $attributes = ['source_semester' => '1', 'semester_scope' => '1'];

    protected $casts = ['needs_allocation' => 'boolean', 'is_duplicate' => 'boolean', 'source_payload' => 'array'];

    public function level()
    {
        return $this->belongsTo(Level::class);
    }

    public function document()
    {
        return $this->belongsTo(SourceDocument::class, 'source_document_id');
    }

    public function ggbItems()
    {
        return $this->belongsToMany(GgbItem::class, 'ggb_syllabus_links')->withPivot(['id', 'status', 'confidence', 'notes', 'lock_version'])->wherePivotNull('deleted_at');
    }

    public function placements()
    {
        return $this->hasMany(RppWeekItem::class);
    }

    public function progressTargets()
    {
        return $this->hasMany(RppProgressTarget::class);
    }

    public function editor()
    {
        return $this->belongsTo(User::class, 'last_edited_by');
    }
}
