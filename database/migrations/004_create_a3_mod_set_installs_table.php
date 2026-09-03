<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One attempt to install a mod set onto one server.
 *
 * Kept after it finishes. A failed install is the thing support is asked about,
 * and "it did not work" with no row to look at is the state this table exists
 * to prevent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('a3_mod_set_installs', function (Blueprint $table) {
            $table->id();

            $table->unsignedInteger('server_id');
            $table->foreign('server_id')->references('id')->on('servers')->cascadeOnDelete();

            // nullOnDelete, not cascade: deleting a set from the catalogue must
            // not erase the history of it having been installed. The name is
            // frozen below so the row still reads sensibly afterwards.
            $table->unsignedInteger('mod_set_id')->nullable();
            $table->foreign('mod_set_id')->references('id')->on('a3_mod_sets')->nullOnDelete();

            $table->string('mod_set_name');

            $table->string('state')->default('queued');
            $table->string('error')->nullable();

            // Progress, as resolved/total workshop items. Both nullable because
            // the total is unknown until dependency resolution has finished.
            $table->unsignedInteger('resolved')->nullable();
            $table->unsignedInteger('total')->nullable();

            // The load order this install wrote, frozen. Lets a later install
            // say what it changed, and lets support see what the server was
            // actually told to load rather than what the set says today.
            $table->json('applied_mods')->nullable();

            $table->unsignedInteger('user_id')->nullable();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();

            // Bumped on every state change. The stale-install reaper compares
            // against this rather than updated_at, which any unrelated write
            // would move.
            $table->timestamp('heartbeat_at')->nullable();

            $table->timestamps();

            $table->index(['server_id', 'state']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('a3_mod_set_installs');
    }
};
