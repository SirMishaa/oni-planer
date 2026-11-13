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

## Rendu de la carte des zones

La carte des zones dessine les éléments avec les données de rendu du jeu. Si ton export est plus ancien, relance **Export Game Data** dans le jeu (menu pause), puis les trois commandes ci-dessus. Si `game:import-assets` échoue en listant des solides sans texture ou des fichiers de rendu manquants, c'est que l'export n'est pas à jour.

<Accordion title="Ce que l'import lit et publie">

`game:import` lit la clé `render` de chaque élément dans `planner_extras.json` et la range dans les colonnes de la table des éléments : `render_color`, `substance_texture`, `uses_caustics`, `glows`, `is_metallic`, `is_opaque_liquid`, `liquid_scroll_speed`, `max_mass` et `terrain_texture`. Une famille de texture de substance inconnue est stockée à `null`. La commande liste en avertissement les gaz, liquides et solides importés sans clé `render` : la carte les dessine avec un motif neutre.

`game:import-assets` publie en plus, depuis l'export :

- `render/terrain/*.png` vers `terrain/*.webp`, une texture par solide ;
- `render/mask_atlas.png` vers `terrain/mask_atlas.webp`, l'atlas des masques de bord ;
- `database/planner_render.json` vers `render.json`.

La commande échoue (code de sortie non nul) si un solide n'a pas de texture de terrain, si l'image de sa texture n'a pas été publiée, ou si `render/mask_atlas.png` ou `database/planner_render.json` manque dans l'export.

</Accordion>
