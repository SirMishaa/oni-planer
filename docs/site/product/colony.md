---
title: Ma colonie
description: Relier le jeu au planner avec le mod Uplink, puis suivre la viabilité alimentaire de ta colonie, la carte de tes zones et voir ce que le jeu envoie.
sources: ["app/Http/Controllers/ColonyController.php", "app/Console/Commands/UplinkInstallCommand.php", "app/Services/Viability/**", "resources/js/pages/Colony/**", "lang/fr/colony.php", "app/Http/Controllers/ZoneController.php", "app/Services/Zones/ZonePresenter.php", "resources/js/components/zones/**", "lang/fr/zones.php", "app/Services/Zones/ZonePopulation.php"]
order: 6
---

# Ma colonie

Les pages **Ma colonie** suivent la partie en cours. Elles ont besoin du mod **Uplink**, qui envoie l'état de ta colonie au planner pendant que tu joues.

## Relier le jeu

<Steps>
<Step title="Installer le mod">
Compile et installe le mod Uplink, puis active-le dans le menu des mods du jeu.
</Step>
<Step title="Le relier au planner">
Depuis le dossier du planner, lance `php artisan uplink:install`. La commande écrit les réglages du mod (où envoyer les données) dans son dossier.
</Step>
<Step title="Charger une sauvegarde">
Charge une sauvegarde dans le jeu. Les pages de la colonie se remplissent en quelques secondes.
</Step>
</Steps>

Le haut de chaque page indique si le jeu est **en direct**, quand il a été vu pour la dernière fois et le cycle en cours.

## Viabilité : nourriture

La page de viabilité répond à une question : **tes duplicants vont-ils manquer de nourriture ?**

- Le verdict dit si la nourriture est **critique**, **juste** ou **tient**, et à quel cycle elle s'épuise au rythme actuel.
- Le graphique projette ton stock sur les prochains cycles : sans récolte, avec les récoltes automatiques de tes fermes, et avec les plantes sauvages en plus.
- **Récoltes à venir** liste chaque source de nourriture, avec une marque pour chaque récolte au cycle où elle mûrit.

Si le jeu n'a pas pu lire quelque chose (duplicants, stock de nourriture ou plantes), la page dit ce qui manque au lieu de deviner.

## Carte d'une zone

Ouvre une zone (<Route name="zones.index" />) pour voir sa carte, dessinée comme dans le jeu : le terrain porte ses textures et ses bords de biome, les liquides coulent et les gaz ondulent. Passe la souris sur une case pour lire son élément, sa masse et sa température.

- Le sélecteur de **calque** choisit ce que la carte montre : croissance, matériaux, température ou problèmes.
- **Grille** affiche ou masque le quadrillage des cases.
- La carte s'ajuste à ton écran (100 %). Les boutons **−** et **+** changent le zoom de 10 points, un clic sur le pourcentage revient à 100 %, et Ctrl (ou ⌘) avec la molette zoome sous le curseur. Glisse la carte pour la déplacer.
- Clique une plante ou un bâtiment pour le sélectionner : il se soulève, cerné de blanc, et ses détails s'affichent à côté.
- Les cases que le jeu n'a pas lues restent hachurées.
- **Comparer avec le jeu** affiche la dernière capture du jeu à côté de la carte (en dessous si la zone est plus large que haute), à la même échelle et au même zoom (voir [Captures du jeu](#captures-du-jeu)).

### Population

Le bloc **Population**, à côté de la carte, est toujours visible quand le jeu a envoyé les critters. Il montre une pièce ou un bassin par ligne, avec sa taille en cases et une jauge : les critters, les œufs à l'éclosion et la limite. Dessous, chaque espèce affiche son nombre, l'espace qu'elle prend et, s'il faut en retirer, `−N`.

Quand une pièce est **À l'étroit** ou **Confinée**, le verdict de la zone annonce **Ponte arrêtée**. Le bloc affiche « Aucun critter » si la zone n'en a pas, et « Pas encore reçu » tant que le jeu n'a rien envoyé. Les états et le calcul du retrait sont détaillés dans [Surpeuplement](./rules/overcrowding.md).

### Entités et bord

L'onglet **Entités** liste les plantes et les bâtiments de la zone, ceux qui ont un problème en premier. Les bâtiments posés sur les murs, le sol ou le plafond de la zone comptent avec elle : une station de pêche ou un distributeur collé au bassin apparaît dans les entités et peut signaler un problème.

Les portes, échelles, poteaux de pompier et escaliers sur ce bord sont rangés à part, dans le groupe **Bord** : ils restent sur la carte, mais ne comptent ni dans les bâtiments ni dans les problèmes.

### Captures du jeu

L'onglet **Captures** montre la zone telle que le jeu la dessine, pour la comparer à la carte : passe d'un onglet à l'autre, l'image tombe exactement sur la carte.

- **Capturer** demande une capture au jeu. Le bouton est grisé tant que le jeu n'est pas relié ; une pause ne le bloque pas.
- Le jeu en prend aussi une tout seul après un chantier : quand le plan de la zone a changé puis n'a plus bougé pendant deux minutes de jeu, au plus une toutes les dix minutes par zone.
- Les 15 dernières captures de chaque zone sont gardées. La plus récente s'affiche en grand, les autres en miniatures ; chacune indique quand elle a été prise, au cycle près, et si elle montre le **plan actuel** de la zone ou un plan plus ancien.

<Accordion title="Quand une capture ne vient pas">

Le jeu ne dessine que l'astéroïde affiché : une zone d'un autre astéroïde est refusée, avec la raison sous le bouton. Une capture attend aussi que l'outil Zone soit fermé, pour ne pas le photographier. L'écran se fige le temps d'une ou deux images pendant la capture.

</Accordion>

### Calque température

Quatre modes colorent la carte avec les teintes de la surcouche température du jeu. Une légende accompagne chaque mode.

| Mode | Ce qu'il montre |
| --- | --- |
| Absolue | La température de chaque case sur une échelle fixe. |
| Adaptative | La même couleur, mais l'échelle s'ajuste aux températures présentes dans la zone. |
| Flux thermique | Si la case refroidit, reste stable ou chauffe depuis la lecture précédente. Le rythme se mesure en temps réel : la vitesse du jeu et les pauses le faussent. |
| Changement d'état | Les cases proches de geler ou de se condenser, ou de fondre ou de bouillir. |

Le flux thermique a besoin de deux lectures du terrain : tant que le jeu n'a envoyé que la première, la carte l'indique.

Si les couleurs de la surcouche manquent (fichier `render.json` absent), le mode Changement d'état reste coloré ; les autres modes laissent les cases connues sur le fond de la carte et une note demande de relancer l'export du jeu.

<Accordion title="Si l'animation ne s'affiche pas">

Les liquides et les gaz sont animés avec WebGL2. Si ton navigateur ne le gère pas, ou si le contexte graphique est perdu, ils restent dessinés à plat : la carte reste lisible, sans mouvement. Le calque température ne dessine pas les fluides animés.

Les textures viennent de l'export du jeu. Après une mise à jour du jeu ou du mod, relance **Export Game Data** dans le jeu, puis l'import (voir [Import des données du jeu](../development/game-data-import.md)).

</Accordion>

## Inspecteur Uplink

L'inspecteur liste chaque message reçu du jeu, du plus récent au plus ancien, avec sa colonie, son cycle, sa taille et son résultat. Choisis un message pour lire son contenu, ou voir pourquoi il a été refusé. C'est la page à ouvrir quand une page de la colonie reste vide. Quand la règle de surpeuplement du planner donne un autre résultat que le jeu, une liste d'écarts (zone, région, valeur, jeu contre planner) apparaît au-dessus des messages.
