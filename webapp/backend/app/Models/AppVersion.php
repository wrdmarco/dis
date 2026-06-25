<?php

namespace App\Models;

use App\Models\Concerns\UsesUlids;
use Illuminate\Database\Eloquent\Model;

final class AppVersion extends Model
{
    use UsesUlids;

    protected $fillable = ['platform', 'version_name', 'version_code', 'status', 'artifact_sha256', 'download_url', 'release_notes', 'created_by'];
}

