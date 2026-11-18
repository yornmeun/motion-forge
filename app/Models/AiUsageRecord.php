<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiUsageRecord extends Model
{
    protected $fillable = ['user_id', 'project_id', 'ai_request_id', 'provider_slug', 'model', 'period', 'estimated_cost_usd', 'confirmed_cost_usd', 'source', 'notes'];

    protected function casts(): array
    {
        return ['estimated_cost_usd' => 'decimal:6', 'confirmed_cost_usd' => 'decimal:6'];
    }
}
