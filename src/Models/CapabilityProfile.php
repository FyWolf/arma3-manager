<?php

namespace FyWolf\Arma3Manager\Models;

use App\Models\Egg;
use FyWolf\Arma3Manager\Enums\Capability;
use FyWolf\Arma3Manager\Enums\ServerFlavour;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * What a family of eggs is allowed to do, and where its files live.
 *
 * @property int $id
 * @property string $name
 * @property ?string $flavour
 * @property array<int, string> $capabilities
 * @property ?string $mods_dir
 * @property ?string $servermods_dir
 * @property ?string $missions_dir
 * @property ?string $profiles_dir
 * @property ?string $server_binary
 * @property ?array<int, string> $config_files
 * @property ?array<int, string> $mod_list_variables
 * @property ?array<int, string> $servermod_list_variables
 * @property ?array<int, string> $parameter_variables
 * @property ?array<int, string> $headless_variables
 * @property Collection<int, Egg> $eggs
 * @property ?int $eggs_count
 */
class CapabilityProfile extends Model
{
    protected $table = 'a3_capability_profiles';

    protected $fillable = [
        'name',
        'flavour',
        'capabilities',
        'mods_dir',
        'servermods_dir',
        'missions_dir',
        'profiles_dir',
        'server_binary',
        'config_files',
        'mod_list_variables',
        'servermod_list_variables',
        'parameter_variables',
        'headless_variables',
    ];

    protected $attributes = [
        'mods_dir' => 'mods',
        'profiles_dir' => 'profiles',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'capabilities' => 'array',
            'config_files' => 'array',
            'mod_list_variables' => 'array',
            'servermod_list_variables' => 'array',
            'parameter_variables' => 'array',
            'headless_variables' => 'array',
        ];
    }

    /**
     * The pivot table has to be named explicitly: Laravel would derive
     * `capability_profile_egg` from the two model names, but the table is
     * `egg_a3_capability_profile` so it reads sensibly beside the panel's own
     * `egg_game_query`.
     */
    public function eggs(): BelongsToMany
    {
        return $this->belongsToMany(Egg::class, 'egg_a3_capability_profile', 'a3_capability_profile_id', 'egg_id');
    }

    public function flavour(): ?ServerFlavour
    {
        return $this->flavour ? ServerFlavour::tryFrom($this->flavour) : null;
    }

    public function has(Capability $capability): bool
    {
        return in_array($capability->value, $this->capabilities ?? [], true);
    }

    public function hasAny(Capability ...$capabilities): bool
    {
        foreach ($capabilities as $capability) {
            if ($this->has($capability)) {
                return true;
            }
        }

        return false;
    }
}
