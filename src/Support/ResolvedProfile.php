<?php

namespace FyWolf\Arma3Manager\Support;

use FyWolf\Arma3Manager\Enums\Capability;
use FyWolf\Arma3Manager\Enums\ServerFlavour;
use FyWolf\Arma3Manager\Models\CapabilityProfile;

/**
 * A server's resolved capabilities, however they were arrived at.
 *
 * Every consumer sees this one type whether the profile came from an explicit
 * admin mapping, was inherited from a parent egg, or was guessed — so no page
 * ever has to branch on where its configuration came from. Only the header
 * banner cares, via $source.
 */
readonly class ResolvedProfile
{
    public const SOURCE_EXPLICIT = 'explicit';

    public const SOURCE_INHERITED = 'inherited';

    public const SOURCE_HEURISTIC = 'heuristic';

    /**
     * @param array<int, Capability> $capabilities
     * @param array<int, string>     $configFiles
     * @param array<int, string>     $modListVariables
     * @param array<int, string>     $serverModListVariables
     * @param array<int, string>     $parameterVariables
     * @param array<int, string>     $headlessVariables
     */
    public function __construct(
        public string $name,
        public ?ServerFlavour $flavour,
        public array $capabilities,
        public ?string $modsDir,
        public ?string $serverModsDir,
        public ?string $missionsDir,
        public ?string $profilesDir,
        public ?string $serverBinary,
        public array $configFiles,
        public array $modListVariables,
        public array $serverModListVariables,
        public array $parameterVariables,
        public array $headlessVariables,
        public string $source,
        public ?string $sourceEggName = null,
        public ?int $profileId = null,
    ) {}

    public static function fromModel(CapabilityProfile $profile, string $source = self::SOURCE_EXPLICIT, ?string $sourceEggName = null): self
    {
        return new self(
            name: $profile->name,
            flavour: $profile->flavour(),
            capabilities: self::mapCapabilities($profile->capabilities ?? []),
            modsDir: $profile->mods_dir,
            serverModsDir: $profile->servermods_dir,
            missionsDir: $profile->missions_dir,
            profilesDir: $profile->profiles_dir,
            serverBinary: $profile->server_binary,
            configFiles: $profile->config_files ?? [],
            modListVariables: $profile->mod_list_variables ?? [],
            serverModListVariables: $profile->servermod_list_variables ?? [],
            parameterVariables: $profile->parameter_variables ?? [],
            headlessVariables: $profile->headless_variables ?? [],
            source: $source,
            sourceEggName: $sourceEggName,
            profileId: $profile->id,
        );
    }

    /**
     * Build from the built-in defaults in config, for a heuristically detected
     * flavour. Deliberately produces no database row — persisting a guess would
     * fight the administrator the next time they edited the real mapping.
     */
    public static function fromDefaults(ServerFlavour $flavour): ?self
    {
        $defaults = config('arma3-manager.profiles.' . $flavour->value);

        if (! is_array($defaults)) {
            return null;
        }

        return new self(
            name: $defaults['name'] ?? $flavour->getLabel(),
            flavour: $flavour,
            capabilities: self::mapCapabilities($defaults['capabilities'] ?? $flavour->defaultCapabilities()),
            modsDir: $defaults['mods_dir'] ?? 'mods',
            serverModsDir: $defaults['servermods_dir'] ?? null,
            missionsDir: $defaults['missions_dir'] ?? null,
            profilesDir: $defaults['profiles_dir'] ?? 'profiles',
            serverBinary: $defaults['server_binary'] ?? null,
            configFiles: $defaults['config_files'] ?? [],
            modListVariables: $defaults['mod_list_variables'] ?? [],
            serverModListVariables: $defaults['servermod_list_variables'] ?? [],
            parameterVariables: $defaults['parameter_variables'] ?? [],
            headlessVariables: $defaults['headless_variables'] ?? [],
            source: self::SOURCE_HEURISTIC,
        );
    }

    /**
     * @param array<int, string> $values
     *
     * @return array<int, Capability>
     */
    private static function mapCapabilities(array $values): array
    {
        return array_values(array_filter(array_map(
            fn (string $value) => Capability::tryFrom($value),
            $values,
        )));
    }

    public function has(Capability $capability): bool
    {
        return in_array($capability, $this->capabilities, true);
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

    public function modsDir(): string
    {
        return trim($this->modsDir ?: 'mods', '/');
    }

    public function serverModsDir(): ?string
    {
        return $this->serverModsDir ? trim($this->serverModsDir, '/') : null;
    }

    public function missionsDir(): ?string
    {
        return $this->missionsDir ? trim($this->missionsDir, '/') : null;
    }

    /**
     * The config files this server offers, filtered to those the profile names.
     *
     * Empty means the Configuration page does not render — which is the correct
     * outcome for a headless client, whose container has no server.cfg to edit.
     *
     * @return array<int, string>
     */
    public function configFiles(): array
    {
        return array_values(array_filter(array_map(
            static fn ($file) => trim((string) $file, '/'),
            $this->configFiles,
        )));
    }

    /**
     * Which typed schema describes a given config file.
     *
     * Keyed on the *basename* rather than the path, because an egg may put
     * server.cfg anywhere and the schema follows the file, not its location.
     *
     * @return array<string, array<string, mixed>>
     */
    public function schemaFor(string $file): array
    {
        $base = strtolower(basename($file));

        return match (true) {
            str_contains($base, 'basic') => (array) config('arma3-manager.configs.basic_cfg_schema', []),
            default => (array) config('arma3-manager.configs.server_cfg_schema', []),
        };
    }

    /**
     * Whether this server hosts a mission, as opposed to joining one.
     *
     * Read from the capability set rather than the flavour: an administrator
     * who builds a custom profile decides this by ticking Missions, and the
     * flavour is only ever a default.
     */
    public function hosts(): bool
    {
        return $this->has(Capability::Missions) || $this->has(Capability::Configs);
    }

    /**
     * A one-line explanation of where this configuration came from, shown in the
     * page header so an administrator can tell a guess from a decision.
     */
    public function sourceDescription(): string
    {
        return match ($this->source) {
            self::SOURCE_EXPLICIT => trans('arma3-manager::strings.profile.source.explicit'),
            self::SOURCE_INHERITED => trans('arma3-manager::strings.profile.source.inherited', ['egg' => $this->sourceEggName ?? '—']),
            default => trans('arma3-manager::strings.profile.source.heuristic', ['flavour' => $this->flavour?->getLabel() ?? '—']),
        };
    }

    public function isDetected(): bool
    {
        return $this->source === self::SOURCE_HEURISTIC;
    }
}
