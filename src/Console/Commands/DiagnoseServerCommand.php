<?php

namespace FyWolf\Arma3Manager\Console\Commands;

use App\Models\EggVariable;
use App\Models\Server;
use App\Models\ServerVariable;
use FyWolf\Arma3Manager\Enums\Capability;
use FyWolf\Arma3Manager\Services\ModService;
use FyWolf\Arma3Manager\Support\CapabilityResolver;
use FyWolf\Arma3Manager\Support\ModList;
use FyWolf\Arma3Manager\Support\ResolvedProfile;
use FyWolf\Arma3Manager\Support\ServerVariables;
use FyWolf\Arma3Manager\Support\WorkshopId;
use Illuminate\Console\Command;
use Throwable;

/**
 * Print everything this plugin sees for one server.
 *
 * ## Why this exists
 *
 * "The mods do not appear" has now been diagnosed three times by guessing, and
 * twice the guess was wrong. The chain between a customer clicking import and a
 * row rendering on the Mods page runs through an egg mapping, a capability
 * profile, a list of candidate variable names, a `server_variables` row, a
 * string format, and two directory listings on the daemon — and every one of
 * those can fail while the layer above it reports success.
 *
 * So this walks the whole chain and prints what it found at each step. It is
 * read-only: it resolves, reads and reports, and writes nothing anywhere.
 *
 * ## Values are redacted unless they are safe to show
 *
 * An earlier version claimed it never touched credentials, and then dumped every
 * `server_variables` row for the server — including `STEAM_PASS`,
 * `SERVER_PASSWORD` and `RCON_PASSWORD`. A diagnostic command is pasted into
 * tickets and chat logs by definition, so that is the worst possible place for
 * a secret to appear, and the claim in this docblock is exactly what made it
 * feel safe to run.
 *
 * So the rule is inverted: a value is masked unless its name is one this plugin
 * itself manages. `secretish()` decides, it errs towards masking, and a masked
 * value still shows its length so "is it set" stays answerable.
 */
class DiagnoseServerCommand extends Command
{
    protected $signature = 'arma3-manager:diagnose
        {server : The server uuid, short uuid, or name}
        {--test-write : Write a canary value to the mod list variable, read it back, then restore what was there}';

    protected $description = 'Show what Arma 3 Manager resolves for one server, step by step.';

    public function handle(CapabilityResolver $resolver, ModService $mods): int
    {
        $server = $this->findServer((string) $this->argument('server'));

        if (! $server) {
            $this->error('No server matched "' . $this->argument('server') . '".');

            return self::FAILURE;
        }

        $this->line('');
        $this->info('Server');
        $this->line('  name       ' . $server->name);
        $this->line('  uuid       ' . $server->uuid);
        $this->line('  egg        ' . ($server->egg?->name ?? '—') . ' (id ' . $server->egg_id . ')');

        $profile = $resolver->for($server);

        $this->line('');
        $this->info('Capability profile');

        if (! $profile) {
            $this->error('  Nothing resolved. This server sees none of the plugin.');
            $this->line('  Check Admin -> Arma 3 Eggs; the egg is either unmapped or not detected as Arma.');

            return self::FAILURE;
        }

        $this->line('  name       ' . $profile->name);
        $this->line('  source     ' . $profile->source . ($profile->sourceEggName ? ' (from ' . $profile->sourceEggName . ')' : ''));
        $this->line('  grants     ' . implode(', ', array_map(
            static fn (Capability $capability): string => $capability->value,
            $profile->capabilities,
        )));

        $this->reportVariable($server, $profile, $mods);
        $this->reportRows($server, $profile, $mods);

        if ($this->option('test-write')) {
            $this->testWrite($server, $profile, $mods);
        }

        $this->reportDisk($server, $profile, $mods);

        $this->line('');

        return self::SUCCESS;
    }

    private function reportVariable(Server $server, ResolvedProfile $profile, ModService $mods): void
    {
        $candidates = $mods->modVariables($profile);

        $this->line('');
        $this->info('Mod list variable');
        $this->line('  looking for  ' . (implode(', ', $candidates) ?: '(none configured)'));

        // Every variable the egg actually declares, so a name mismatch is
        // visible rather than inferred. This is the step that has been wrong
        // before: writing to a variable the egg does not read fails in total
        // silence.
        $this->line('  egg declares ' . (implode(', ', ServerVariables::declared($server)) ?: '(none)'));

        $resolved = $mods->variableName($server, $profile);

        if ($resolved === null) {
            $this->error('  MATCHED      nothing — none of the candidates exist on this egg.');
            $this->line('  Fix: add the egg\'s real variable name to the profile at Admin -> Arma 3 Profiles.');

            return;
        }

        $this->line('  MATCHED      ' . $resolved);

        $raw = ServerVariables::read($server, $candidates);

        $this->line('  raw value    ' . $this->display($resolved, (string) $raw));

        $order = $mods->loadOrder($server, $profile);

        $this->line('  parsed       ' . $order->count() . ' entr(ies)');

        foreach (array_slice($order->all(), 0, 10) as $index => $entry) {
            $id = WorkshopId::fromModEntry($entry);

            $this->line(sprintf(
                '    %2d. %-28s %s',
                $index + 1,
                $entry,
                $id !== null ? 'workshop id ' . $id : 'not a @workshopID entry',
            ));
        }

        if ($order->count() > 10) {
            $this->line('    … and ' . ($order->count() - 10) . ' more');
        }

        if ($order->isEmpty() && filled($raw)) {
            $this->error('  The variable holds a value but nothing parsed out of it. That is a format bug — please report this line.');
        }
    }

    /**
     * The `server_variables` table itself, queried directly.
     *
     * Everything else here reads through `$server->variables`, which is a
     * *projection*: a hasMany over `egg_variables` with a left join onto
     * `server_variables`. If a write lands somewhere that projection does not
     * look — the wrong `variable_id`, a row against another server — the value
     * is simply absent and every layer above reports success.
     *
     * That is the exact state this plugin is in on at least one panel: the write
     * returns true, no exception is raised, and the read finds nothing. So this
     * goes around the projection and prints the rows.
     */
    private function reportRows(Server $server, ResolvedProfile $profile, ModService $mods): void
    {
        $this->line('');
        $this->info('server_variables rows');

        $resolved = $mods->variableName($server, $profile);
        $target = null;

        foreach (EggVariable::query()->where('egg_id', $server->egg_id)->get() as $variable) {
            if ($resolved !== null && strtoupper((string) $variable->env_variable) === strtoupper($resolved)) {
                $target = $variable;
            }
        }

        if ($target !== null) {
            $this->line('  would write to  egg_variables.id = ' . $target->id . '  (' . $target->env_variable . ')');

            $direct = ServerVariable::query()
                ->where('server_id', $server->id)
                ->where('variable_id', $target->id)
                ->first();

            $this->line('  direct lookup   ' . ($direct
                ? 'row id ' . $direct->id . ', value: ' . $this->display((string) $target->env_variable, (string) $direct->variable_value)
                : 'NO ROW for (server ' . $server->id . ', variable ' . $target->id . ')'));
        }

        $rows = ServerVariable::query()->where('server_id', $server->id)->get();

        $this->line('  total rows      ' . $rows->count() . ' for this server');

        $names = EggVariable::query()
            ->where('egg_id', $server->egg_id)
            ->pluck('env_variable', 'id')
            ->map(static fn ($name): string => (string) $name)
            ->all();

        $this->line('  (values are masked unless this plugin manages that variable)');

        foreach ($rows as $row) {
            $name = $names[$row->variable_id] ?? '(variable ' . $row->variable_id . ' is not on this egg)';

            $this->line(sprintf('    %-28s %s', $name, $this->display($name, (string) $row->variable_value)));
        }
    }

    /**
     * Write a canary, read it back, put the old value back.
     *
     * The one thing no amount of reading the code has settled: whether a write
     * to this variable persists at all. Opt-in, because it does mutate — and it
     * restores whatever was there, including restoring "no row at all" by
     * deleting the row it created.
     */
    private function testWrite(Server $server, ResolvedProfile $profile, ModService $mods): void
    {
        $this->line('');
        $this->info('Write test');

        $candidates = $mods->modVariables($profile);
        $before = ServerVariables::read($server, $candidates);

        $this->line('  before        ' . ($before === null ? '(no row)' : ($before === '' ? '(empty)' : $before)));

        $canary = '@000000001;@000000002;';

        $wrote = ServerVariables::write($server, $candidates, $canary);

        $this->line('  write()       ' . ($wrote ? 'returned true' : 'returned FALSE — no candidate variable exists'));

        if (! $wrote) {
            return;
        }

        // Reads go to the tables now, so this needs no relation at all — and
        // eager loading one here is precisely what made the previous version of
        // this test report "the write did NOT persist" about a write that had.
        $after = ServerVariables::read($server, $candidates);

        $this->line('  read back     ' . ($after === null ? '(no row)' : ($after === '' ? '(empty)' : $after)));

        if ($after === $canary) {
            $this->info('  RESULT        writes persist. The fault is elsewhere.');
        } else {
            $this->error('  RESULT        the write did NOT persist. This is the bug.');
        }

        // Put it back exactly as it was.
        if ($before === null) {
            $target = EggVariable::query()
                ->where('egg_id', $server->egg_id)
                ->whereIn('env_variable', $candidates)
                ->first();

            if ($target) {
                ServerVariable::query()
                    ->where('server_id', $server->id)
                    ->where('variable_id', $target->id)
                    ->delete();
            }

            $this->line('  restored      removed the row again (there was none before)');
        } else {
            ServerVariables::write($server, $candidates, $before);
            $this->line('  restored      previous value written back');
        }
    }

    private function reportDisk(Server $server, ResolvedProfile $profile, ModService $mods): void
    {
        $this->line('');
        $this->info('On the server');

        try {
            $downloaded = $mods->downloadedIds($server, $profile);
            $downloading = $mods->downloadingIds($server, $profile);
        } catch (Throwable $exception) {
            $this->error('  Could not reach the daemon: ' . $exception->getMessage());

            return;
        }

        $root = $mods->resolvedWorkshopRoot($server);

        if ($root === null) {
            $this->line('  workshop dir (none of ' . implode(', ', (array) config('arma3-manager.steamcmd.workshop_roots', [])) . ' answered)');
            $this->line('               Nothing has been downloaded yet, or the server is off and the');
            $this->line('               daemon is refusing listings. Every mod will read as "waiting".');
        } else {
            $this->line('  workshop dir ' . $root);
        }

        $this->line('  downloaded   ' . count($downloaded) . ' workshop item(s) (SteamCMD cache or @<id> folder)');
        $this->line('  downloading  ' . count($downloading) . ' staged in ' . ($root ?? '?') . '/downloads');

        $missing = $mods->missing($server, $profile);

        $this->line('  missing      ' . count($missing) . ' entr(ies) in the load order with no files yet');

        foreach (array_slice($missing, 0, 5) as $entry) {
            $this->line('    ' . $entry);
        }

        $modsDir = $profile->modsDir();

        $this->line('  mods dir     /' . $modsDir . ' — ' . count($mods->listDirectories($server, $modsDir)) . ' director(ies)');
    }

    /**
     * A value fit to paste into a ticket.
     *
     * Masked unless this plugin manages the variable, because the alternative —
     * masking only what *looks* secret — has to be right every time, and was
     * already wrong once.
     */
    private function display(string $name, string $value): string
    {
        if ($value === '') {
            return '(empty)';
        }

        if ($this->secretish($name)) {
            return '(set, ' . mb_strlen($value) . ' characters, hidden)';
        }

        return mb_strimwidth($value, 0, 60, '…');
    }

    /**
     * Whether a variable's value must not be printed.
     *
     * An allowlist rather than a denylist. The variables this plugin writes are
     * mod folder names and startup flags and are safe to show; **everything
     * else on the egg is assumed to be a secret**, whatever it is called. A
     * denylist of PASS/TOKEN/KEY would have caught `STEAM_PASS`, and would still
     * miss the next egg's `LICENCE` or `WEBHOOK`.
     */
    private function secretish(string $name): bool
    {
        $mine = array_map('strtoupper', [
            ...(array) config('arma3-manager.steamcmd.mod_list_variables', []),
            ...(array) config('arma3-manager.steamcmd.servermod_list_variables', []),
            ...array_merge(...array_map(
                static fn (array $profile): array => [
                    ...(array) ($profile['mod_list_variables'] ?? []),
                    ...(array) ($profile['servermod_list_variables'] ?? []),
                    ...(array) ($profile['parameter_variables'] ?? []),
                    ...(array) ($profile['headless_variables'] ?? []),
                ],
                array_values((array) config('arma3-manager.profiles', [])),
            ) ?: [[]]),
            // Named explicitly because they are useful context for a mod
            // problem and carry nothing sensitive.
            'MOD_FILE',
            'OPTIONALMODS',
            'MODS_LOWERCASE',
            'STEAMCMD_APPID',
            'SERVER_BINARY',
            'UPDATE_SERVER',
            'VALIDATE_SERVER',
        ]);

        return ! in_array(strtoupper($name), $mine, true);
    }

    private function findServer(string $identifier): ?Server
    {
        $identifier = trim($identifier);

        return Server::query()
            // Deliberately NOT eager loading `variables`: that relation's
            // join reads `$this->id`, which is null during eager loading,
            // so every `server_value` comes back null. Eager loading it is
            // what made this command report "no server_variables row" on a
            // server whose row it then printed in full a few lines later.
            ->with('egg')
            ->where('uuid', $identifier)
            ->orWhere('uuid_short', $identifier)
            ->orWhere('name', $identifier)
            ->first()
            ?? Server::query()->with('egg')->where('name', 'like', '%' . $identifier . '%')->first();
    }
}
