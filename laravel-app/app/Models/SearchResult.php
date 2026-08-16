<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SearchResult extends Model
{
    use HasFactory;

    protected $fillable = [
        'search_log_id',
        'project_id',
        'rank',
        'relevance_score',
        'reason',
    ];

    protected $casts = ['relevance_score' => 'decimal:4'];

    public function project()
    {
        return $this->belongsTo(Project::class);
    }
}
