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

## Données de colonie

Remplies par Uplink, jamais touchées par l'import.

- <Model name="Colony" /> : le dernier état reçu pour chaque colonie.
- <Model name="UplinkMessage" /> : le journal affiché dans l'inspecteur.
- <Model name="UplinkSession" /> : une ligne par session de jeu (sauvegarde chargée).
- <Model name="Zone" /> et <Model name="ZoneVersion" /> : les zones marquées en jeu et chaque version de leurs cases, pour que modifier une zone ne réécrive jamais son passé.
