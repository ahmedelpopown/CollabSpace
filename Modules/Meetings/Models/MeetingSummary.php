<?php

namespace Modules\Meetings\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Identity\Models\User;

class MeetingSummary extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = ['meeting_id', 'generated_by_user_id', 'model_name', 'summary_text', 'generated_at'];

    protected function casts(): array
    {
        return ['generated_at' => 'datetime'];
    }

    protected static function newFactory(): \Illuminate\Database\Eloquent\Factories\Factory
    {
        return \Modules\Meetings\Factories\MeetingSummaryFactory::new();
    }

    public function meeting(): BelongsTo
    {
        return $this->belongsTo(Meeting::class);
    }

    public function generatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by_user_id');
    }

    public function decisions(): HasMany
    {
        return $this->hasMany(MeetingDecision::class, 'summary_id');
    }

    public function risks(): HasMany
    {
        return $this->hasMany(MeetingRisk::class, 'summary_id');
    }

    public function nextSteps(): HasMany
    {
        return $this->hasMany(MeetingNextStep::class, 'summary_id');
    }

    public function actionItems(): HasMany
    {
        return $this->hasMany(MeetingActionItem::class, 'summary_id');
    }
}
