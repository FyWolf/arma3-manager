<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Egg -> profile mapping.
 *
 * Same shape as the panel's own `egg_game_query` pivot from the player-counter
 * plugin: a unique egg_id, so an egg has at most one profile, and cascading
 * deletes on both sides.
 *
 * Note that uninstalling the plugin rolls these migrations back, which drops
 * every mapping an administrator made. That is how PluginService::uninstallPlugin
 * works (it calls $migrator->reset()) and there is no way for a plugin to opt
 * out — hence the export action on the admin resource.
 */
return new class extends Migration
{
    public function up(): void
    {
        // No surrogate id: hasOneThrough addresses this table purely by
        // egg_id / a3_capability_profile_id, exactly as egg_game_query does.
        Schema::create('egg_a3_capability_profile', function (Blueprint $table) {
            $table->unsignedInteger('egg_id');
            $table->foreign('egg_id')->references('id')->on('eggs')->cascadeOnDelete();

            $table->unsignedInteger('a3_capability_profile_id');
            $table->foreign('a3_capability_profile_id')->references('id')->on('a3_capability_profiles')->cascadeOnDelete();

            // One profile per egg.
            $table->unique('egg_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('egg_a3_capability_profile');
    }
};
