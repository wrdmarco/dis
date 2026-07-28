<?php

namespace App\Models;

use App\Models\Concerns\UsesUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

final class CalendarGroup extends Model
{
    use SoftDeletes;
    use UsesUlids;

    protected $fillable = [
        'name',
        'description',
        'legacy_team_id',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'is_everyone' => 'boolean',
        ];
    }

    public function legacyTeam(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'legacy_team_id');
    }

    public function directUsers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'calendar_group_user')
            ->withPivot(['assigned_by', 'created_at']);
    }

    public function teams(): BelongsToMany
    {
        return $this->belongsToMany(Team::class, 'calendar_group_team')
            ->withPivot(['assigned_by', 'created_at']);
    }

    public function calendarEvents(): BelongsToMany
    {
        return $this->belongsToMany(CalendarEvent::class, 'calendar_event_group')
            ->withPivot('created_at');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
