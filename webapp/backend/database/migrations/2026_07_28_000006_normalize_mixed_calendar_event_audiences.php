<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            $everyoneGroupIds = DB::table('calendar_groups')
                ->where('is_everyone', true)
                ->pluck('id')
                ->map(static fn (mixed $id): string => (string) $id)
                ->all();

            if ($everyoneGroupIds === []) {
                return;
            }

            $mixedEventIds = DB::table('calendar_event_group as everyone_link')
                ->whereIn('everyone_link.calendar_group_id', $everyoneGroupIds)
                ->whereExists(function ($query): void {
                    $query
                        ->selectRaw('1')
                        ->from('calendar_event_group as specific_link')
                        ->join(
                            'calendar_groups as specific_group',
                            'specific_group.id',
                            '=',
                            'specific_link.calendar_group_id',
                        )
                        ->whereColumn(
                            'specific_link.calendar_event_id',
                            'everyone_link.calendar_event_id',
                        )
                        ->where('specific_group.is_everyone', false);
                })
                ->distinct()
                ->pluck('everyone_link.calendar_event_id')
                ->map(static fn (mixed $id): string => (string) $id)
                ->all();

            if ($mixedEventIds === []) {
                return;
            }

            DB::table('calendar_events')
                ->whereIn('id', $mixedEventIds)
                ->update([
                    'audience_scope' => 'groups',
                    'updated_at' => now(),
                ]);

            DB::table('calendar_event_group')
                ->whereIn('calendar_event_id', $mixedEventIds)
                ->whereIn('calendar_group_id', $everyoneGroupIds)
                ->delete();
        });
    }

    public function down(): void
    {
        // The removed broad audience was ambiguous and must not be recreated.
    }
};
