---
title: Architecture
description: Comment les données du jeu et celles de la colonie en direct arrivent au planner, et quel processus fait quoi.
sources: ["README.md", "app/Services/GameImport/**", "app/Services/Uplink/**", "app/Listeners/**", "Caddyfile", "composer.json"]
order: 1
---

# Architecture

Le planner a deux chemins de données. **Les données de référence** (éléments, bâtiments, créatures, plantes…) sont importées depuis un export du jeu et changent rarement. **Les données de colonie** arrivent en direct pendant la partie, via le mod Uplink et Reverb.

```mermaid
---
config:
  flowchart:
    curve: basis
    nodeSpacing: 36
    rankSpacing: 48
    padding: 14
  themeVariables:
    fontSize: 16px
---
flowchart TB
  subgraph Jeu[Dans le jeu]
    OE[Mod OniExtract]
    UL[Mod Uplink]
  end
  OE -->|export JSON| EX[(Dossier d'export)]
  EX -->|game:sync-export| GD[(game-data/)]
  GD -->|game:import| DB[(Base de données)]
  GD -->|game:import-assets| AS[(public/assets/game)]
  UL -->|client events WebSocket| RV[Processus Reverb]
  RV -->|listeners| DB
  DB --> OC[Pages Octane / FrankenPHP]
  RV -->|broadcasts| BR[Navigateur]
  OC --> BR
  class OE,UL game
  class RV,OC,BR site
  class EX,GD,DB,AS store
```

## Processus

| Processus | Lancé par | Rôle |
| --- | --- | --- |
| Octane (FrankenPHP) | `composer devWithOctane` | Sert les pages Inertia. OPcache et le JIT tracing sont activés dans le `Caddyfile`. |
| Reverb | `php artisan reverb:start` | Serveur WebSocket. Le jeu s'y connecte ; ses listeners enregistrent ce que le jeu envoie. |
| Worker de queue | `php artisan queue:listen` | Exécute les broadcasts en file vers le navigateur. |
| Vite | `bun run dev` | Serveur de dev frontend avec HMR. |

## Carte du code

- `app/Services/GameImport/` : les readers transforment l'export OniExtract en enregistrements, les importers les persistent. Voir [Import des données du jeu](./game-data-import.md).
- `app/Services/Uplink/` et `app/Listeners/` : tout ce que le jeu envoie via Reverb. Voir [Uplink](./uplink.md).
- `app/Services/Viability/`, `app/Services/Critters/` : presenters et calculs derrière les pages colonie et créatures.
- `app/Data/` : objets spatie/laravel-data passés à Inertia ; leurs types TypeScript sont générés pour les pages Vue.
- `resources/js/pages/` : une page Vue par route ; les liens passent par Wayfinder, jamais par des URL écrites à la main.
