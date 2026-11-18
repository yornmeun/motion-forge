<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Caption extends Model
{
    use HasUuids;

    protected $fillable = ['project_id', 'body', 'hashtags', 'origin', 'ai_request_id'];

    protected function casts(): array
    {
        return ['hashtags' => 'array'];
    }

    public function postText(): string
    {
        return trim($this->body)."\n\n".implode(' ', $this->hashtags ?? []);
    }
}
