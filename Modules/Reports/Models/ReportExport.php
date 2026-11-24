<?php

namespace Modules\Reports\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Files\Models\File;
use Modules\Identity\Models\User;
use Modules\Projects\Models\Project;

class ReportExport extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'created_by_user_id', 'project_id', 'file_id', 'report_type', 'parameters', 'status', 'generated_at', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'parameters' => 'array',
            'generated_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    protected static function newFactory(): \Illuminate\Database\Eloquent\Factories\Factory
    {
        return \Modules\Reports\Factories\ReportExportFactory::new();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function file(): BelongsTo
    {
        return $this->belongsTo(File::class);
    }
}
