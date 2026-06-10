<?php
/**
 * Modele de configuration de l'assistant finalyn.ia.
 *
 * A FAIRE AU DEPLOIEMENT :
 *   1. Copier ce fichier en  api/config.php  (meme dossier).
 *   2. Coller la vraie clef API Anthropic ci-dessous.
 *   3. NE JAMAIS committer api/config.php (il est dans .gitignore).
 *
 * Alternative (recommandee si l'hebergeur le permet) : ne pas creer config.php
 * du tout et definir plutot la variable d'environnement ANTHROPIC_API_KEY.
 */

return [
    // Clef API Anthropic (commence par "sk-ant-...").
    'api_key' => 'COLLEZ_VOTRE_CLEF_API_ICI',
];
