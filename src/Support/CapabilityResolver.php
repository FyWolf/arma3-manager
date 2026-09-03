<?php

namespace FyWolf\Arma3Manager\Support;

use App\Models\Egg;
use App\Models\Server;
use FyWolf\Arma3Manager\Enums\ServerFlavour;
use FyWolf\Arma3Manager\Models\CapabilityProfile;
use Illuminate\Support\Str;

/**
 * Decides what a given server is allowed to do.
 *
 * Every page in this plugin asks this one question, and a null answer means the
 * page does not exist for that server — no navigation entry, no empty state, no
 * error. An egg nobody has taught us about is invisible rather than broken.
 *
 * Resolution order:
 *
 *   1. explicit   — a profile an admin mapped to this egg
 *   2. inherited  — the profile mapped to the egg's parent (config_from)
 *   3. heuristic  — guessed from tags, the egg name and startup variables
 *   4. null
 *
 * ## Why the Minecraft version of this cannot be copied verbatim
 *
 * minecraft-manager can lean on egg *features*: `eula` and `java_version` are
 * ids the panel genuinely registers, and together with the `minecraft` tag they
 * are a reliable "this is Minecraft Java" signal.
 *
 * Arma has no equivalent. The only feature id in that family is
 * `steam_disk_space`, which every SteamCMD game carries — it identifies Steam,
 * not Arma, and gating on it would light this plugin up on every Rust, ARK and
 * CS2 server on the node. So the signals here are the egg's tags, its name, and
 * above all the **Steam app id in its variables**: 233780 is the Arma 3
 * dedicated server and an egg declaring it is Arma 3 whatever it calls itself.
 */
class CapabilityResolver
{
    /** @var array<int, ?ResolvedProfile> */
    private array $memo = [];

    public function for(Server $server): ?ResolvedProfile
    {
        $eggId = $server->egg_id;

        if (array_key_exists($eggId, $this->memo)) {
            return $this->memo[$eggId];
        }

        return $this->memo[$eggId] = $this->resolve($server);
    }

    private function resolve(Server $server): ?ResolvedProfile
    {
        $server->loadMissing('egg');

        $egg = $server->egg;

        if (! $egg) {
            return null;
        }

        // 1. Explicit mapping. The admin always wins.
        if ($profile = $this->profileFor($egg)) {
            return ResolvedProfile::fromModel($profile, ResolvedProfile::SOURCE_EXPLICIT);
        }

        // 2. The parent egg's mapping. Covers the very common case of a
        //    customised copy of a stock egg, mirroring how the panel itself
        //    falls back for features, config files and the file denylist.
        if ($egg->config_from) {
            $parent = $egg->configFrom;

            if ($parent && ($profile = $this->profileFor($parent))) {
                return ResolvedProfile::fromModel($profile, ResolvedProfile::SOURCE_INHERITED, $parent->name);
            }
        }

        // 3. Guess.
        if (! config('arma3-manager.heuristics.enabled', true)) {
            return null;
        }

        if (! $this->looksLikeArma($egg, $server)) {
            return null;
        }

        $flavour = $this->detectFlavour($egg);

        return $flavour ? ResolvedProfile::fromDefaults($flavour) : null;
    }

    /**
     * Deliberately queried through this plugin's own belongsToMany rather than
     * through the `a3CapabilityProfile` relation the service provider grafts
     * onto the core Egg model. The graft exists for other code's convenience
     * and for eager loading; resolution itself must not depend on it having
     * booted, because this runs inside every page's canAccess().
     */
    private function profileFor(Egg $egg): ?CapabilityProfile
    {
        return CapabilityProfile::query()
            ->whereHas('eggs', fn ($query) => $query->whereKey($egg->id))
            ->first();
    }

    /**
     * Is this an Arma 3 egg at all?
     *
     * A tag, the egg's own name, or the Arma 3 app id in a variable. The app id
     * is checked last but is the strongest of the three, and is the only one
     * that survives an egg being renamed by whoever imported it.
     */
    private function looksLikeArma(Egg $egg, ?Server $server = null): bool
    {
        $tags = array_map('strtolower', $egg->tags ?? []);

        foreach ((array) config('arma3-manager.heuristics.tags', []) as $tag) {
            if (in_array(strtolower((string) $tag), $tags, true)) {
                return true;
            }
        }

        if (preg_match('/\barma\s*3?\b/i', (string) $egg->name) === 1) {
            return true;
        }

        return $this->declaresArmaAppId($egg, $server);
    }

    /**
     * Whether an Arma app id appears in the egg's or the server's variables.
     *
     * Both are checked because they hold different things: the egg's variable
     * rows carry the *default* value, and the server's carry what the customer
     * or the provisioner actually set. An egg whose default app id was left
     * blank is still Arma once a server has filled it in.
     */
    private function declaresArmaAppId(Egg $egg, ?Server $server = null): bool
    {
        $names = array_map('strtoupper', (array) config('arma3-manager.heuristics.app_id_variables', []));
        $wanted = array_map('strval', (array) config('arma3-manager.heuristics.app_ids', []));

        if ($names === [] || $wanted === []) {
            return false;
        }

        foreach ($egg->variables()->get(['env_variable', 'default_value']) as $variable) {
            if (in_array(strtoupper((string) $variable->env_variable), $names, true)
                && in_array(trim((string) $variable->default_value), $wanted, true)) {
                return true;
            }
        }

        if (! $server) {
            return false;
        }

        foreach ($server->variables as $variable) {
            if (in_array(strtoupper((string) $variable->env_variable), $names, true)
                && in_array(trim((string) $variable->server_value ?? ''), $wanted, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Detect the flavour from tags and the egg name.
     *
     * The config's token order is load-bearing and must stay most-specific
     * first: `headless` before the generic arma tokens, because a headless
     * client egg is *also* an Arma egg and matching the generic token first
     * would hand it a Missions page for a container that hosts no mission.
     */
    private function detectFlavour(Egg $egg): ?ServerFlavour
    {
        $haystack = array_map('strtolower', $egg->tags ?? []);

        foreach (preg_split('/[^a-z0-9]+/i', Str::lower($egg->name)) ?: [] as $token) {
            if ($token !== '') {
                $haystack[] = $token;
            }
        }

        $haystack = array_unique($haystack);

        foreach ((array) config('arma3-manager.heuristics.flavour_tokens', []) as $flavourValue => $tokens) {
            foreach ((array) $tokens as $token) {
                if (in_array(strtolower((string) $token), $haystack, true)) {
                    return ServerFlavour::tryFrom($flavourValue);
                }
            }
        }

        // An Arma egg with no flavour signal is a dedicated server. It is the
        // overwhelmingly common case, and the cost of being wrong is one page
        // that shows an empty mpmissions directory — as against hiding mod
        // management from a server that needs it.
        return ServerFlavour::Arma3;
    }

    /**
     * Best-effort detection for an egg with no server attached, used by the
     * admin "unmapped eggs" suggestion and the seeder.
     */
    public function detectFlavourForEgg(Egg $egg): ?ServerFlavour
    {
        return $this->looksLikeArma($egg) ? $this->detectFlavour($egg) : null;
    }

    public function flush(): void
    {
        $this->memo = [];
    }
}
