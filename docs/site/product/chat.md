---
title: Chat en jeu
description: Poser une question à Claude depuis le jeu, avec ce que tu regardes comme contexte, et lire sa réponse dans un panneau.
sources: ["app/Jobs/Uplink/ReceiveChatAsk.php", "app/Actions/Chat/**", "app/Jobs/Chat/**", "app/Listeners/Chat/**", "app/Services/Chat/**", "app/Ai/**"]
order: 7
---

# Chat en jeu

Pose une question à Claude sans quitter la partie : « pourquoi cette machine ne tourne pas ? », « ce ranch peut-il être optimisé ? ». Le chat a besoin du mod Uplink relié au planner (voir [Ma colonie](./colony.md#relier-le-jeu)), et le planner doit tourner.

1. Appuie sur **Maj + Q** dans le jeu : le panneau s'ouvre. La touche se change dans les contrôles du jeu, sous **Uplink chat** (options, contrôles).
2. Tape ta question et valide avec Entrée. Les touches vont au champ, pas au jeu.
3. Attends la réponse, en général 20 secondes ou plus. Le jeu continue de tourner pendant ce temps.

Ce que tu as sélectionné dans le jeu part avec la question, avec sa zone et la dernière capture de cette zone. Claude lit ça, interroge ta colonie et les données du jeu, puis répond dans la langue de ta question.

Claude tourne sur ta machine, par Claude Code : chaque question compte dans ton abonnement Claude. Il est en **lecture seule** : il ne modifie rien dans le jeu ni dans la colonie.

## Le contexte envoyé

Sous le titre **CONTEXT SENT**, chaque élément envoyé avec ta question est une pastille que tu retires avec sa croix **×**. Rien ne part sans que tu le voies.

- **+ Selection** ajoute ce que tu as sélectionné dans le jeu (bâtiment, plante, créature) et la dernière capture de sa zone, affichée « *zone* · latest capture ». Sans sélection, le panneau dit « Nothing is selected in the game ».
- **New capture** demande au jeu de redessiner la zone au lieu d'utiliser sa dernière capture.
- **+ Aim…** te laisse viser autre chose sur la carte (**AIM FOR CLAUDE**), avec quatre modes : **Object** (clique un objet, bouton **Add object**), **Box** (glisse un rectangle de cases, **Add box**, pastille « Box 12×6 »), **Room** (clique dans une pièce, **Add room**) et **Zone** (clique une de tes zones, **Add zone**). Le filtre de calque (**Buildings**, **Liquid pipes**, **Gas pipes**, **Wires**, **Automation**) dit à Claude ce qui t'intéresse ; il ne filtre pas les données. Clic droit ou Échap reviennent au panneau.

Une boîte ou une pièce hors de tes zones n'est pas enregistrée comme zone : le planner la fait lire une fois par le jeu, juste pour ta question.

## Le panneau

Son titre est **Uplink · Claude**.

- Glisse l'en-tête pour le déplacer et tire le petit carré du coin bas-droit pour le redimensionner. Sa taille et sa position sont gardées d'une partie à l'autre.
- **+** démarre une nouvelle conversation.
- **Pin** garde le panneau ouvert quand tu appuies sur Échap.
- **–** le réduit en badge dans un coin (le raccourci du chat fait pareil). Le badge affiche **Claude**, puis **Claude · working…** pendant la réponse et **Claude · reply ready** quand elle est prête.
- **×** ferme le panneau ; la conversation est gardée.
- Après un rechargement de la partie, la dernière conversation revient dans le panneau.
- Sous chaque question, **Sent with:** liste les éléments envoyés ; sous chaque réponse, sa durée et les outils que Claude a utilisés.
- Le bouton **Send** (ou Entrée) envoie la question. Tant que Claude n'a pas répondu, le panneau dit « Wait for the answer to the previous question ».

## Les liens dans les réponses

Quand Claude parle d'un bâtiment, d'une plante ou d'une créature, son nom est un lien. Un clic le sélectionne et place la caméra dessus ; si le lien désigne plusieurs objets, chaque clic passe au suivant. Si l'objet n'existe plus, le panneau écrit **Not found any more**.

<Accordion title="Limites et cas particuliers">

- Une question porte au plus 16 éléments de contexte et 5000 cases en tout. Au-delà, le message est refusé.
- Une question à la fois : **Send** refuse une nouvelle question tant que Claude répond.
- Le planner attend jusqu'à 20 s que le jeu lise le contexte hors zone. Si le jeu est hors ligne ou si une autre sauvegarde est chargée, Claude répond avec ce que le planner a déjà et le dit. Une boîte sur un autre astéroïde est refusée.
- Une erreur (Claude Code absent ou non connecté, délai dépassé) s'affiche dans le panneau (**Failed:** suivi de la raison), jamais en silence.
- Sans le planner lancé, rien ne part. Voir [Installation locale](../development/local-setup.md#chat-en-jeu) pour installer Claude Code.
- Claude peut ouvrir des fichiers et lire le code d'un mod pour répondre, mais jamais écrire. Le détail est dans [Uplink](../development/uplink.md#chat-en-jeu).

</Accordion>
