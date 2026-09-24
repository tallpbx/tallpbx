<?php

declare(strict_types=1);

return [
    'seo' => [
        'title' => 'TallPBX — Plateforme Téléphonique FreeSWITCH',
        'description' => 'TallPBX est une plateforme PBX moderne basée sur TALL, construite avec Tailwind CSS, Alpine.js, Laravel et Livewire. Gérez dynamiquement les extensions, les trunks SIP, le routage, la messagerie vocale et les IVR.',
        'keywords' => 'TallPBX, PBX, téléphonie, VoIP, SIP, FreeSWITCH, TALL stack, Laravel, Livewire',
    ],

    'nav' => [
        'home' => 'Accueil',
        'features' => 'Fonctionnalités',
        'documentation' => 'Documentation',
        'client_sign_in' => 'Connexion Client',
        'administrator' => 'Administrateur',
        'get_started' => 'Commencer',
        'sign_in' => 'Connexion',
    ],

    'hero' => [
        'title' => 'Plateforme de Téléphonie Moderne',
        'subtitle' => 'Simplicité moderne en surface, ingénierie télécom d\'entreprise sous le capot.',
        'summary' => 'TALL signifie Tailwind CSS, Alpine.js, Laravel et Livewire, également connu sous le nom de stack TALL, ce qui aide la plateforme à rester rapide, réactive et facile à utiliser pour gérer les tâches PBX quotidiennes.',
        'modular_summary' => 'Son architecture modulaire garde chaque installation centrée sur les fonctions dont elle a besoin, simplifie la gestion des mises à jour et encourage les développeurs tiers à ajouter de nouvelles capacités PBX depuis leurs propres dépôts de confiance. TallPBX est un projet communautaire, publié sous licence Apache 2.0.',
        'cta_primary' => 'Commencer',
        'cta_secondary' => 'En Savoir Plus',
    ],

    'features' => [
        'title' => 'Fonctionnalités de Téléphonie d\'Entreprise',
        'subtitle' => 'Gérez dynamiquement la configuration FreeSWITCH depuis un tableau de bord unifié, avec routage tenant-aware, provisionnement et contrôles opérationnels conçus pour de vrais flux PBX.',
        'extensions' => [
            'title' => 'Extensions SIP',
            'tooltip' => 'Créez et gérez des extensions SIP avec des permissions granulaires, messagerie vocale et provisionnement d\'appareils.',
            'desc' => 'Créez et gérez des extensions SIP avec des permissions granulaires, messagerie vocale et provisionnement d\'appareils.',
        ],
        'trunks' => [
            'title' => 'Trunks SIP',
            'tooltip' => 'Connectez-vous aux opérateurs via des trunks SIP avec basculement automatique et équilibrage de charge.',
            'desc' => 'Connectez-vous aux opérateurs via des trunks SIP avec basculement automatique et équilibrage de charge.',
        ],
        'inbound' => [
            'title' => 'Règles DID Entrantes',
            'tooltip' => 'Acheminez les appels entrants vers des extensions, menus IVR, groupes de sonnerie ou messagerie vocale avec logique conditionnelle.',
            'desc' => 'Acheminez les appels entrants vers des extensions, menus IVR, groupes de sonnerie ou messagerie vocale avec logique conditionnelle.',
        ],
        'outbound' => [
            'title' => 'Routage Sortant',
            'tooltip' => 'Dirigez les appels sortants via l\'opérateur le moins cher ou le plus fiable selon des routes configurables.',
            'desc' => 'Dirigez les appels sortants via l\'opérateur le moins cher ou le plus fiable selon des routes configurables.',
        ],
        'voicemail' => [
            'title' => 'Messagerie Visuelle',
            'desc' => 'Messagerie vocale vers email avec pièces jointes audio, indicateurs visuels et sécurité PIN par extension.',
        ],
        'ivr' => [
            'title' => 'Menus IVR',
            'desc' => 'Construisez des menus interactifs à plusieurs niveaux avec routage horaire et planification des jours fériés.',
        ],
    ],

    'stats' => [
        'uptime' => '99.9%',
        'uptime_label' => 'Temps d\'activité',
        'extensions' => '10K+',
        'extensions_label' => 'Extensions Gérées',
        'providers' => '50+',
        'providers_label' => 'Intégrations de Prestataires',
        'support' => '24/7',
        'support_label' => 'Support Expert',
    ],

    'testimonials' => [
        'title' => 'Approuvé par les Professionnels de la Téléphonie',
        'subtitle' => 'Découvrez ce que nos utilisateurs disent de TallPBX.',
        'items' => [
            [
                'quote' => 'TallPBX a transformé notre gestion d\'infrastructure téléphonique. Le support multi-tenant est exceptionnel.',
                'author' => 'Alex Rivera',
                'role' => 'Directeur IT, TechCorp',
            ],
            [
                'quote' => 'L\'intégration dynamique FreeSWITCH nous a fait gagner d\'innombrables heures de configuration XML. Un changement révolutionnaire pour notre équipe.',
                'author' => 'Sarah Chen',
                'role' => 'Ingénieure VoIP, ConnectTel',
            ],
            [
                'quote' => 'Nous avons migré depuis FusionPBX et le processus s\'est déroulé sans accroc. L\'approche API-first est exactement ce dont nous avions besoin.',
                'author' => 'Marcus Williams',
                'role' => 'CTO, CloudVoice',
            ],
        ],
    ],

    'footer' => [
        'copyright' => 'Tous droits réservés.',
        'version' => 'Version',
        'php_version' => 'PHP :version',
        'freeswitch' => 'Intégration FreeSWITCH',
    ],

    'contact' => [
        'title' => 'Contactez-Nous',
        'subtitle' => 'Des questions ? Notre équipe est là pour vous aider.',
        'email_label' => 'Email',
        'email_placeholder' => 'vous@exemple.com',
        'message_label' => 'Message',
        'message_placeholder' => 'Comment pouvons-nous vous aider ?',
        'submit' => 'Envoyer le Message',
        'success' => 'Merci ! Votre message a été envoyé.',
    ],
];
