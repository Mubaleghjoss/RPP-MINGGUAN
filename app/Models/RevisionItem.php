<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RevisionItem extends Model
{
    protected $guarded = [];
    protected $casts = ['before_values' => 'array', 'after_values' => 'array'];

    public function batch() { return $this->belongsTo(RevisionBatch::class, 'revision_batch_id'); }
}
