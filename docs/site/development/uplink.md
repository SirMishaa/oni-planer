---
title: Uplink
description: Comment le mod Uplink parle au planner via Reverb, ce que chaque listener enregistre et les règles qu'ils suivent.
sources: ["app/Listeners/IngestColonyState.php", "app/Listeners/IngestUplinkData.php", "app/Services/Uplink/**", "app/Actions/Uplink/**", "app/Events/ZoneUpdated.php", "app/Console/Commands/UplinkInstallCommand.php"]
order: 4
---

# Uplink

Uplink est un mod du jeu (dépôt séparé) qui se connecte à Reverb comme client Pusher et envoie des **client events**. Des listeners sur l'événement `MessageReceived` de Reverb les enregistrent. Tout passe par le WebSocket : le jeu n'a aucun endpoint HTTP.

## Canaux

| Canal | Événements | Enregistré par |
| --- | --- | --- |
| `uplink` | `client-colony.state` | `IngestColonyState` → `ColonyIngestor` |
| `uplink-data` | `client-session.start`, `client-zone.list`, `client-zone.layout` (fiables) ; `client-zone.slow`, `client-zone.fast` (dernier état) | `IngestUplinkData` |

Les données lourdes des zones ont leur propre canal, car un client event est diffusé à tous les abonnés du canal.

## Format des messages

Sur `uplink-data`, le `data` de l'événement vaut `{"payload": "<base64 du gzip du JSON>"}`. Garder le contenu opaque compte : Reverb valide les client events, et son validateur parcourt un `data` JSON décodé, ce qui rendait les gros messages deux à trois fois plus lents. `UplinkPayload::decode` le déballe (gzip détecté par ses octets magiques, plafond à 64 Mo).

## Fiabilité

- **Les événements fiables** portent un identifiant de corrélation. Une fois enregistré, le listener répond à l'émetteur par `uplink.ack` sur `uplink-data`, avec cet identifiant. Le mod renvoie tant qu'il n'a pas d'ack, donc la réception est idempotente.
- **Les événements d'état** (`slow`, `fast`) ne gardent que le dernier et ne sont jamais acquittés.
- Un layout pour une zone que le site ne connaît pas encore n'est pas acquitté ; le mod le renvoie après la liste des zones.

## Règles pour les listeners

Les listeners tournent **dans le processus Reverb**, pour chaque message :

- ils doivent rester légers et **ne jamais lever d'exception**, sinon la boucle d'événements de Reverb meurt ;
- les mises à jour du navigateur passent par des broadcasts en file (`ZoneUpdated`), jamais par du travail synchrone.

Les messages sur `uplink` sont journalisés (voir l'inspecteur). Seules les colonies vues le plus récemment sont gardées, plus toute colonie qui possède des zones.

## Relier une partie

<Artisan command="uplink:install" />
