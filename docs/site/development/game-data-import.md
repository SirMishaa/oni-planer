---
title: Import des données du jeu
description: Transformer l'export OniExtract en tables de référence, catalogues de traduction et images WebP.
sources: ["app/Console/Commands/GameImportCommand.php", "app/Console/Commands/GameSyncExportCommand.php", "app/Console/Commands/GameImportAssetsCommand.php", "app/Services/GameImport/**"]
order: 3
---

# Import des données du jeu

Les données de référence viennent du mod **OniExtract**, qui exporte en JSON les données du jeu (bâtiments, entités, éléments, recettes, images) depuis le menu pause. Trois commandes les font entrer dans l'application, dans cet ordre :

```bash
php artisan game:sync-export     # copie l'export et les mods pris en charge dans game-data/
php artisan game:import          # reconstruit les tables du jeu et les catalogues lang/game
php artisan game:import-assets   # convertit les images exportées en WebP
```

<Callout type="warning">
`game:import` est destructif : il vide toutes les tables de données du jeu et réimporte tout. Les données de colonie (colonies, messages Uplink, zones) ne sont pas touchées.
</Callout>

## Fonctionnement

- Les readers de `app/Services/GameImport/OniExport/` lisent l'export en enregistrements ; les importers les persistent.
- Tout l'import tourne dans une seule transaction. Les catalogues de traduction de `lang/game/` sont générés en parallèle et ne remplacent les anciens qu'après le commit.
- Si Octane tourne, la commande recharge ses workers pour qu'ils servent les nouveaux catalogues. Si le rechargement échoue, elle affiche la commande `php artisan octane:reload` à lancer à la main.

<Accordion title="Référence des commandes">
<Artisan command="game:sync-export" />
<Artisan command="game:import" />
<Artisan command="game:import-assets" />
</Accordion>
