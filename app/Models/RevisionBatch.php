<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RevisionBatch extends Model
{
    protected $guarded = [];

    public function user() { return $this->belongsTo(User::class); }
    public function items() { return $this->hasMany(RevisionItem::class); }
}
