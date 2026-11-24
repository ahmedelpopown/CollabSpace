<?php

namespace Modules\Meetings\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MeetingNextStep extends Model
{
    public $timestamps = false;

    protected $fillable = ['summary_id', 'step_text', 'created_at'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public function summary(): BelongsTo
    {
        return $this->belongsTo(MeetingSummary::class, 'summary_id');
    }
}
