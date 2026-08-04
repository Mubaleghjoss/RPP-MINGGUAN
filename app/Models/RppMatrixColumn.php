<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RppMatrixColumn extends Model
{
    protected $guarded = [];

    protected $casts = ['is_active' => 'boolean'];

    public function level()
    {
        return $this->belongsTo(Level::class);
    }

    public function mappings()
    {
        return $this->hasMany(RppMatrixMapping::class);
    }

    public function syllabusItems()
    {
        return $this->belongsToMany(SyllabusItem::class, 'rpp_matrix_mappings')->withTimestamps();
    }

    public function placements()
    {
        return $this->hasMany(RppWeekItem::class);
    }

    public function catalogItems()
    {
        return $this->hasMany(RppMaterialCatalogItem::class);
    }

    public function editor()
    {
        return $this->belongsTo(User::class, 'last_edited_by');
    }
}
