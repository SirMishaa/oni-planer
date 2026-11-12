<?php

declare(strict_types=1);

return [
    'nav' => [
        'zones' => 'Zones',
    ],
    'index' => [
        'title' => 'Zones',
        'empty' => 'No zone yet. Mark one in the game with the Zone tool: it shows up here within a second.',
        'no_colony' => 'Uplink has not sent a colony yet.',
        'cells' => ':count cells',
        'problems' => '{0} No problem|{1} 1 problem|[2,*] :count problems',
        'updated' => 'updated :age',
    ],
    'show' => [
        'orphaned' => 'The world of this zone no longer exists in the game. The zone is kept as it was last seen.',
        'no_layout' => 'The game has not sent the layout of this zone yet.',
        'unknown' => 'The game could not read this part of the zone.',
        'tabs' => [
            'map' => 'Map',
            'entities' => 'Entities',
            'history' => 'History',
        ],
    ],
    'kinds' => [
        'plant' => 'Plant',
        'building' => 'Building',
    ],
    'states' => [
        'growing' => 'Growing',
        'grown' => 'Ready to harvest',
        'wilting' => 'Wilting',
        'operational' => 'Running',
        'not_operational' => 'Stopped',
    ],
    'problems' => [
        'wilting' => 'Wilting',
        'not_operational' => 'Not operational',
    ],
];
