<?php

namespace FyWolf\Arma3Manager\Support;

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
 * ## Why writing a variable that does not exist is refused
 *
 * The tempting shortcut is to create the missing variable. It fails silently
 * and completely: a `ServerVariable` row is only ever read through the egg's
 * `startup` string, so a variable the egg never declared is interpolated
 * nowhere. The mods would be recorded in the panel, shown in the UI as
 * installed, and never passed to the game. Refusing gives the operator an error
 * naming the variable, which is fixable in thirty seconds by editing the egg.
 *
 * ## Why the value is written through ServerVariable and not through the egg
 *
 * `$server->variables` is a *read* projection: it selects egg_variables joined
 * against server_variables and aliases `variable_value` to `server_value`.
 * Assigning to it writes to the egg and changes the value for every server on
 * that egg — one customer's mod list landing on every other customer's server.
 * The write always goes to the pivot row, keyed on both ids.
 */
class ServerVariables
{
    /**
     * The value of the first candidate variable that exists on this server.
     *
     * Null means none of the candidates exist. An existing variable with an
     * empty value returns the empty string, which is a different answer and one
     * the caller cares about: "no mod list variable" is a misconfigured egg,
     * "an empty mod list" is a server with no mods.
     *
     * @param array<int, string> $candidates
     */
    public static function read(Server $server, array $candidates): ?string
    {
        $variable = self::resolve($server, $candidates);

        return $variable === null ? null : (string) ($variable->server_value ?? '');
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

        // The relation was already loaded to resolve the name, and it caches the
        // old value. Anything reading it again in this request — the page that
        // is about to re-render, for instance — would show what was there before
        // the write.
        $server->unsetRelation('variables');

        return true;
    }

    /**
     * @param array<int, string> $candidates
     */
    private static function resolve(Server $server, array $candidates): mixed
    {
        if ($candidates === []) {
            return null;
        }

        $server->loadMissing('variables');

        $wanted = array_map('strtoupper', $candidates);

        // Ordered by the candidate list, not by the server's own ordering: the
        // profile lists them most-specific first and the first match must win
        // deterministically. Iterating the server's variables instead would
        // make the answer depend on egg_variables row order.
        foreach ($wanted as $name) {
            foreach ($server->variables as $variable) {
                if (strtoupper((string) $variable->env_variable) === $name) {
                    return $variable;
                }
            }
        }

        return null;
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
