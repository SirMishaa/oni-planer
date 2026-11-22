---
title: 'Suggestions de zone'
description: "Voir sur la carte d'une zone les changements qu'un assistant te propose, les déplacer ou les supprimer, et suivre leur avancement dans le jeu."
sources:
  - app/Models/ZonePlan.php
  - app/Models/ZonePlanChange.php
  - app/Enums/ZonePlanChangeState.php
  - app/Enums/ZonePlanChangeKind.php
  - app/Http/Controllers/ZonePlanChangeController.php
  - app/Actions/Zones/ReconcileZonePlan.php
  - 'app/Services/Zones/Plans/**'
  - resources/js/pages/Zones/Show.vue
  - resources/js/components/zones/ZonePlanPanel.vue
  - resources/js/components/zones/zonePlan.ts
  - lang/fr/zones.php
order: 8
---

# Suggestions de zone

Une **suggestion** est une variante d'une zone : des bâtiments à ajouter, à retirer ou à remplacer, que Claude te propose depuis une session de code (voir [Serveur MCP](../development/mcp.md#suggestions-de-zone)). Rien n'est posé dans ta partie : c'est à toi de construire.

## Voir la suggestion

Quand une zone a une suggestion, la carte (<Route name="zones.index" />) affiche un sélecteur **Vue** avec deux boutons :

- **Actuel** : la zone telle que le jeu l'a envoyée.
- **Suggestion** : la zone telle qu'elle serait une fois les changements faits.

Le panneau **Suggestion** liste chaque changement avec sa raison en une phrase. Sur la carte, les bâtiments prévus sont cernés ; ceux que le plan retire sont marqués comme tels. La légende donne les deux teintes : **Changement prévu** et **Retiré par le plan**.

## Déplacer ou supprimer

- **Déplacer** : glisse un ajout sur la carte. Il n'atterrit que sur une position où il tient (dans la zone, sans chevaucher, sur un sol ou sous un plafond s'il en faut un). Un changement déplacé par toi est marqué **Déplacé par vous**. Si le déplacement ferait tenir moins bien un autre changement, il est refusé et rien ne bouge.
- **Supprimer** : le bouton **Supprimer** retire un changement. Si d'autres changements ne tiennent que grâce à lui, le planner refuse et te le dit.

Ces deux actions passent par <Route name="zones.plan.changes.update" /> et <Route name="zones.plan.changes.destroy" />.

## Les états d'un changement

| État | Sens |
| --- | --- |
| **en attente** | Pas encore fait dans le jeu. |
| **appliqué** | Le jeu a construit le bâtiment : le changement est fait. |
| **en conflit** | L'emplacement n'est plus libre (un bâtiment ou une tuile a pris la place). Il repasse en attente dès que la place se libère. |
| **non vérifié** | Une couche que le jeu n'envoie pas (câbles, tuyaux, logique) : elle est dessinée, jamais contrôlée. |

<Accordion title="Comment la suggestion suit ta partie">

Chaque fois que le jeu envoie le plan de la zone, le planner relit la suggestion : un ajout construit devient **appliqué** (et repasse en attente si le bâtiment disparaît), un ajout qui ne tient plus devient **en conflit**, et un retrait dont la cible a disparu s'efface. Une suggestion sans plus aucun changement est supprimée. Les changements **non vérifiés** ne sont jamais touchés. La page se met à jour seule, comme le reste de la zone.

</Accordion>

<Accordion title="Et le chat en jeu ?">

Le [chat en jeu](./chat.md) peut lire une suggestion mais jamais l'écrire : une suggestion se propose depuis une session de code.

</Accordion>
