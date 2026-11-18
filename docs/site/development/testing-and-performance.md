---
title: Tests et performance
description: Lancer les suites de tests et mesurer le traitement des messages Uplink avec le benchmark k6 et Excimer.
sources: ["composer.json", "phpunit.xml", "tests/php/coverage.ini", "app/Console/Commands/UplinkBenchCommand.php", "app/Services/Uplink/IngestBench.php", "app/Listeners/Uplink/RecordBenchLatency.php", "tests/Performance/**", "tests/Feature/Uplink/MessageBaselineTest.php"]
order: 6
---

# Tests et performance

## Tests

```bash
php artisan test --compact                 # tout
php artisan test --compact --filter=Zone   # un sous-ensemble
composer test                              # couverture de types, unitaires + couverture, lint, phpstan
```

Les tests tournent sur la base PostgreSQL `oni_planner_testing` (voir [Installation locale](./local-setup.md)).

Si une exécution ne se termine pas, lance-la avec `OTEL_SDK_DISABLED=true` devant la commande.

`composer test:unit` charge pcov via `tests/php/coverage.ini` et exige 100 % de couverture. pcov n'est chargé nulle part ailleurs, car il désactive le JIT.

## Temps de traitement des messages Uplink

`tests/Feature/Uplink/MessageBaselineTest.php` tourne avec la suite normale. Pour chaque type de message, il passe un message de grosse colonie (format réel, dans `tests/Fixtures/Uplink/perf`) dans son job, et vérifie deux choses :

- la médiane reste sous un budget d'environ 3 fois la médiane mesurée (minimum 5 ms, une remesure avant d'échouer) ;
- le nombre de requêtes d'un passage est exact (un état rapide en fait 3, dont la lecture de la zone par le contrôle de surpeuplement).

Le message `client-chat.ask` y a sa fixture (`chat-ask.json`) comme les autres types. Un nouveau type de message fait échouer ce test tant qu'il n'a pas de fixture, de budget et de nombre de requêtes.

## Mesurer avant d'optimiser

Avant de contourner une limite supposée (taille des messages, latence), mesure-la. <Artisan command="uplink:bench" /> envoie des messages de plusieurs tailles à travers le Reverb local avec k6, et rapporte l'aller-retour, le transit, le temps de décodage PHP et la mémoire.

```bash
php artisan uplink:bench --sizes=100,1000,4000 --encoding=base64
php artisan uplink:bench --profile   # profil Excimer d'un Reverb temporaire
```

Avec `--profile`, la commande lance un Reverb temporaire avec le profileur Excimer, affiche les fonctions les plus coûteuses et écrit un fichier speedscope. Les résumés sont enregistrés dans `storage/app/private/bench/`.

## Ingestion de bout en bout

Avant un déploiement ou un gros merge, mesure toute la chaîne (Reverb, queue, workers, base) avec la stack de dev lancée :

```bash
php artisan uplink:bench --ingest                       # 7, 50, 150 et 400 msg/s, 20 s chacun
php artisan uplink:bench --ingest --rates=7,150 --duration=10
```

La commande crée une colonie de bench (30 zones par défaut), envoie des états de grosse colonie à débit fixe avec k6, attend que la file se vide, puis affiche le débit, la latence (envoi → état enregistré, médiane, p95, max) et les pertes. La colonie de bench est supprimée à la fin, même en cas d'échec.

<Accordion title="Résultats du 13/11/2026">

États de 73 Ko (lent) et 34 Ko (rapide), 30 zones, 5 workers :

| Débit | Perdus | Médiane | p95 |
| --- | --- | --- | --- |
| 7/s (30 zones au rythme du mod) | 0 | 43 ms | 98 ms |
| 150/s | 0 | 24 ms | 91 ms |
| 400/s | 1 | 522 ms (saturé vers 280/s) | 999 ms |

</Accordion>

<Callout type="important">
Ne jamais profiler ni benchmarker avec Xdebug chargé : il double les chiffres.
</Callout>
