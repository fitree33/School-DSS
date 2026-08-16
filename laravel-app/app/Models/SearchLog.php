<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SearchLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'project_id',
        'keyword',
        'intent',
        'filters',
        'ai_response',
        'model_name',
        'response_status',
        'response_time_ms',
    ];

    protected $casts = ['filters' => 'array'];

    public function results()
    {
        return $this->hasMany(SearchResult::class);
    }
}
