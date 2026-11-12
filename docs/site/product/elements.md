---
title: Éléments
description: Parcourir tous les éléments du jeu et lire leur état, leur catégorie, leurs propriétés thermiques et leurs changements d'état.
sources: ["app/Http/Controllers/ElementController.php", "resources/js/pages/Elements/**"]
order: 2
---

# Éléments

La liste des éléments est la page d'accueil du planner. Elle montre chaque élément utilisé par le jeu, classé par état (solide, liquide, gaz), avec sa catégorie de matériau.

Ouvre un élément pour voir son détail :

- sa description tirée du jeu, sa toxicité et sa masse molaire ;
- ses propriétés thermiques (capacité thermique, conductivité thermique et valeurs associées) ;
- ce qu'il devient quand il a trop froid ou trop chaud ;
- ses tags, tels que le jeu les définit.

Les éléments désactivés dans le jeu ne sont pas listés.
