---
title: Installation locale
description: Installer l'application, importer les données du jeu et lancer la stack de dev avec Octane et Reverb.
sources: ["README.md", "composer.json", ".env.example", "app/Console/Commands/UplinkInstallCommand.php"]
order: 2
---

# Installation locale

Il faut PHP 8.5, Composer, Bun et une copie d'Oxygen Not Included.

<Steps>
<Step title="Installer">

```bash
composer install
bun install
cp .env.example .env
php artisan key:generate
php artisan migrate
```

</Step>
<Step title="Importer les données du jeu">
Lance l'export OniExtract depuis le menu pause du jeu, puis les trois commandes d'import. Voir [Import des données du jeu](./game-data-import.md).
</Step>
<Step title="Lancer la stack">

```bash
composer devWithOctane
```

Lance ensemble Octane, le worker de queue, Pail, Vite et Reverb, avec le site sur le port 8003. `composer dev` fait pareil avec `php artisan serve` à la place d'Octane.
</Step>
<Step title="Relier le jeu (optionnel)">
Compile et installe le mod Uplink, puis lance <Artisan command="uplink:install" /> pour écrire ses réglages dans le dossier du mod.
</Step>
</Steps>

## Conventions

- Utiliser `bun` et `bunx`, jamais `npm` ni `npx`.
- Vite tourne avec HMR : pas besoin de `bun run build` pendant le dev.
- Xdebug reste désactivé : il double les temps et fausse les benchmarks. Profiler avec Excimer (voir [Tests et performance](./testing-and-performance.md)).
- pcov n'est chargé que par `composer test:unit`, car il désactive le JIT.
