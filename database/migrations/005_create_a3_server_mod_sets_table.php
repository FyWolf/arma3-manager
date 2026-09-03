<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which mod sets a given server has been granted.
 *
 * Written by the billing service through this plugin's API, so a set can be
 * something a customer buys rather than something every customer has. A public
 * set needs no row here; this table is only ever about the non-public ones.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('a3_server_mod_sets', function (Blueprint $table) {
            $table->id();

            $table->unsignedInteger('server_id');
            $table->foreign('server_id')->references('id')->on('servers')->cascadeOnDelete();

            $table->unsignedInteger('mod_set_id');
            $table->foreign('mod_set_id')->references('id')->on('a3_mod_sets')->cascadeOnDelete();

            // Free text from the caller — an order number, usually. It is the
            // only thing linking a grant back to the thing that paid for it.
            $table->string('source')->nullable();

            $table->timestamp('granted_at')->nullable();

            $table->timestamps();

            // A grant is a fact, not a quantity: granting twice must not create
            // two rows, or revoking once would leave the set still granted.
            $table->unique(['server_id', 'mod_set_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('a3_server_mod_sets');
    }
};
