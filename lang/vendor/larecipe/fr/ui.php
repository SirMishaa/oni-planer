<?php

declare(strict_types=1);

/*
 * French strings for LaRecipe's reading experience. Overrides the package's
 * resources/lang/en/ui.php key for key; keep both in sync after a LaRecipe update.
 */

return [
    'skip' => 'Aller au contenu',
    'agents_index' => 'Index de la doc pour les agents IA (llms.txt)',

    'header' => [
        'home' => 'Accueil :name',
        'menu' => 'Ouvrir la navigation',
        'close_menu' => 'Fermer la navigation',
        'repository' => 'Dépôt source',
        'version' => 'Version',
        'versions' => 'Versions',
        'language' => 'Langue',
        'languages' => 'Langues',
        'theme' => 'Thème',
        'theme_light' => 'Clair',
        'theme_dark' => 'Sombre',
        'theme_system' => 'Système',
        'theme_toggle' => 'Thème sombre',
        'sidebar' => 'Afficher la navigation',
        'back_to_top' => 'Retour en haut',
    ],

    'search' => [
        'label' => 'Rechercher dans la doc',
        'placeholder' => 'Rechercher dans la doc',
        'open' => 'Ouvrir la recherche',
    ],

    'nav' => [
        'label' => 'Doc',
        'updated' => 'Mis à jour récemment',
    ],

    'page' => [
        'breadcrumb' => "Fil d'Ariane",
        'updated' => 'Mis à jour :time',
        'updated_by' => 'Mis à jour :time par :name',
        'reading' => '{1} :count min de lecture|[2,*] :count min de lecture',
        'fallback' => "Cette page n'est pas encore traduite. Tu lis la version d'origine.",
        'on_this_page' => 'Sur cette page',
    ],

    'actions' => [
        'label' => 'Pour les agents',
        'copy_markdown' => 'Copier en Markdown',
        'view_markdown' => 'Voir en Markdown',
        'open_claude' => 'Ouvrir dans Claude',
        'open_chatgpt' => 'Ouvrir dans ChatGPT',
        'open_cursor' => 'Ouvrir dans Cursor',
        'connect_mcp' => 'Connecter le MCP',
        'copy_page' => 'Copier la page',
        'more' => "Plus d'actions sur la page",
        'copied' => 'Copié',
        'mcp_copied' => 'Commande MCP copiée',
        'prompt' => 'Lis :url pour que je puisse te poser des questions dessus.',
    ],

    'ticket' => [
        'label' => 'Dernière modification',
        'pages' => '{1} :count page|[2,*] :count pages',
        'approved_by' => 'Approuvé par :name',
        'view' => 'Voir la modification',
        'summary_by' => ':name a mis à jour cette page.',
        'summary' => 'Cette page a été mise à jour.',
    ],

    'footer' => [
        'previous' => 'Précédent',
        'next' => 'Suivant',
        'pager' => 'Pages précédente et suivante',
        'edit' => 'Modifier cette page',
        'credit' => 'Construit avec LaRecipe',
    ],

    'feedback' => [
        'question' => "Cette page t'a-t-elle aidé ?",
        'yes' => 'Oui',
        'no' => 'Non',
        'comment' => "Qu'est-ce qui pourrait être mieux ? (facultatif)",
        'send' => 'Envoyer',
        'skip' => 'Passer',
        'thanks' => 'Merci, ton avis a été envoyé.',
        'error' => "L'envoi a échoué. Réessaie.",
    ],

    'palette' => [
        'title' => 'Recherche',
        'placeholder' => 'Rechercher des pages, sections et actions',
        'pages' => 'Pages',
        'sections' => 'Sections',
        'actions' => 'Actions',
        'ask' => "Demander à l'IA : « :query »",
        'ask_label' => 'Demander',
        'empty' => 'Aucune page ne correspond à « :query ».',
        'loading' => "Chargement de l'index",
        'error' => "La recherche n'est pas disponible pour le moment.",
        'select' => 'pour ouvrir',
        'navigate' => 'pour naviguer',
        'close' => 'pour fermer',
        'switch_version' => 'Passer à la version :version',
        'toggle_theme' => 'Changer de thème',
        'copy_page' => 'Copier cette page en Markdown',
        'view_markdown' => 'Voir cette page en Markdown',
        'ask_docs' => 'Interroger la doc',
        'results' => '{0} Aucun résultat|{1} :count résultat|[2,*] :count résultats',
    ],

    'shortcuts' => [
        'title' => 'Raccourcis clavier',
        'search' => 'Rechercher',
        'search_alt' => 'Rechercher',
        'ask' => 'Interroger la doc',
        'help' => 'Afficher les raccourcis',
        'close' => 'Fermer un panneau',
    ],

    'ask' => [
        'title' => 'Interroger la doc',
        'label' => 'Demander',
        'button' => 'Interroger la doc',
        'placeholder' => 'Pose une question sur cette doc',
        'intro' => 'Les réponses viennent uniquement de cette doc et citent toujours les pages utilisées.',
        'sources' => 'Sources',
        'not_covered' => "Aucune page n'a été citée pour cette réponse : la doc ne couvre peut-être pas encore ce sujet.",
        'error' => "La question n'est pas disponible pour le moment. Essaie la recherche.",
        'send' => 'Envoyer la question',
        'stop' => 'Arrêter',
        'new' => 'Nouvelle question',
        'close' => 'Fermer',
        'you' => 'Toi',
        'answer' => 'Réponse',
        'search_instead' => 'Rechercher « :query »',
        'rate' => "Cette réponse t'a-t-elle aidé ?",
        'helpful' => 'Cette réponse m’a aidé',
        'not_helpful' => 'Cette réponse ne m’a pas aidé',
    ],

    'code' => [
        'copy' => 'Copier',
        'copied' => 'Copié',
        'copy_label' => 'Copier le code',
    ],

    'anchor' => 'Lien vers cette section',

    'frame' => [
        'zoom' => "Agrandir l'image",
        'close' => "Fermer l'image",
    ],

    'video' => [
        'play' => 'Lire la vidéo',
        'placeholder' => 'Vidéo de démonstration à venir',
        'watch' => 'Regarder sur :provider',
    ],

    'mermaid' => [
        'source' => 'Source du diagramme',
    ],

    'not_found' => [
        'label' => '404',
        'title' => 'Page introuvable',
        'body' => "Il n'y a pas de page à l'adresse :path.",
        'suggestions' => 'Pages similaires',
        'home' => 'Retour à :name',
    ],

    'common' => [
        'close' => 'Fermer',
        'loading' => 'Chargement',
    ],
];
