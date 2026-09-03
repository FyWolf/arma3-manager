<?php

namespace FyWolf\Arma3Manager\Models;

use App\Models\Server;
use FyWolf\Arma3Manager\Support\ModList;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A curated collection of Workshop mods, in load order.
 *
 * @property int $id
 * @property string $key
 * @property string $name
 * @property ?string $description
 * @property array<int, array{id: string, name?: string}> $mods
 * @property ?array<int, array{id: string, name?: string}> $server_mods
 * @property bool $is_public
 * @property bool $is_enabled
 * @property int $sort
 */
class ModSet extends Model
{
    protected $table = 'a3_mod_sets';

    protected $fillable = [
        'key',
        'name',
        'description',
        'mods',
        'server_mods',
        'is_public',
        'is_enabled',
        'sort',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'mods' => 'array',
            'server_mods' => 'array',
            'is_public' => 'boolean',
            'is_enabled' => 'boolean',
        ];
    }

    public function installs(): HasMany
    {
        return $this->hasMany(ModSetInstall::class, 'mod_set_id');
    }

    public function grants(): HasMany
    {
        return $this->hasMany(ServerModSet::class, 'mod_set_id');
    }

    /**
     * The workshop ids in this set, in order.
     *
     * @return array<int, string>
     */
    public function workshopIds(): array
    {
        return array_values(array_filter(array_map(
            static fn ($mod) => is_array($mod) ? (string) ($mod['id'] ?? '') : (string) $mod,
            $this->mods ?? [],
        )));
    }

    /**
     * The Workshop ids this set contributes, in order.
     *
     * Ids rather than folder names, because the load order this feeds is what
     * the egg's install script downloads from and SteamCMD only takes an id.
     * The `folder` recorded against each entry is informational — it is what
     * the mod is expected to unpack as, and nothing here relies on it.
     */
    public function modList(): ModList
    {
        return ModList::fromArray($this->workshopIds());
    }

    /**
     * Sets a given server may install: the public ones, plus anything granted.
     *
     * One query rather than "fetch public, fetch granted, merge in PHP" — the
     * merge version silently returns duplicates for a set that is both, and the
     * duplicate then renders as two identical install buttons.
     */
    public function scopeInstallableBy(Builder $query, Server $server): Builder
    {
        return $query
            ->where('is_enabled', true)
            ->where(function (Builder $inner) use ($server) {
                $inner
                    ->where('is_public', true)
                    ->orWhereHas('grants', fn (Builder $grant) => $grant->where('server_id', $server->id));
            });
    }
}
