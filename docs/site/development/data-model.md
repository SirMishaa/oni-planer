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
- <Model name="Zone" /> et <Model name="ZoneVersion" /> : les zones marquées en jeu et chaque version de leurs cases, pour que modifier une zone ne réécrive jamais son passé. Une zone garde aussi la lecture lente précédente de ses températures (`previous_slow_temperature`, même session uniquement), pour le mode Flux thermique de la carte. Elle garde aussi les derniers écarts de surpeuplement entre le planner et le jeu (`crowding_mismatches`, `null` quand tout concorde). Chaque état enregistré garde sa session et son temps de jeu (`slow_session_id`, `slow_game_seconds`, et pareil pour `fast`), pour qu'un état en retard ne remplace jamais un plus récent (voir [Uplink](./uplink.md)).
- <Model name="ZoneCapture" /> : les images d'une zone dessinées par le jeu (fichier WebP sur le disque public), les 15 dernières par zone, chacune avec la signature du layout qu'elle montre (voir [Captures de zone](./uplink.md#captures-de-zone)).
- <Model name="ZonePlan" /> et <Model name="ZonePlanChange" /> : la suggestion d'une zone (un plan par zone) et ses changements ordonnés (ajout, retrait, remplacement), chacun avec son état (`pending`, `applied`, `conflicted`), s'il est contrôlé (`checked`) et s'il a été déplacé par le joueur. Données du joueur : `game:import` n'y touche pas. Voir [Suggestions de zone](../product/plans.md).
- <Model name="UplinkCommand" /> : les commandes envoyées au mod et leur réponse.

### Chat en jeu

- <Model name="ChatConversation" /> : une conversation par colonie, avec l'identifiant de la session Claude Code à reprendre (`claude_session_id`).
- <Model name="ChatQuestion" /> : une question du joueur, son statut, la réponse ou l'erreur, la durée et les outils utilisés.
- <Model name="ChatContextItem" /> : chaque élément de contexte envoyé avec une question (sélection, zone, boîte, pièce, objet), avec son indice de calque et la raison d'un éventuel refus du mod.

Une zone peut être **temporaire** (`zones.transient`) : le planner la crée pour lire une boîte, une pièce ou un objet hors de toute zone. Elle sert au chat seulement, n'apparaît dans aucune liste ni page, et n'est jamais archivée par la liste des zones du jeu.
