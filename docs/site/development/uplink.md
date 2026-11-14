---
title: Uplink
description: Comment le mod Uplink parle au planner via Reverb, comment chaque message est traité en file et les règles d'ordre et de commande.
sources: ["app/Listeners/RouteUplinkMessage.php", "app/Listeners/Uplink/**", "app/Enums/UplinkMessageType.php", "app/Jobs/Uplink/**", "app/Events/Uplink/**", "app/Services/Uplink/**", "app/Actions/Uplink/**", "app/Events/ZoneUpdated.php", "app/Models/UplinkCommand.php", "app/Console/Commands/UplinkInstallCommand.php", "app/Console/Commands/UplinkResyncZoneCommand.php", "app/Console/Commands/UplinkCaptureCommand.php", "app/Http/Controllers/ZoneCaptureController.php", "app/Services/Zones/ZonePresenter.php", "app/Services/Zones/ZoneProblems.php"]
order: 4
---

# Uplink

Uplink est un mod du jeu (dépôt séparé) qui se connecte à Reverb comme client Pusher et envoie des **client events**. Le listener `RouteUplinkMessage` les reçoit dans Reverb et met chacun en file sur le job de son type ; les workers les décodent et les enregistrent. Tout passe par le WebSocket : le jeu n'a aucun endpoint HTTP.

## Canaux

| Canal | Événements | File |
| --- | --- | --- |
| `uplink` | `client-colony.state` | `uplink-state` |
| `uplink-data` | `client-session.start`, `client-zone.list`, `client-zone.layout`, `client-command.ack`, `client-zone.capture` (fiables) | `uplink-reliable` |
| `uplink-data` | `client-zone.slow`, `client-zone.fast` (dernier état) | `uplink-state` |

Chaque événement a un job dans `app/Jobs/Uplink` (par exemple `ReceiveZoneLayout`), listé par l'enum `UplinkMessageType`. Ajouter un type de message, c'est un cas de l'enum et un job.

Les données lourdes des zones ont leur propre canal, car un client event est diffusé à tous les abonnés du canal.

## Format des messages

Sur `uplink-data`, le `data` de l'événement vaut `{"payload": "<base64 du gzip du JSON>"}`. Garder le contenu opaque compte : Reverb valide les client events, et son validateur parcourt un `data` JSON décodé, ce qui rendait les gros messages deux à trois fois plus lents. `UplinkPayload::decode` le déballe (gzip détecté par ses octets magiques, plafond à 64 Mo).

## Fiabilité

- **Les événements fiables** portent un identifiant de corrélation. Une fois l'événement enregistré, le worker diffuse `uplink.ack` sur `uplink-data` avec cet identifiant (`UplinkMessageAccepted`). Le mod renvoie tant qu'il n'a pas d'ack, donc la réception est idempotente.
- **Ils sont traités dans l'ordre** : un seul worker lit `uplink-reliable`, donc le début de session passe avant la liste, et la liste avant les layouts.
- **Les événements d'état** (`slow`, `fast`) ne gardent que le dernier et ne sont jamais acquittés.
- Un layout pour une zone que le site ne connaît pas encore n'est pas acquitté ; le mod le renvoie après la liste des zones.

## Bord de zone

Une zone n'est qu'un ensemble de cases. Le mod lit aussi un **anneau de bord** autour d'elle : les cases à quelques cases d'une case de la zone, diagonales comprises (`ZoneBorder`). Sa largeur se règle avec `zoneBorderCells` dans `uplink.json` et le layout la renvoie dans `borderCells`.

- L'état rapide (`buildings`) liste les bâtiments qui touchent la zone **ou** l'anneau, sur le même monde que la zone. Le cadre du layout s'élargit pour les dessiner.
- Le terrain, les températures et les plantes restent ceux des cases de la zone : les murs ne faussent pas les moyennes.
- Côté site, `ZonePresenter` sépare les passages (`ZoneProblems::PASSAGE_PREFABS` : portes, échelles, poteaux, escaliers) dans `borderEntities`. Ils ne comptent ni dans les entités, ni dans les problèmes, ni dans le résumé des zones. Tout autre bâtiment, de mod ou inconnu, compte comme contenu.

## Règles du listener

`RouteUplinkMessage` tourne **dans le processus Reverb**, pour chaque message :

- il ne fait que lire l'enveloppe et mettre le message en file : jamais de décodage du payload, jamais de base de données, car un travail lourd fige tous les sockets, pages comprises ;
- il ne lève **jamais d'exception**, sinon la boucle d'événements de Reverb meurt.

Les jobs enregistrent puis lèvent un événement métier (`ZoneListSynced`, `ZoneLayoutStored`, `ZoneStateStored`). Les listeners de ces événements diffusent `ZoneUpdated` aux pages ouvertes (au plus une fois toutes les 2 s par zone pour les états rapides) et demandent un resync si besoin.

Les messages sur `uplink` sont journalisés (voir l'inspecteur). Seules les colonies vues le plus récemment sont gardées, plus toute colonie qui possède des zones.

## Ordre des états

Plusieurs workers lisent `uplink-state` : un état peut finir après un plus récent. L'écriture est donc conditionnelle, en une seule requête :

- dans une même session, seul un `gameSeconds` plus grand remplace l'état enregistré ;
- un état d'une autre session que la session courante de la colonie (le dernier `client-session.start`) est ignoré. Recharger une ancienne sauvegarde fait reculer `gameSeconds`, et ses états ne doivent jamais se mélanger à ceux de la nouvelle session ;
- un état sans `sessionId` (mod plus ancien) compte comme de la session courante.

`previous_slow_temperature` ne garde que des températures de la même session. L'état colonie suit la même règle quand il porte `sessionId` et `gameSeconds` ; sans eux, le dernier arrivé gagne.

## Commandes du site vers le mod

Le site envoie `uplink.command` sur `uplink-data` : `{schema, id, colonyId, type, issuedAt, expiresAt, payload}`. Le mod dont la colonie chargée est `colonyId` l'exécute et répond `client-command.ack` avec `commandId` et un statut `done`, `refused` ou `expired` ; un autre mod l'ignore. Une commande sans réponse passe `expired` après `expiresAt` (vérifié à la lecture, sans planificateur).

<Model name="UplinkCommand" />

Deux commandes existent :

- `zone.resync` : le mod renvoie le layout et les deux états d'une zone. Le site l'envoie seul pour une zone toujours sans layout 30 s après sa création (puis attend 10 min si le mod n'a pas répondu), ou à la main :

<Artisan command="uplink:resync-zone" />

- `zone.capture` : le mod dessine la zone (voir [Captures de zone](#captures-de-zone)). Le bouton **Capturer** de la page de la zone l'envoie (<Route name="zones.captures.store" />), ou à la main, en attendant l'image :

<Artisan command="uplink:capture" />

## Captures de zone

Le mod dessine la zone comme le timelapse du jeu : il fige l'écran, rend le cadre de la zone (celui du layout) à 32 pixels par case, puis remet la vue en place. Il écrit le PNG dans son propre dossier, `captures/{zoneId}/`, puis envoie `client-zone.capture` avec le nom du fichier, la taille, l'heure de jeu, la signature du layout montré et le déclencheur (`manual` avec l'identifiant de la commande, ou `auto`). Aucune image ne passe par Reverb : le site lit le fichier par le dossier du mod qu'il connaît (`game.uplink_mod_path`), donc les deux doivent tourner sur la même machine.

`StoreZoneCapture` le convertit en WebP qualité 90 sur le disque public, enregistre une ligne `zone_captures`, supprime la copie du mod, garde les 15 dernières de la zone et diffuse `zone.updated` (genre `capture`). Le layout porte la même signature : une capture montre le plan actuel quand les deux sont égales.

- **Automatique** : après un chantier, quand la signature du layout a changé puis n'a plus bougé pendant 120 s de jeu, au plus une capture automatique par zone toutes les 10 min, une seule par passe d'échantillonnage. L'état est gardé dans la sauvegarde.
- **Refus et échecs** : une zone inconnue, vide ou sur un autre astéroïde est refusée dans la réponse à la commande. Un échec pendant le rendu, ou un fichier absent ou illisible côté site, arrive sur `client-zone.capture` et passe la commande en `refused` avec la raison.
- **Idempotence** : le message est toujours acquitté. Un renvoi d'une capture déjà enregistrée ne change rien.

<Accordion title="Versions des messages">

Chaque type de message a sa propre version (`schema`). Un champ ajouté et optionnel ne la change pas ; une version plus récente que celle que le site connaît est refusée (avertissement au plus une fois par heure, par type de message et par colonie ; pour l'état colonie, le refus apparaît dans le journal de l'inspecteur). Le site et le mod peuvent donc être mis à jour dans n'importe quel ordre.

</Accordion>

## Relier une partie

<Artisan command="uplink:install" />
