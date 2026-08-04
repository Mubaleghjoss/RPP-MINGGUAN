<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RppMaterialCatalogItem extends Model
{
    protected $guarded = [];

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
