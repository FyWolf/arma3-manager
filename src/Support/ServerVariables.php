<?php

namespace FyWolf\Arma3Manager\Support;

use App\Models\EggVariable;
use App\Models\Server;
use App\Models\ServerVariable;

/**
 * Read and write a server's egg variables by *candidate name*.
 *
 * This exists because Arma eggs do not agree on anything. The mod list is
 * `MODS` on one egg, `MODIFICATIONS` on another, `WORKSHOP_MODS` on a third,
 * and the startup command interpolates whichever one that egg declared. So the
 * profile carries an ordered list of candidates and this class resolves it
 * against what the server actually has.
 *
 * ## Never read through `$server->variables`
 *
 * This is the important rule in this file, and breaking it cost six rounds of
 * wrong diagnosis.
 *
 * The panel's `Server::variables()` is a `hasMany` over `egg_variables` with a
 * left join onto `server_variables`, and the join is constrained inside a
 * closure that reads **`$this->id`**:
 *
 *     ->leftJoin('server_variables', function (JoinClause $join) {
 *         $join->on('server_variables.variable_id', 'egg_variables.id')
 *             ->where('server_variables.server_id', $this->id);
 *     });
 *
 * Under **lazy** loading that is the real model and `$this->id` is the server.
 * Under **eager** loading it is not: Laravel builds the relation with
 * `Relation::noConstraints(fn () => $model->newInstance()->$name())` — a fresh,
 * attribute-less instance — so `$this->id` is `null`, the join matches nothing,
 * and `server_value` comes back null for **every** variable on the server.
 *
 * `loadMissing('variables')` and `with('variables')` are both eager. So this
 * class used to call `loadMissing()` and then read `server_value`, which meant
 * every read returned "unset" no matter what was stored. The write was fine
 * throughout — it only needs `egg_variables.id`, which the join cannot corrupt
 * — so the panel showed the mods, the database held them, and every page in
 * this plugin showed an empty list.
 *
 * Both directions therefore go to the tables directly. It is two small queries
 * instead of one relation, and it cannot be broken by how somebody else in the
 * request happened to load the model.
 */
class ServerVariables
{
    /**
     * The value in force for the first candidate that exists on this server.
     *
     * Null means none of the candidates exist. An existing variable with no
     * `server_variables` row falls back to the egg's default, because that is
     * what the game is actually started with — reporting null there would say
     * "no such variable" about one that is simply unset.
     *
     * @param array<int, string> $candidates
     */
    public static function read(Server $server, array $candidates): ?string
    {
        $variable = self::resolve($server, $candidates);

        if ($variable === null) {
            return null;
        }

        $row = ServerVariable::query()
            ->where('server_id', $server->id)
            ->where('variable_id', $variable->id)
            ->first();

        return $row !== null
            ? (string) $row->variable_value
            : (string) ($variable->default_value ?? '');
    }

    /**
     * @param array<int, string> $candidates
     */
    public static function name(Server $server, array $candidates): ?string
    {
        return self::resolve($server, $candidates)?->env_variable;
    }

    /**
     * Write to the first candidate that exists.
     *
     * @param array<int, string> $candidates
     *
     * @return bool False when no candidate exists, so the caller can say which
     *              variable it was looking for rather than reporting success.
     */
    public static function write(Server $server, array $candidates, string $value): bool
    {
        $variable = self::resolve($server, $candidates);

        if ($variable === null) {
            return false;
        }

        ServerVariable::updateOrCreate(
            ['server_id' => $server->id, 'variable_id' => $variable->id],
            ['variable_value' => $value],
        );

        // Anything else in this request that already loaded the relation is
        // holding the old value. Harmless for our own reads, which no longer go
        // through it, but the page rendering afterwards may not be ours.
        $server->unsetRelation('variables');

        return true;
    }

    /**
     * The egg variable a candidate list resolves to.
     *
     * Queried straight off `egg_variables` rather than through the server's
     * relation — see the class note. Ordered by the candidate list rather than
     * by the table, so the profile's preference wins deterministically instead
     * of depending on row order.
     *
     * @param array<int, string> $candidates
     */
    private static function resolve(Server $server, array $candidates): ?EggVariable
    {
        if ($candidates === []) {
            return null;
        }

        $variables = EggVariable::query()
            ->where('egg_id', $server->egg_id)
            ->get(['id', 'env_variable', 'default_value']);

        foreach (array_map('strtoupper', $candidates) as $wanted) {
            foreach ($variables as $variable) {
                if (strtoupper((string) $variable->env_variable) === $wanted) {
                    return $variable;
                }
            }
        }

        return null;
    }

    /**
     * Every variable name the egg declares, for an error that can name them.
     *
     * @return array<int, string>
     */
    public static function declared(Server $server): array
    {
        $names = EggVariable::query()
            ->where('egg_id', $server->egg_id)
            ->pluck('env_variable')
            ->map(static fn ($name): string => (string) $name)
            ->all();

        sort($names);

        return $names;
    }

    /**
     * Every candidate name that is missing from this server, for an error that
     * tells the operator what to add rather than that something went wrong.
     *
     * @param array<int, string> $candidates
     *
     * @return array<int, string>
     */
    public static function missing(Server $server, array $candidates): array
    {
        return self::resolve($server, $candidates) === null ? array_values($candidates) : [];
    }
}
