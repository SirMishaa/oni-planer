---
title: Serveur MCP
description: Le serveur MCP local oni, qui laisse un assistant IA explorer ta colonie (zones, cartes, entités, données du jeu, captures) depuis Claude Code.
sources: ["routes/ai.php", "app/Mcp/**", "app/Services/Mcp/**", "app/Services/Zones/ZoneCritters.php", "app/Services/Zones/ZoneCritterState.php", "app/Services/Zones/ZoneGeometry.php", "app/Services/Zones/ZoneProblems.php", "app/Services/Zones/CritterProblems.php", "app/Services/Zones/ZonePlants.php", "app/Services/Zones/ZoneFeeding.php", ".mcp.json", "app/Services/Mcp/McpBuildingSettings.php", "app/Services/Zones/RanchProblems.php"]
order: 7
---

# Serveur MCP

`oni` est un serveur MCP local, déclaré dans `routes/ai.php` et lancé par Claude Code depuis `.mcp.json`. Il donne à l'assistant la colonie synchronisée par Uplink et les données importées du jeu, dans un format texte compact pensé pour lui : il peut répondre à des questions sur ta partie, situer les choses dans une zone et conseiller sur un layout.

Rien à lancer à la main : Claude Code démarre le serveur avec <Artisan command="mcp:start" /> au début d'une session. Après avoir ajouté ou modifié un outil, redémarre la session pour qu'il soit rechargé.

## Les outils

| Outil | Pour quoi faire |
|---|---|
| `colony_status` | État de la synchro (dernier message, session, âge des données de chaque zone, commandes Uplink) et résumé de la colonie : duplicants, nourriture, créatures et plantes par espèce. |
| `list_zones` | Une ligne par zone active : taille, origine, fraîcheur, nombre de problèmes, captures. |
| `zone_overview` | Une zone en un écran : bâtiments et états, plantes, créatures, population par pièce ou bassin (verdict du jeu et critters à retirer), alimentation par groupe de critters (ce qu'il mange selon son métabolisme, stock des mangeoires, cycles tenus ou manque, production, groupe bloqué par les filtres et aliments à cocher, marqués `(derived)`), réglages des mangeoires et points de dépôt (filtre, capacité), éléments (masse, plage de température), problèmes (alertes des créatures et alertes de stock, de filtre et de point de dépôt comprises). |
| `zone_map` | Où sont les choses : la carte texte de la zone (voir plus bas). |
| `zone_cells` | Le détail case par case d'un petit rectangle (100 cases au plus). |
| `entity` | Tout ce que le jeu envoie sur une plante, un bâtiment (avec son filtre et sa capacité), une créature ou un œuf, par son identifiant d'instance. |
| `lookup` | Un objet du jeu (élément, bâtiment, créature, plante, aliment, entrée du codex) par identifiant ou par nom, en français ou en anglais. |
| `zone_captures` | Les images de la zone rendues par le jeu (WebP), pour comparer un avant / après, et l'adresse de la page de la zone. |
| `request_capture` | Demande une nouvelle capture au jeu, comme le bouton de la page de la zone. Seul outil qui agit. |

Chaque réponse commence par une ligne d'en-tête : colonie, cycle, âge des données. `STALE` veut dire que le jeu n'envoie plus rien depuis plus d'une minute.

<AccordionGroup>
<Accordion title="La carte texte (zone_map)">
La carte affiche deux grilles alignées sur le cadre de la zone, un caractère par case, les lignes numérotées par leur y local et une règle de colonnes :

- **T** (terrain) : tuiles (`#` tuile, `=` tuile grillagée), gaz (`.` oxygène, `,` oxygène pollué, `_` CO₂…), liquides (`~` eau, `%` eau polluée…), solides en majuscules (`D` terre, `S` sable…). `:` marque un gaz ou un liquide de l'anneau de bord, hors de la zone.
- **O** (occupation) : les bâtiments sur toute leur emprise, les plantes et les créatures en minuscules.

Les tuiles, éléments et bâtiments courants ont un caractère fixe, le même partout. Les autres reçoivent un caractère propre à la zone : stable d'un rectangle à l'autre de la zone, mais pas d'une zone à l'autre.

La légende ne liste que les caractères présents, avec une ligne par préfab et ses instances (identifiant, position, taille, écarts d'état comme `NOT operational`). Au-delà de 40 × 25 cases, l'outil demande un rectangle. `zone_map` et `zone_cells` découpent le rectangle demandé au cadre de la zone et le disent quand il ne le touche pas.
</Accordion>
<Accordion title="Coordonnées, unités et valeurs dérivées">
- Les listes donnent des coordonnées monde `w(x,y)` : x = case modulo la largeur de la grille, y = case divisée par cette largeur, y vers le haut. Les cartes numérotent en local depuis leur origine, écrite dans l'en-tête.
- Un identifiant de zone retrouve aussi une zone temporaire créée par le chat en jeu ; un nom, jamais. Ces zones n'apparaissent pas dans `list_zones`.
- L'ancre d'un bâtiment est sa case en bas à gauche ; l'emprise monte depuis elle (`ZoneGeometry`).
- Unités : kg, °C, secondes ; un cycle dure 600 s.
- Les créatures et les œufs viennent des états de la zone (`ZoneCritterState`) : `entity` décrit une créature (région, surpeuplement, statuts, fertilité, chances du prochain œuf) ou un œuf (incubation, viabilité, porteur) par son identifiant. La carte marque les œufs `o`. Avec un ancien mod, les créatures de toute la colonie sont rattachées à une zone par leur case (`ZoneCritters`) et marquées `derived`.
- `unavailable` veut dire que le jeu n'a pas envoyé la valeur : rien n'est deviné.
</Accordion>
<Accordion title="Règles du code">
- Les outils vivent dans `app/Mcp/Tools`, un par fichier, enregistrés dans `OniServer`. Les outils de lecture portent `#[IsReadOnly]` et n'écrivent rien en base.
- Le processus stdio vit longtemps : aucun état de colonie ou de zone n'est gardé entre deux appels, chaque appel relit la base. Les noms traduits des objets du jeu et les régimes des critters, eux, restent en cache : après un `game:import`, relance la session Claude Code.
- Les règles de problèmes, d'emprise et de croissance sont partagées avec la page des zones (`ZoneProblems`, `CritterProblems`, `ZoneGeometry`, `ZonePlants`, `ZoneFeeding`) : ne pas les recopier dans un outil.
- Les tests appellent chaque outil avec `OniServer::tool(...)` dans `tests/Feature/Mcp`.
</Accordion>
</AccordionGroup>

Pour la synchro elle-même, voir [Uplink](./uplink.md).
