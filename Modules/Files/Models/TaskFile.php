<?php

namespace Modules\Files\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Modules\Identity\Models\User;
use Modules\Tasks\Models\Task;

class TaskFile extends Pivot
{
    protected $table = 'task_files';

    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = ['task_id', 'file_id', 'attached_by_user_id', 'attached_at'];

    protected function casts(): array
    {
        return ['attached_at' => 'datetime'];
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function file(): BelongsTo
    {
        return $this->belongsTo(File::class);
    }

    public function attachedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'attached_by_user_id');
    }

}
