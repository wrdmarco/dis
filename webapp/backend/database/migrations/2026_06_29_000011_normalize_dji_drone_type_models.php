<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        $now = now();
        $droneTypes = DB::table('drone_types')
            ->where('manufacturer', 'DJI')
            ->where('model', 'like', 'DJI %')
            ->get();

        foreach ($droneTypes as $droneType) {
            $model = preg_replace('/^DJI\s+/i', '', (string) $droneType->model) ?? (string) $droneType->model;

            if ($model === $droneType->model || $model === '') {
                continue;
            }

            $existing = DB::table('drone_types')
                ->where('manufacturer', 'DJI')
                ->where('model', $model)
                ->where('id', '!=', $droneType->id)
                ->first();

            if ($existing !== null) {
                DB::table('assets')
                    ->where('drone_type_id', $droneType->id)
                    ->update(['drone_type_id' => $existing->id]);

                DB::table('drone_types')->where('id', $droneType->id)->delete();
                continue;
            }

            DB::table('drone_types')->where('id', $droneType->id)->update([
                'model' => $model,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        // Data normalization only. Re-adding duplicate manufacturer prefixes would be unsafe.
    }
};
