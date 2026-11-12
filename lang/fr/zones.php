<?php

declare(strict_types=1);

return [
    'nav' => [
        'zones' => 'Zones',
    ],
    'index' => [
        'title' => 'Zones',
        'empty' => "Aucune zone pour l'instant. Tracez-en une dans le jeu avec l'outil Zone : elle apparaît ici en moins d'une seconde.",
        'no_colony' => "Uplink n'a pas encore envoyé de colonie.",
        'cells' => ':count cases',
        'problems' => '{0} Aucun problème|{1} 1 problème|[2,*] :count problèmes',
        'updated' => 'mis à jour :age',
    ],
    'show' => [
        'orphaned' => "Le monde de cette zone n'existe plus dans le jeu. La zone est gardée telle qu'elle a été vue la dernière fois.",
        'no_layout' => "Le jeu n'a pas encore envoyé le plan de cette zone.",
        'unknown' => "Le jeu n'a pas pu lire cette partie de la zone.",
        'tabs' => [
            'map' => 'Carte',
            'entities' => 'Entités',
            'history' => 'Historique',
        ],
    ],
    'kinds' => [
        'plant' => 'Plante',
        'building' => 'Bâtiment',
    ],
    'states' => [
        'growing' => 'En croissance',
        'grown' => 'Prête à récolter',
        'wilting' => 'Flétrit',
        'operational' => 'En marche',
        'not_operational' => "À l'arrêt",
    ],
    'problems' => [
        'wilting' => 'Flétrit',
        'not_operational' => 'Hors service',
    ],
];
