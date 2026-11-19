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

    /** A YouTube block (Title / Description with hashtags / Tags) is already complete. */
    public function isYoutubeBlock(): bool
    {
        return str_starts_with(trim($this->body), 'Title:');
    }

    public function postText(): string
    {
        return $this->isYoutubeBlock() ? trim($this->body) : trim($this->body)."\n\n".implode(' ', $this->hashtags ?? []);
    }
}
