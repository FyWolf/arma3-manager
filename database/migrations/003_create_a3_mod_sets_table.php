<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The admin-curated catalogue: a named collection of Workshop ids a customer
 * installs in one action.
 *
 * This is where an Arma host's real product lives. "ACE, CBA and TFAR, in this
 * order" is a support answer given a hundred times, and a set is that answer
 * written down once — including the order, which a customer cannot be expected
 * to know and which decides whether the server boots at all.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('a3_mod_sets', function (Blueprint $table) {
            $table->increments('id');

            $table->string('key')->unique();
            $table->string('name');
            $table->text('description')->nullable();

            // Ordered list of {id, name} maps. Ordered, because Arma merges
            // addons in the order given and a set that loads ACE before CBA is
            // a set that does not work. Stored as JSON rather than a child
            // table for exactly that reason: a row order nobody can accidentally
            // re-sort with an ORDER BY.
            $table->json('mods');

            // Server-only entries, loaded through -serverMod=.
            $table->json('server_mods')->nullable();

            // Whether any customer may install it, or only servers the billing
            // service has granted it to. Default false: a set an admin is still
            // assembling must not be installable the moment it is saved.
            $table->boolean('is_public')->default(false);

            // Hidden without deleting, so a set withdrawn from sale stops being
            // offered while the servers already running it keep working.
            $table->boolean('is_enabled')->default(true);

            $table->unsignedInteger('sort')->default(0);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('a3_mod_sets');
    }
};
