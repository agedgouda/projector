<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use \Illuminate\Database\Eloquent\Relations\HasOne;
use Pgvector\Laravel\HasNeighbors;
use Pgvector\Laravel\Vector;

class Document extends Model
{
    use HasNeighbors;
    use HasUuids;
    protected $keyType = 'string';
    public $incrementing = false;

    protected $casts = [
        'embedding' => Vector::class,
    ];

    protected $fillable = ['content', 'embedding', 'type'];

    /**
     * Get the project associated with this DNA snippet.
     */
    public function project(): HasOne
    {
        return $this->hasOne(Project::class, 'document_id');
    }
}
