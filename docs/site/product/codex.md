---
title: Codex
description: Lire les entrées de la base de données du jeu, avec des liens vers les pages des bâtiments et éléments correspondants.
sources: ["app/Http/Controllers/CodexController.php", "resources/js/components/codex/**"]
order: 5
---

# Codex

Le codex reprend la base de données du jeu dans le planner : chaque entrée garde sa catégorie, son texte dans ta langue et son image.

Quand une entrée parle d'un bâtiment ou d'un élément, elle renvoie vers la page de cet objet dans le planner. Les autres entrées s'affichent en texte seul pour l'instant.
