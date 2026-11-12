---
title: Tests et performance
description: Lancer les suites de tests et mesurer le traitement des messages Uplink avec le benchmark k6 et Excimer.
sources: ["composer.json", "tests/php/coverage.ini", "app/Console/Commands/UplinkBenchCommand.php", "tests/Performance/**"]
order: 6
---

# Tests et performance

## Tests

```bash
php artisan test --compact                 # tout
php artisan test --compact --filter=Zone   # un sous-ensemble
composer test                              # couverture de types, unitaires + couverture, lint, phpstan
```

`composer test:unit` charge pcov via `tests/php/coverage.ini` et exige 100 % de couverture. pcov n'est chargé nulle part ailleurs, car il désactive le JIT.

## Mesurer avant d'optimiser

Avant de contourner une limite supposée (taille des messages, latence), mesure-la. <Artisan command="uplink:bench" /> envoie des messages de plusieurs tailles à travers le Reverb local avec k6, et rapporte l'aller-retour, le transit, le temps de décodage PHP et la mémoire.

```bash
php artisan uplink:bench --sizes=100,1000,4000 --encoding=base64
php artisan uplink:bench --profile   # profil Excimer d'un Reverb temporaire
```

Avec `--profile`, la commande lance un Reverb temporaire avec le profileur Excimer, affiche les fonctions les plus coûteuses et écrit un fichier speedscope. Les résumés sont enregistrés dans `storage/app/private/bench/`.

<Callout type="important">
Ne jamais profiler ni benchmarker avec Xdebug chargé : il double les chiffres.
</Callout>
