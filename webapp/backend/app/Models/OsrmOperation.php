<?php

namespace App\Models;

use App\Models\Concerns\UsesUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class OsrmOperation extends Model
{
    use UsesUlids;

    public const ACTIVE_KEY = 'osrm';

    public const ACTION_INSTALL_ACTIVATE = 'install_activate';

    public const ACTION_UPDATE = 'update';

    public const STATE_QUEUED = 'queued';

    public const STATE_RUNNING = 'running';

    public const STATE_SUCCEEDED = 'succeeded';

    public const STATE_FAILED = 'failed';

    /** @var list<string> */
    public const STAGES = [
        'validating',
        'downloading',
        'installing_package',
        'provisioning',
        'extracting',
        'partitioning',
        'customizing',
        'activating',
        'verifying',
        'configuring',
        'completed',
    ];

    protected $fillable = [
        'request_id',
        'action',
        'state',
        'stage',
        'active_key',
        'message',
        'progress_percent',
        'source_url',
        'source_sha256',
        'health_longitude',
        'health_latitude',
        'actor_id',
        'actor_id_snapshot',
        'exit_code',
        'started_at',
        'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'progress_percent' => 'integer',
            'health_longitude' => 'decimal:7',
            'health_latitude' => 'decimal:7',
            'exit_code' => 'integer',
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
        ];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function isActive(): bool
    {
        return in_array($this->state, [self::STATE_QUEUED, self::STATE_RUNNING], true);
    }
}
