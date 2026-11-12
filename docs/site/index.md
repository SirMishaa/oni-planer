---
title: Introduction
description: Ce qu'est ONI Planner, d'où viennent ses données et comment cette doc est organisée.
sources: ["README.md", "composer.json", "routes/web.php"]
---

# ONI Planner

**ONI Planner** est un outil de planification pour [Oxygen Not Included](https://www.klei.com/games/oxygen-not-included). Il présente les éléments, bâtiments et créatures du jeu, aide à équilibrer un ranch et suit ta colonie en direct grâce au mod Uplink.

Chaque chiffre vient du jeu lui-même : un export en jeu (le mod OniExtract) est importé dans la base du site, donc les pages correspondent à ta version du jeu et à tes mods installés.

## Par où commencer

- **Tu joues ?** Lis [Bien démarrer](./product/getting-started.md), puis la page de l'outil qu'il te faut.
- **Tu travailles sur le code ?** Lis [Architecture](./development/architecture.md), puis [Installation locale](./development/local-setup.md).

## Construit avec

Laravel 13 sur PHP 8.5, Inertia v3 avec Vue 3 et TypeScript, Octane sur FrankenPHP pour les pages, et Reverb pour le lien en direct avec le jeu.
