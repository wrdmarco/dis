<?php

namespace App\Models;

use App\Models\Concerns\UsesUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class DeploymentStatusHistory extends Model
{
    use UsesUlids;

    protected $table = 'deployment_status_history';

    public $timestamps = false;

    protected $fillable = ['deployment_id', 'from_status', 'to_status', 'changed_by', 'changed_by_name', 'changed_by_email', 'reason', 'created_at'];

    protected function casts(): array
    {
        return ['created_at' => 'immutable_datetime'];
    }

    public function deployment(): BelongsTo
    {
        return $this->belongsTo(Deployment::class);
    }
}
