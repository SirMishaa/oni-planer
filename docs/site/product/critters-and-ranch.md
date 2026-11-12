---
title: Créatures et ranch
description: Lire chaque famille de créatures et ses variantes, puis équilibrer la nourriture, les productions et les œufs d'un ranch par cycle.
sources: ["app/Http/Controllers/CritterController.php", "app/Http/Controllers/RanchController.php", "app/Services/Critters/**", "resources/js/pages/Critters/**", "resources/js/pages/Ranch/**", "resources/js/composables/useRanch.ts"]
order: 4
---

# Créatures et ranch

## Créatures

La page des créatures affiche une carte par famille (Hatch, Pacu, Shine Bug…). Cherche par nom pour réduire la grille. Ouvre une famille pour voir toutes ses variantes côte à côte et en choisir une : son régime, ce qu'elle produit et les œufs qu'elle pond.

## Planificateur de ranch

Le planificateur additionne ce qu'un groupe de créatures mange et produit à chaque cycle.

1. Tape le nom d'une créature dans **Ajouter une créature…** et choisis la variante.
2. Indique combien tu en élèves et la nourriture de chacune.
3. Lis le bilan : chaque élément affiche sa quantité nette par cycle (positive si produite, négative si consommée), et les œufs sont listés à part.

Le ranch est enregistré dans l'adresse de la page : tu peux le mettre en favori ou envoyer le lien, l'autre personne verra le même ranch.
