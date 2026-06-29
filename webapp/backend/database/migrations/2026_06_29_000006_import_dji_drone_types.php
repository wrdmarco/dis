<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration {
    /**
     * @var array<int, array{model: string, thermal: bool, spotlight: bool, speaker: bool, notes: string}>
     */
    private array $droneTypes = [
        ['model' => 'DJI Lito X1', 'thermal' => false, 'spotlight' => false, 'speaker' => false, 'notes' => 'Consumer camera drone. No integrated thermal camera, spotlight or speaker listed by DJI.'],
        ['model' => 'DJI Lito 1', 'thermal' => false, 'spotlight' => false, 'speaker' => false, 'notes' => 'Consumer camera drone. No integrated thermal camera, spotlight or speaker listed by DJI.'],
        ['model' => 'DJI Flip', 'thermal' => false, 'spotlight' => false, 'speaker' => false, 'notes' => 'Consumer camera drone with 1/1.3-inch camera. No integrated thermal camera, spotlight or speaker listed by DJI.'],
        ['model' => 'DJI Neo', 'thermal' => false, 'spotlight' => false, 'speaker' => false, 'notes' => 'Compact consumer camera drone. No integrated thermal camera, spotlight or speaker listed by DJI.'],
        ['model' => 'DJI Mini 2 SE', 'thermal' => false, 'spotlight' => false, 'speaker' => false, 'notes' => 'Mini consumer camera drone. No integrated thermal camera, spotlight or speaker listed by DJI.'],
        ['model' => 'DJI Mini 3', 'thermal' => false, 'spotlight' => false, 'speaker' => false, 'notes' => 'Mini consumer camera drone. No integrated thermal camera, spotlight or speaker listed by DJI.'],
        ['model' => 'DJI Mini 3 Pro', 'thermal' => false, 'spotlight' => false, 'speaker' => false, 'notes' => 'Mini consumer camera drone. No integrated thermal camera, spotlight or speaker listed by DJI.'],
        ['model' => 'DJI Mini 4K', 'thermal' => false, 'spotlight' => false, 'speaker' => false, 'notes' => 'Mini consumer camera drone with 4K camera. No integrated thermal camera, spotlight or speaker listed by DJI.'],
        ['model' => 'DJI Mini 4 Pro', 'thermal' => false, 'spotlight' => false, 'speaker' => false, 'notes' => 'Mini consumer camera drone. No integrated thermal camera, spotlight or speaker listed by DJI.'],
        ['model' => 'DJI Mini 5 Pro', 'thermal' => false, 'spotlight' => false, 'speaker' => false, 'notes' => 'Mini consumer camera drone. No integrated thermal camera, spotlight or speaker listed by DJI.'],
        ['model' => 'DJI Air 2S', 'thermal' => false, 'spotlight' => false, 'speaker' => false, 'notes' => 'Air-series consumer camera drone. No integrated thermal camera, spotlight or speaker listed by DJI.'],
        ['model' => 'DJI Air 3', 'thermal' => false, 'spotlight' => false, 'speaker' => false, 'notes' => 'Air-series dual-camera drone. No integrated thermal camera, spotlight or speaker listed by DJI.'],
        ['model' => 'DJI Air 3S', 'thermal' => false, 'spotlight' => false, 'speaker' => false, 'notes' => 'Air-series dual-camera drone. No integrated thermal camera, spotlight or speaker listed by DJI.'],
        ['model' => 'DJI Mavic Air 2', 'thermal' => false, 'spotlight' => false, 'speaker' => false, 'notes' => 'Mavic Air consumer camera drone. No integrated thermal camera, spotlight or speaker listed by DJI.'],
        ['model' => 'DJI Mavic 2 Pro', 'thermal' => false, 'spotlight' => false, 'speaker' => false, 'notes' => 'Mavic consumer camera drone. No integrated thermal camera, spotlight or speaker listed by DJI.'],
        ['model' => 'DJI Mavic 2 Zoom', 'thermal' => false, 'spotlight' => false, 'speaker' => false, 'notes' => 'Mavic consumer camera drone. No integrated thermal camera, spotlight or speaker listed by DJI.'],
        ['model' => 'DJI Mavic 3', 'thermal' => false, 'spotlight' => false, 'speaker' => false, 'notes' => 'Mavic consumer camera drone. No integrated thermal camera, spotlight or speaker listed by DJI.'],
        ['model' => 'DJI Mavic 3 Classic', 'thermal' => false, 'spotlight' => false, 'speaker' => false, 'notes' => 'Mavic consumer camera drone. No integrated thermal camera, spotlight or speaker listed by DJI.'],
        ['model' => 'DJI Mavic 3 Pro', 'thermal' => false, 'spotlight' => false, 'speaker' => false, 'notes' => 'Mavic consumer camera drone with triple-camera payload. No integrated thermal camera, spotlight or speaker listed by DJI.'],
        ['model' => 'DJI Mavic 4 Pro', 'thermal' => false, 'spotlight' => false, 'speaker' => false, 'notes' => 'Mavic consumer/pro camera drone. No integrated thermal camera, spotlight or speaker listed by DJI.'],
        ['model' => 'DJI Avata', 'thermal' => false, 'spotlight' => false, 'speaker' => false, 'notes' => 'FPV camera drone. No integrated thermal camera, spotlight or speaker listed by DJI.'],
        ['model' => 'DJI Avata 2', 'thermal' => false, 'spotlight' => false, 'speaker' => false, 'notes' => 'FPV camera drone. No integrated thermal camera, spotlight or speaker listed by DJI.'],
        ['model' => 'DJI FPV', 'thermal' => false, 'spotlight' => false, 'speaker' => false, 'notes' => 'FPV camera drone. No integrated thermal camera, spotlight or speaker listed by DJI.'],
        ['model' => 'DJI Inspire 2', 'thermal' => false, 'spotlight' => false, 'speaker' => false, 'notes' => 'Professional aerial camera platform. Thermal, spotlight and speaker are not standard DJI capabilities for this type.'],
        ['model' => 'DJI Inspire 3', 'thermal' => false, 'spotlight' => false, 'speaker' => false, 'notes' => 'Professional aerial camera platform. Thermal, spotlight and speaker are not standard DJI capabilities for this type.'],
        ['model' => 'DJI Phantom 4 Pro V2.0', 'thermal' => false, 'spotlight' => false, 'speaker' => false, 'notes' => 'Phantom camera drone. No integrated thermal camera, spotlight or speaker listed by DJI.'],
        ['model' => 'DJI Mavic 2 Enterprise', 'thermal' => false, 'spotlight' => true, 'speaker' => true, 'notes' => 'Enterprise drone with official M2E Spotlight and M2E Speaker accessories.'],
        ['model' => 'DJI Mavic 2 Enterprise Dual', 'thermal' => true, 'spotlight' => true, 'speaker' => true, 'notes' => 'Enterprise drone with thermal imaging and official M2E Spotlight and M2E Speaker accessories.'],
        ['model' => 'DJI Mavic 2 Enterprise Advanced', 'thermal' => true, 'spotlight' => true, 'speaker' => true, 'notes' => 'Enterprise drone with thermal imaging and official M2E Spotlight and M2E Speaker accessories.'],
        ['model' => 'DJI Mavic 3 Enterprise', 'thermal' => false, 'spotlight' => false, 'speaker' => true, 'notes' => 'Enterprise mapping/inspection drone. DJI lists a thermal version separately; speaker support is available via enterprise accessory.'],
        ['model' => 'DJI Mavic 3 Thermal', 'thermal' => true, 'spotlight' => false, 'speaker' => true, 'notes' => 'Enterprise thermal version for firefighting, search and rescue, inspection and night operations; speaker support is available via enterprise accessory.'],
        ['model' => 'DJI Mavic 3 Multispectral', 'thermal' => false, 'spotlight' => false, 'speaker' => false, 'notes' => 'Enterprise multispectral mapping drone. No thermal camera, spotlight or speaker listed as standard DJI capability.'],
        ['model' => 'DJI Matrice 4E', 'thermal' => false, 'spotlight' => true, 'speaker' => true, 'notes' => 'Matrice 4 mapping version with laser rangefinder; compatible with DJI AL1 Spotlight and AS1 Speaker accessories.'],
        ['model' => 'DJI Matrice 4T', 'thermal' => true, 'spotlight' => true, 'speaker' => true, 'notes' => 'Matrice 4 thermal version with thermal camera, NIR auxiliary light and DJI AL1 Spotlight / AS1 Speaker accessory support.'],
        ['model' => 'DJI Matrice 4D', 'thermal' => false, 'spotlight' => true, 'speaker' => true, 'notes' => 'Dock 3 aircraft variant; compatible with Matrice 4 accessory ecosystem where supported.'],
        ['model' => 'DJI Matrice 4TD', 'thermal' => true, 'spotlight' => true, 'speaker' => true, 'notes' => 'Dock 3 thermal aircraft variant with thermal camera; compatible with Matrice 4 accessory ecosystem where supported.'],
        ['model' => 'DJI Matrice 30', 'thermal' => false, 'spotlight' => false, 'speaker' => false, 'notes' => 'Integrated enterprise payload platform. Thermal camera is available on Matrice 30T, not this type.'],
        ['model' => 'DJI Matrice 30T', 'thermal' => true, 'spotlight' => false, 'speaker' => false, 'notes' => 'Enterprise drone with integrated thermal camera.'],
        ['model' => 'DJI Matrice 300 RTK', 'thermal' => true, 'spotlight' => true, 'speaker' => true, 'notes' => 'Multi-payload enterprise platform; thermal depends on payload such as Zenmuse H20T/H30T. Compatible with Zenmuse S1 Spotlight and V1 Speaker.'],
        ['model' => 'DJI Matrice 350 RTK', 'thermal' => true, 'spotlight' => true, 'speaker' => true, 'notes' => 'Multi-payload enterprise platform; thermal depends on payload such as Zenmuse H20T/H30T. Compatible with Zenmuse S1 Spotlight and V1 Speaker.'],
        ['model' => 'DJI Matrice 400', 'thermal' => true, 'spotlight' => true, 'speaker' => true, 'notes' => 'Enterprise platform for emergency response and inspection; supports visible/thermal payloads and Zenmuse S1 Spotlight / V1 Speaker.'],
        ['model' => 'DJI Agras T10', 'thermal' => false, 'spotlight' => false, 'speaker' => false, 'notes' => 'Agricultural spraying drone. Operational thermal, spotlight and speaker capabilities are not listed as standard DJI capability.'],
        ['model' => 'DJI Agras T20', 'thermal' => false, 'spotlight' => false, 'speaker' => false, 'notes' => 'Agricultural spraying drone. Operational thermal, spotlight and speaker capabilities are not listed as standard DJI capability.'],
        ['model' => 'DJI Agras T25', 'thermal' => false, 'spotlight' => false, 'speaker' => false, 'notes' => 'Agricultural spraying drone. Operational thermal, spotlight and speaker capabilities are not listed as standard DJI capability.'],
        ['model' => 'DJI Agras T30', 'thermal' => false, 'spotlight' => false, 'speaker' => false, 'notes' => 'Agricultural spraying drone. Operational thermal, spotlight and speaker capabilities are not listed as standard DJI capability.'],
        ['model' => 'DJI Agras T40', 'thermal' => false, 'spotlight' => false, 'speaker' => false, 'notes' => 'Agricultural spraying drone. Operational thermal, spotlight and speaker capabilities are not listed as standard DJI capability.'],
        ['model' => 'DJI Agras T50', 'thermal' => false, 'spotlight' => false, 'speaker' => false, 'notes' => 'Agricultural spraying drone. Operational thermal, spotlight and speaker capabilities are not listed as standard DJI capability.'],
        ['model' => 'DJI Agras T100', 'thermal' => false, 'spotlight' => false, 'speaker' => false, 'notes' => 'Agricultural spraying drone. Operational thermal, spotlight and speaker capabilities are not listed as standard DJI capability.'],
    ];

    public function up(): void
    {
        $now = now();

        foreach ($this->droneTypes as $type) {
            $existing = DB::table('drone_types')->where('model', $type['model'])->first();
            if ($existing === null) {
                DB::table('drone_types')->insert([
                    'id' => (string) Str::ulid(),
                    'manufacturer' => 'DJI',
                    'model' => $type['model'],
                    'has_thermal' => $type['thermal'],
                    'has_spotlight' => $type['spotlight'],
                    'has_speaker' => $type['speaker'],
                    'is_active' => true,
                    'notes' => $type['notes'],
                    'deleted_at' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                continue;
            }

            DB::table('drone_types')->where('model', $type['model'])->update([
                    'manufacturer' => 'DJI',
                    'has_thermal' => $type['thermal'],
                    'has_spotlight' => $type['spotlight'],
                    'has_speaker' => $type['speaker'],
                    'is_active' => true,
                    'notes' => $type['notes'],
                    'deleted_at' => null,
                    'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        DB::table('drone_types')
            ->where('manufacturer', 'DJI')
            ->whereIn('model', array_column($this->droneTypes, 'model'))
            ->delete();
    }
};
