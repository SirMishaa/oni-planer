---
title: Import des données du jeu
description: Transformer l'export OniExtract en tables de référence, catalogues de traduction et images WebP.
sources: ["app/Console/Commands/GameImportCommand.php", "app/Console/Commands/GameSyncExportCommand.php", "app/Console/Commands/GameImportAssetsCommand.php", "app/Services/GameImport/**", "scripts/kanim/**"]
order: 3
---

# Import des données du jeu

Les données de référence viennent du mod **OniExtract**, qui exporte en JSON les données du jeu (bâtiments, entités, éléments, recettes, images) depuis le menu pause. Trois commandes les font entrer dans l'application, dans cet ordre :

```bash
php artisan game:sync-export     # copie l'export et les fichiers des mods dans game-data/
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
- Le contenu de tous les mods présents dans l'export est importé, pour que la carte des zones (et plus tard un menu de construction) connaisse chaque bâtiment de ta partie. `game.supported_mods` peut limiter l'import à une liste de mods ; la valeur `*` les prend tous. `game:sync-export` copie les traductions de chaque mod de l'export ; un mod sans dossier copié est importé sans ses traductions.
- `game:sync-export` copie l'export avec `cp`, puis vérifie qu'il a autant de fichiers que la source. Sous WSL, les itérateurs de dossier de PHP sautent des fichiers sur le disque Windows : sans cette vérification, la copie restait incomplète sans erreur. Si la commande signale une copie incomplète, relance-la.

<Accordion title="Référence des commandes">
<Artisan command="game:sync-export" />
<Artisan command="game:import" />
<Artisan command="game:import-assets" />
</Accordion>

## Rendu de la carte des zones

La carte des zones dessine les éléments avec les données de rendu du jeu. Si ton export est plus ancien, relance **Export Game Data** dans le jeu (menu pause), puis les trois commandes ci-dessus. Si `game:import-assets` échoue en listant des solides sans texture ou des fichiers de rendu manquants, c'est que l'export n'est pas à jour.

<Accordion title="Ce que l'import lit et publie">

`game:import` lit la clé `render` de chaque élément dans `planner_extras.json` et la range dans les colonnes de la table des éléments : `render_color`, `substance_texture`, `uses_caustics`, `glows`, `is_metallic`, `is_opaque_liquid`, `liquid_scroll_speed`, `max_mass` et `terrain_texture`. Une famille de texture de substance inconnue est stockée à `null`. La commande liste en avertissement les gaz, liquides et solides importés sans clé `render` : la carte les dessine avec un motif neutre.

Pour chaque ligne de régime d'un critter, `game:import` range le type de nourriture du jeu (`foodType`) dans `critter_morph_diets.food_kind` : objet, plante ou proie. Le bilan d'alimentation s'en sert pour ne compter en stock que les objets.

`game:import-assets` publie en plus, depuis l'export :

- `render/terrain/*.png` vers `terrain/*.webp`, une texture par solide ;
- `render/mask_atlas.png` vers `terrain/mask_atlas.webp`, l'atlas des masques de bord ;
- `database/planner_render.json` vers `render.json` ;
- `stair_caps/{bâtiment}/{masque}.png` vers `stair_caps/{bâtiment}/{masque}.webp`, et `stair_caps.json` vers `stair_caps/rects.json` : les variantes des escaliers du mod Stairs, qui raccordent chaque marche à ses voisines comme dans le jeu ;
- `ui_image/{œuf}.png` vers `eggs/{œuf}.webp`, une image par œuf pondu par un critter. La liste des œufs vient des productions des critters : lance `game:import` avant.

Elle recrée aussi depuis `ui_image/{critter}.png` les images de critters vides (moins de 200 octets), même plus récentes que leur source. Une image d'œuf vide est recréée de la même façon.

Pour chaque morph de critter (bébés et morphs de mods compris) qui n'a pas encore de portrait, elle convertit `ui_image/{morph}.png` vers `critters/{morph}.webp`. Un portrait déjà rendu n'est jamais remplacé. Les morphs sans portrait ni image dans `ui_image` sont listés en avertissement, sans faire échouer la commande.

L'import lit aussi, pour chaque bâtiment, s'il rend sa case pleine (`simCellOccupier` : dalles, portes…), rangé dans `sim_cell_occupier`. La carte s'en sert pour raccorder les escaliers.

`game:import-assets` échoue (code de sortie non nul) si un solide n'a pas de texture de terrain, si l'image de sa texture n'a pas été publiée, si `render/mask_atlas.png` ou `database/planner_render.json` manque dans l'export, si un œuf n'a pas d'image dans `ui_image` (ou si son image reste vide), ou si une image de critter vide n'a pas de source dans `ui_image`.

</Accordion>

<Accordion title="Rendu des sprites animés (kanim)">

Les portraits, boucles d'animation (`_idle`) et sprites de carte (`_world`) des critters et des plantes sont rendus depuis les kanims du jeu par `scripts/kanim/extract.py`, hors de `game:import-assets`. La liste à rendre vient de l'export synchronisé (`entities.json`, `planner_extras.json`, `geyser.json`) : tous les critters, bébés compris, toutes les plantes et tous les geysers, chacun avec le kanim exact que charge le jeu. Un bébé est rendu avec son propre kanim, dans les couleurs du morph de son adulte. Les critters de mods ne sont pas rendus : leurs kanims sont livrés avec le mod, et `game:import-assets` reprend leur image `ui_image`.

Lance le rendu avec `--missing` pour ne rendre que les entités sans image, puis `game:import-assets` pour compléter les portraits restants depuis `ui_image`. Depuis un worktree git, `KANIM_DATA_ROOT` désigne le dépôt principal, seul à contenir `game-data/` et `public/assets/game/`.

</Accordion>
