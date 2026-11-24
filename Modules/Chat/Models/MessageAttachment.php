<?php

namespace Modules\Chat\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Modules\Files\Models\File;

class MessageAttachment extends Pivot
{
    protected $table = 'message_attachments';

    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = ['message_id', 'file_id'];

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    public function file(): BelongsTo
    {
        return $this->belongsTo(File::class);
    }
}
