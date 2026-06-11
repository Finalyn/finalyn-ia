<?php
/**
 * Modele de configuration finalyn.ia (chat + back-office).
 *
 * A FAIRE AU DEPLOIEMENT :
 *   1. Copier ce fichier en  api/config.php  (meme dossier).
 *   2. Remplir les valeurs ci-dessous.
 *   3. NE JAMAIS committer api/config.php (il est dans .gitignore).
 */

return [
    // --- Clef API Anthropic (assistant chat) ---
    // Commence par "sk-ant-...". Alternative : variable d'env ANTHROPIC_API_KEY.
    'api_key' => 'COLLEZ_VOTRE_CLEF_API_ICI',

    // --- E-mail qui recoit les nouvelles reservations d'audit ---
    'notify_email' => 'contact@finalyn.com',

    // --- Adresse expeditrice des e-mails (doit appartenir a votre domaine) ---
    // Idealement autorisee par votre SPF/DKIM pour ne pas tomber en spam.
    'from_email' => 'noreply@finalyn.com',

    // --- Acces au back-office (admin/) ---
    // Le plus simple : mettre un mot de passe en clair ci-dessous.
    'admin_password' => 'CHOISISSEZ_UN_MOT_DE_PASSE',

    // Plus sur (optionnel) : laisser admin_password vide et mettre ici un hash
    // genere avec :  php -r "echo password_hash('votre-mdp', PASSWORD_DEFAULT);"
    'admin_password_hash' => '',
];
