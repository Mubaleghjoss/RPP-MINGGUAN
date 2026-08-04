<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Level extends Model
{
    protected $guarded = [];

    public function documents()
    {
        return $this->hasMany(SourceDocument::class);
    }

    public function ggbItems()
    {
        return $this->hasMany(GgbItem::class);
    }

    public function syllabusItems()
    {
        return $this->hasMany(SyllabusItem::class);
    }

    public function plans()
    {
        return $this->hasMany(RppPlan::class);
    }

    public function matrixColumns()
    {
        return $this->hasMany(RppMatrixColumn::class)->orderBy('sort_order');
    }

    public function materialCatalogItems()
    {
        return $this->hasMany(RppMaterialCatalogItem::class)->orderBy('sort_order');
    }
}
