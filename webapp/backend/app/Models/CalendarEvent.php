<?php

namespace App\Models;

use App\Models\Concerns\UsesUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

final class CalendarEvent extends Model
{
    use SoftDeletes;
    use UsesUlids;

    public const AUDIENCE_SCOPE_EVERYONE = 'everyone';

    public const AUDIENCE_SCOPE_GROUPS = 'groups';

    protected $fillable = [
        'title',
        'type',
        'starts_at',
        'ends_at',
        'location_label',
        'description',
        'team_id',
        'audience_scope',
        'registration_enabled',
        'max_participants',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
            'registration_enabled' => 'boolean',
            'max_participants' => 'integer',
        ];
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function audienceGroups(): BelongsToMany
    {
        return $this->belongsToMany(CalendarGroup::class, 'calendar_event_group')
            ->withPivot('created_at');
    }

    public function registrations(): HasMany
    {
        return $this->hasMany(CalendarEventRegistration::class);
    }
}
