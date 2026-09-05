<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Drop the mod set tables. The feature is gone.
 *
 * Mod sets were an admin-curated catalogue a customer could install in one
 * action, optionally granted per server by a billing service over this plugin's
 * API. Nobody used it, and it carried a queue worker, an hourly reaper, four API
 * endpoints, a policy and two admin screens with it.
 *
 * ## Why a drop migration rather than deleting the create ones
 *
 * The three `create` migrations are deleted in the same change, and on a fresh
 * install that is the whole story — the `dropIfExists` calls below find nothing
 * and do nothing.
 *
 * On a panel that already ran them it is not. Deleting a migration file does not
 * un-run it: the rows stay in the migrations table and the tables stay in the
 * database, unreferenced by any code, until someone wonders what `a3_mod_sets`
 * is and whether it is safe to touch. Uninstalling the plugin would not clear
 * them either, because a rollback can only reverse migrations it can still see.
 *
 * ## Order matters
 *
 * `a3_mod_set_installs` and `a3_server_mod_sets` both carry a foreign key onto
 * `a3_mod_sets`, so the parent goes last. Dropping it first fails on MySQL — and
 * *succeeds* on SQLite, which is exactly the split this project keeps being
 * caught by: local and CI are SQLite, production is MySQL, so the wrong order
 * would pass every test and fail on the one machine that matters.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('a3_mod_set_installs');
        Schema::dropIfExists('a3_server_mod_sets');
        Schema::dropIfExists('a3_mod_sets');
    }

    /**
     * Deliberately not reversible.
     *
     * Recreating three empty tables for code that no longer exists would leave a
     * panel in a state no version of this plugin ever shipped: schema present,
     * models absent. If the feature is ever wanted again it comes back with its
     * own migration, written against whatever it looks like then.
     */
    public function down(): void
    {
        // Nothing to do.
    }
};
