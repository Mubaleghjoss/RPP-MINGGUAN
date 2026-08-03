<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RppMatrixMapping extends Model
{
    protected $guarded = [];

    public function column()
    {
        return $this->belongsTo(RppMatrixColumn::class, 'rpp_matrix_column_id');
    }

    public function syllabusItem()
    {
        return $this->belongsTo(SyllabusItem::class);
    }

    public function editor()
    {
        return $this->belongsTo(User::class, 'last_edited_by');
    }
}
