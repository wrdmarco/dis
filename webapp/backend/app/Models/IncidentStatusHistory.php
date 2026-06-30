<?php

namespace App\Models;

use App\Models\Concerns\UsesUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class IncidentStatusHistory extends Model
{
    use UsesUlids;

    protected $table = 'incident_status_history';

    public $timestamps = false;

    protected $fillable = ['incident_id', 'from_status', 'to_status', 'changed_by', 'changed_by_name', 'changed_by_email', 'reason', 'created_at'];

    protected function casts(): array
    {
        return ['created_at' => 'immutable_datetime'];
    }

    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
    }
}
