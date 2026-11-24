<?php

namespace Modules\Chat\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Modules\Identity\Models\User;

class MessageMention extends Pivot
{
    protected $table = 'message_mentions';

    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = ['message_id', 'mentioned_user_id', 'created_at'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    public function mentionedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'mentioned_user_id');
    }
}
