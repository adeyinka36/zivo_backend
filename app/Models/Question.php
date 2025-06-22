<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Question extends Model
{
    /** @use HasFactory<\Database\Factories\QuestionFactory> */
    use HasFactory, SoftDeletes, HasUuids;

    protected $fillable = [
        'question',
        'answer',
        'option_a',
        'option_b',
        'option_c',
        'option_d',
        'media_id'
    ];

    protected $casts = [
        'answer' => 'string'
    ];

    public function media(): BelongsTo
    {
        return $this->belongsTo(Media::class);
    }
}
