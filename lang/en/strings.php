<?php

return [
    'nav' => [
        'group' => 'Arma 3',
        'mods' => 'Mods',
        'workshop' => 'Workshop',
        'missions' => 'Missions',
        'configs' => 'Configuration',
        'presets' => 'Presets',
        'parameters' => 'Parameters',
        'modsets' => 'Mod sets',
    ],

    'profile' => [
        'source' => [
            'explicit' => 'Configured by an administrator.',
            'inherited' => 'Inherited from the parent egg :egg.',
            'heuristic' => 'Detected automatically from this egg (:flavour). An administrator can pin it.',
        ],
    ],

    'server_running' => [
        'blocked' => 'The server must be stopped first.',
        'warning' => 'The server is running. Arma reads its configuration and its mod list once at startup, so nothing changed now takes effect until it restarts.',
    ],

    'variable_missing' => 'This egg has no :names variable, so there is nowhere to record the mod list. An administrator has to add one to the egg before mods can be managed here.',

    'permission_denied' => 'You do not have permission to do that.',

    'daemon_unreachable' => 'Could not reach the server daemon: :error',

    'steam' => [
        'no_key' => 'Workshop search needs a Steam Web API key. Without one you can still add mods by pasting a workshop link or id.',
        'unreachable' => 'Could not reach the Steam Workshop API: :error',
        'not_found' => 'Steam has no published item with that id.',
    ],
];
