<?php
/*
 * Réglages du formulaire de contact (envoyer.php).
 * À COMPLÉTER avant la mise en ligne. Ne communiquez jamais ce fichier.
 */
return [
    // Adresse qui reçoit les messages du formulaire
    'to'   => 'A-COMPLETER@jhelleboid-avocat.fr',

    // Adresse d'expédition : doit appartenir au nom de domaine du site
    'from' => 'A-COMPLETER@jhelleboid-avocat.fr',

    // Envoi par serveur SMTP authentifié (recommandé par OVH).
    // Laissez smtp_user vide pour utiliser à la place la fonction mail() de PHP.
    'smtp_host'   => 'ssl0.ovh.net',   // à vérifier dans l'espace client OVH (réglages de la messagerie)
    'smtp_port'   => 465,
    'smtp_secure' => 'ssl',            // 'ssl' (port 465), 'tls' (port 587) ou 'none' (tests uniquement)
    'smtp_user'   => '',               // en général l'adresse e-mail complète
    'smtp_pass'   => '',

    // Nombre maximal de messages par heure et par visiteur (anti-spam)
    'rate_limit'  => 5,
];
