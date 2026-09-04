<?php

namespace FyWolf\Arma3Manager\Models;

use App\Models\Server;
use FyWolf\Arma3Manager\Support\ModList;
use FyWolf\Arma3Manager\Support\WorkshopId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A named modset a customer uploaded, kept for this server.
 *
 * @property int $id
 * @property int $server_id
 * @property string $name
 * @property array<int, string> $entries
 * @property ?\Illuminate\Support\Carbon $applied_at
 */
class Preset extends Model
{
    protected $table = 'a3_presets';

    protected $fillable = [
        'server_id',
        'name',
        'entries',
        'applied_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'entries' => 'array',
            'applied_at' => 'datetime',
        ];
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class, 'server_id');
    }

    public function modList(): ModList
    {
        return ModList::fromArray($this->entries ?? []);
    }

    /**
     * How many of its entries are Workshop items.
     *
     * The rest are CDLC codes and hand-uploaded folders, which are legitimate
     * members of the list and are not downloadable — so a count of "mods" that
     * included them would not match what the Mods page can report progress on.
     */
    public function workshopCount(): int
    {
        return count(array_filter(array_map(
            WorkshopId::fromModEntry(...),
            $this->entries ?? [],
        )));
    }

    /**
     * Whether this preset is what the server is currently set to load.
     *
     * Compared by content rather than trusted from `applied_at`, because the
     * customer can edit the load order on the Mods page after applying one. A
     * stored "active" flag would keep claiming this preset is running while the
     * server loads something else — the failure being that the screen and the
     * server disagree, silently, which is the whole class of bug this plugin
     * keeps finding.
     *
     * Order matters, so this is a sequence comparison and not a set one: Arma
     * merges addons in the order given, and two presets with the same mods in a
     * different order are genuinely different presets.
     */
    public function matches(ModList $order): bool
    {
        return $this->modList()->all() === $order->all();
    }
}
