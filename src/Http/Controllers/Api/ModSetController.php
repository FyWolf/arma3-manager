<?php

namespace FyWolf\Arma3Manager\Http\Controllers\Api;

use App\Facades\Activity;
use App\Http\Controllers\Controller;
use App\Models\Server;
use FyWolf\Arma3Manager\Http\Requests\GrantModSetRequest;
use FyWolf\Arma3Manager\Http\Requests\ReadModSetRequest;
use FyWolf\Arma3Manager\Models\ModSet;
use FyWolf\Arma3Manager\Models\ServerModSet;
use Illuminate\Http\JsonResponse;

/**
 * The endpoints the billing service calls to sell a mod set.
 *
 * Entitlement lives here and nowhere else. The server pages read it; they never
 * write it. A customer cannot grant themselves a set, and the billing service
 * cannot install one — which is the right split, because installing is a
 * destructive act on a running server and buying is not.
 *
 * ## Servers and sets are addressed by identifier, not by database id
 *
 * A caller outside the panel knows a server by its short uuid and a set by the
 * key an administrator gave it. Making the billing service store panel row ids
 * would couple it to this database's autoincrement, and the first restore from
 * backup would silently rewire every grant.
 */
class ModSetController extends Controller
{
    /**
     * Every set a customer could be sold.
     */
    public function catalogue(ReadModSetRequest $request): JsonResponse
    {
        $sets = ModSet::query()
            ->where('is_enabled', true)
            ->orderBy('sort')
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => $sets->map(fn (ModSet $set): array => [
                'key' => $set->key,
                'name' => $set->name,
                'description' => $set->description,
                'public' => $set->is_public,
                'mods' => count($set->mods ?? []),
            ])->all(),
        ]);
    }

    /**
     * What one server has been granted.
     */
    public function index(ReadModSetRequest $request, string $server): JsonResponse
    {
        $model = $this->server($server);

        if (! $model) {
            return response()->json(['errors' => [['code' => 'NotFound', 'detail' => 'No such server.']]], 404);
        }

        $grants = ServerModSet::query()
            ->with('modSet')
            ->where('server_id', $model->id)
            ->get();

        return response()->json([
            'data' => $grants
                ->filter(fn (ServerModSet $grant): bool => $grant->modSet !== null)
                ->map(fn (ServerModSet $grant): array => [
                    'key' => $grant->modSet->key,
                    'name' => $grant->modSet->name,
                    'source' => $grant->source,
                    'granted_at' => $grant->granted_at?->toIso8601String(),
                ])
                ->values()
                ->all(),
        ]);
    }

    public function store(GrantModSetRequest $request): JsonResponse
    {
        $server = $this->server((string) $request->input('server'));
        $set = ModSet::query()->where('key', $request->input('mod_set'))->first();

        if (! $server || ! $set) {
            return response()->json(['errors' => [['code' => 'NotFound', 'detail' => 'No such server or mod set.']]], 404);
        }

        // updateOrCreate, not create: a grant is a fact rather than a quantity,
        // and a retried webhook must not produce a second row — the unique
        // index would reject it and the retry would look like a failure.
        $grant = ServerModSet::updateOrCreate(
            ['server_id' => $server->id, 'mod_set_id' => $set->id],
            ['source' => $request->input('source'), 'granted_at' => now()],
        );

        Activity::event('server:arma3.modset-grant')
            ->subject($server)
            ->property(['set' => $set->name, 'source' => $grant->source])
            ->log();

        return response()->json([
            'data' => [
                'key' => $set->key,
                'name' => $set->name,
                'granted_at' => $grant->granted_at?->toIso8601String(),
            ],
        ], 201);
    }

    public function destroy(GrantModSetRequest $request): JsonResponse
    {
        $server = $this->server((string) $request->input('server'));
        $set = ModSet::query()->where('key', $request->input('mod_set'))->first();

        if (! $server || ! $set) {
            return response()->json(['errors' => [['code' => 'NotFound', 'detail' => 'No such server or mod set.']]], 404);
        }

        ServerModSet::query()
            ->where('server_id', $server->id)
            ->where('mod_set_id', $set->id)
            ->delete();

        // Deliberately does NOT touch the load order. Withdrawing an
        // entitlement stops the set being offered again; ripping the mods out
        // from under a running server would take it down at the moment a
        // subscription lapsed, which is the worst possible time.
        Activity::event('server:arma3.modset-revoke')
            ->subject($server)
            ->property(['set' => $set->name])
            ->log();

        return response()->json(null, 204);
    }

    private function server(string $identifier): ?Server
    {
        $identifier = trim($identifier);

        if ($identifier === '') {
            return null;
        }

        return Server::query()
            ->where('uuid', $identifier)
            ->orWhere('uuid_short', $identifier)
            ->first();
    }
}
