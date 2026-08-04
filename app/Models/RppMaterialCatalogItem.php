<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class RppMaterialCatalogItem extends Model
{
    protected $guarded = [];

    protected $casts = [
        'semester_confirmed' => 'boolean',
        'auto_include' => 'boolean',
        'is_schedulable' => 'boolean',
        'is_active' => 'boolean',
        'rotation_enabled' => 'boolean',
    ];

    public function scopeNeedsRppColumnConfirmation(Builder $query): Builder
    {
        return $query->where(fn (Builder $query) => $query
            ->whereNull('rpp_matrix_column_id')
            ->orWhereNull('mapping_status')
            ->orWhere('mapping_status', '!=', 'mapped'));
    }

    public function level()
    {
        return $this->belongsTo(Level::class);
    }

    public function matrixColumn()
    {
        return $this->belongsTo(RppMatrixColumn::class, 'rpp_matrix_column_id');
    }

    public function ggbItem()
    {
        return $this->belongsTo(GgbItem::class);
    }

    public function syllabusItem()
    {
        return $this->belongsTo(SyllabusItem::class);
    }

    public function placements()
    {
        return $this->belongsToMany(RppWeekItem::class, 'rpp_week_item_materials')->withTimestamps();
    }

    public function editor()
    {
        return $this->belongsTo(User::class, 'last_edited_by');
    }
}
