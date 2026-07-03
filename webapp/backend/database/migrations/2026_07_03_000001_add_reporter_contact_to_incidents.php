<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('incidents', function (Blueprint $table): void {
            $table->string('reporter_name')->nullable()->after('description');
            $table->string('reporter_phone')->nullable()->after('reporter_name');
            $table->string('requesting_organization')->nullable()->after('reporter_phone');
            $table->string('requesting_unit')->nullable()->after('requesting_organization');
            $table->string('on_scene_contact_name')->nullable()->after('requesting_unit');
            $table->string('on_scene_contact_phone')->nullable()->after('on_scene_contact_name');
            $table->string('on_scene_contact_role')->nullable()->after('on_scene_contact_phone');
            $table->text('operational_objective')->nullable()->after('on_scene_contact_role');
            $table->text('required_resources')->nullable()->after('operational_objective');
            $table->text('required_qualification')->nullable()->after('required_resources');
        });
    }

    public function down(): void
    {
        Schema::table('incidents', function (Blueprint $table): void {
            $table->dropColumn([
                'reporter_name',
                'reporter_phone',
                'requesting_organization',
                'requesting_unit',
                'on_scene_contact_name',
                'on_scene_contact_phone',
                'on_scene_contact_role',
                'operational_objective',
                'required_resources',
                'required_qualification',
            ]);
        });
    }
};
