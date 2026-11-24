<?php

namespace Modules\Identity\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuthenticationLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'user_id', 'attempted_email', 'login_method', 'was_successful',
        'failure_reason', 'ip_address', 'user_agent', 'created_at',
    ];

    protected function casts(): array
    {
        return ['was_successful' => 'boolean', 'created_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
