<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('a3_capability_profiles', function (Blueprint $table) {
            // increments(), not id() — matches the eggs table and the
            // egg_game_query precedent, so the pivot's foreign key is a plain
            // unsignedInteger on both sides.
            $table->increments('id');

            $table->string('name')->unique();

            // `arma3` or `arma3-headless`. Nullable because an administrator may
            // build a profile for a community build that is neither.
            $table->string('flavour')->nullable();

            // Which pages this egg gets. Array of Capability values.
            $table->json('capabilities');

            // Where @Mod folders live. Null means no mod management at all.
            $table->string('mods_dir')->nullable()->default('mods');

            // Null means this profile has no -serverMod= concept: a headless
            // client loads what the server loads and nothing server-only.
            $table->string('servermods_dir')->nullable();

            // Null means no missions page — correct for a headless client, which
            // joins a mission rather than hosting one.
            $table->string('missions_dir')->nullable();

            $table->string('profiles_dir')->nullable()->default('profiles');
            $table->string('server_binary')->nullable();

            // The config files the Configuration page offers, in order. An empty
            // array means the page does not render.
            $table->json('config_files')->nullable();

            // Ordered candidate env_variable names; the first that exists on the
            // server wins. Eggs disagree (MODS vs MODIFICATIONS vs
            // WORKSHOP_MODS) and guessing wrong writes a variable nothing reads
            // — which fails completely silently, the mods simply never load.
            $table->json('mod_list_variables')->nullable();
            $table->json('servermod_list_variables')->nullable();
            $table->json('parameter_variables')->nullable();
            $table->json('headless_variables')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('a3_capability_profiles');
    }
};
