<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Launcher presets a customer has uploaded, kept per server.
 *
 * Importing used to merge a preset into the load order and throw the file away,
 * which loses the thing a customer actually has: a *named modset* they can
 * switch between. A unit that plays two campaigns has two presets and wants to
 * pick one, not to rebuild the load order by hand each time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('a3_presets', function (Blueprint $table) {
            $table->id();

            $table->unsignedInteger('server_id');
            $table->foreign('server_id')->references('id')->on('servers')->cascadeOnDelete();

            $table->string('name');

            // Ordered mod-list entries exactly as they will be written —
            // `@450814997`, a CDLC code, a hand-uploaded folder. Stored as the
            // entries rather than as bare ids so a preset carrying a CDLC or a
            // local folder survives a round trip; the list the egg reads is
            // mixed, and a preset that could only hold Workshop items would
            // quietly drop the rest.
            $table->json('entries');

            // When this preset was last written to the load order. Null means
            // it has been saved and never applied.
            //
            // Deliberately *not* an `is_active` flag: the customer can edit the
            // load order on the Mods page afterwards, and a flag would still
            // claim the preset is active while the server runs something else.
            // Whether a preset is active is decided by comparing its entries to
            // the current load order, so it stops being active the moment that
            // stops being true.
            $table->timestamp('applied_at')->nullable();

            $table->timestamps();

            // One name per server. Re-importing a preset of the same name
            // updates it rather than growing a list of near-duplicates, which
            // is what happens when somebody exports from the launcher twice.
            $table->unique(['server_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('a3_presets');
    }
};
