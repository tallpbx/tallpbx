<?php

declare(strict_types=1);

return [
    'regards' => 'Cordialement',

    'welcome' => [
        'subject' => 'Bienvenue chez :name',
        'greeting' => 'Bienvenue chez :name !',
        'body' => 'Merci de vous être joint à nous. Nous sommes ravis de vous compter parmi nous.',
    ],

    'password_reset' => [
        'subject' => 'Réinitialisez Votre Mot de Passe',
        'greeting' => 'Bonjour !',
        'body' => 'Vous recevez cet email car nous avons reçu une demande de réinitialisation de mot de passe pour votre compte.',
        'action' => 'Réinitialiser le Mot de Passe',
        'expire' => 'Ce lien de réinitialisation expirera dans :count minutes.',
        'no_action' => 'Si vous n\'avez pas demandé de réinitialisation de mot de passe, aucune action supplémentaire n\'est requise.',
    ],

    'invoice' => [
        'subject' => 'Nouvelle Facture de :name',
        'greeting' => 'Bonjour :name,',
        'body' => 'Une nouvelle facture a été générée pour votre compte.',
        'total' => 'Total : :amount',
        'due_date' => 'Date d\'échéance : :date',
        'view_invoice' => 'Voir la Facture',
        'thank_you' => 'Merci pour votre confiance.',
    ],
];

