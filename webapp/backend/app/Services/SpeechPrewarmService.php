<?php

namespace App\Services;

use App\Jobs\PrewarmIncidentSpeech;
use App\Models\SystemSetting;
use Illuminate\Support\Facades\DB;

final class SpeechPrewarmService
{
    public function queueAfterCommit(string $incidentId): void
    {
        if (! SystemSetting::boolean('speech.pre_generate_on_save', true)
            || ! SystemSetting::boolean('speech.enabled', false)) {
            return;
        }
        $dispatch = fn () => PrewarmIncidentSpeech::dispatch($incidentId);
        if (DB::transactionLevel() > 0) {
            DB::afterCommit($dispatch);
        } else {
            $dispatch();
        }
    }
}
