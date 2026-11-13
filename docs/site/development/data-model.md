---
title: Modèle de données
description: Les tables de référence importées du jeu et les tables de colonie remplies par Uplink.
sources: ["app/Models/**", "database/migrations/**"]
order: 5
---

# Modèle de données

## Données de référence

Reconstruites à chaque `game:import`.

| Domaine | Modèles principaux |
| --- | --- |
| Éléments | <Model name="Element" />, avec une table enfant par état (thermique, gaz, liquide, solide, spécial) et les propriétés alimentaires |
| Bâtiments | <Model name="Building" />, <Model name="Recipe" />, matériaux de construction |
| Créatures | <Model name="Critter" />, <Model name="CritterMorph" />, régimes et productions |
| Plantes | <Model name="Plant" />, <Model name="PlantVariant" />, entrées, sorties et mutations |
| Autres | <Model name="GeyserType" />, stats et traits des duplicants, moteurs et modules de fusée, <Model name="CodexEntry" />, <Model name="RoomType" /> |

Les éléments utilisent l'héritage par tables de classe : chaque état a sa propre table enfant.

<Accordion title="Colonnes de rendu des éléments">

La carte des zones lit ces colonnes de la table des éléments, remplies par `game:import` depuis la clé `render` de l'export :

| Colonne | Rôle |
| --- | --- |
| `render_color` | Couleur de rendu du jeu. |
| `substance_texture` | Famille de texture de substance (liquides et gaz). |
| `uses_caustics` | Le liquide reçoit l'effet de caustiques. |
| `glows` | L'élément brille. |
| `is_metallic` | Rendu métallique. |
| `is_opaque_liquid` | Liquide opaque. |
| `liquid_scroll_speed` | Vitesse de défilement de la texture du liquide. |
| `max_mass` | Masse maximale d'une case. |
| `terrain_texture` | Texture du terrain d'un solide. |

</Accordion>

## Données de colonie

Remplies par Uplink, jamais touchées par l'import.

- <Model name="Colony" /> : le dernier état reçu pour chaque colonie.
- <Model name="UplinkMessage" /> : le journal affiché dans l'inspecteur.
- <Model name="UplinkSession" /> : une ligne par session de jeu (sauvegarde chargée).
- <Model name="Zone" /> et <Model name="ZoneVersion" /> : les zones marquées en jeu et chaque version de leurs cases, pour que modifier une zone ne réécrive jamais son passé. Une zone garde aussi la lecture lente précédente de ses températures (`previous_slow_temperature`), pour le mode Flux thermique de la carte.
