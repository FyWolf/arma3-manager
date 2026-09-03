<?php

namespace FyWolf\Arma3Manager\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * What a server's egg is allowed to do.
 *
 * Mods and ServerMods are deliberately separate rather than one "addons" flag.
 * They are different startup parameters with different semantics: `-mod=` is
 * loaded by the server *and* required of every client, while `-serverMod=` is
 * server-only and clients neither have it nor need it. A headless client gets
 * Mods and never ServerMods, and collapsing the two would either force a
 * server-only addon onto every client or hide it entirely.
 *
 * Missions and Configs are likewise separate: a headless client hosts no
 * mission and has no server.cfg, but does need the identical mod list.
 */
enum Capability: string implements HasLabel
{
    case Mods = 'mods';
    case ServerMods = 'servermods';
    case Missions = 'missions';
    case Configs = 'configs';
    case Presets = 'presets';
    case Parameters = 'parameters';
    case ModSets = 'modsets';

    public function getLabel(): string
    {
        return match ($this) {
            self::Mods => 'Mods',
            self::ServerMods => 'Server-only mods',
            self::Missions => 'Missions',
            self::Configs => 'Configuration files',
            self::Presets => 'Launcher presets',
            self::Parameters => 'Startup parameters',
            self::ModSets => 'Mod sets',
        };
    }

    public function getDescription(): string
    {
        return match ($this) {
            self::Mods => 'Browse the Workshop, manage the -mod= load order and sync downloads.',
            self::ServerMods => 'Manage -serverMod=, loaded by the server and never sent to clients.',
            self::Missions => 'Upload, remove and rotate the .pbo missions in mpmissions.',
            self::Configs => 'Edit server.cfg and basic.cfg as a form.',
            self::Presets => 'Import and export Arma 3 Launcher HTML presets.',
            self::Parameters => 'Startup flags, headless clients and Creator DLC.',
            self::ModSets => 'Install a whole curated mod set in one action.',
        };
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
