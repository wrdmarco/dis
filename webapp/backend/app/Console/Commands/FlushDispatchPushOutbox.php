<?php

namespace App\Console\Commands;

use App\Services\DispatchPushOutboxService;
use Illuminate\Console\Command;

final class FlushDispatchPushOutbox extends Command
{
    protected $signature = 'dis:flush-dispatch-push-outbox {--limit=100}';

    protected $description = 'Queue durable pending dispatch alarm notifications.';

    public function handle(DispatchPushOutboxService $outbox): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => 500],
        ]);
        if ($limit === false) {
            $this->error('The limit must be an integer between 1 and 500.');

            return self::INVALID;
        }

        $this->info(json_encode($outbox->flushPending($limit), JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
