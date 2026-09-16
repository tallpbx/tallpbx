<?php

declare(strict_types=1);

return [
    'seo' => [
        'title' => 'TallPBX — FreeSWITCH Telephone Platform',
        'description' => 'TallPBX is a modern TALL-stack PBX platform built with Tailwind CSS, Alpine.js, Laravel, and Livewire. Manage extensions, SIP trunks, routing, voicemail, and IVR dynamically.',
        'keywords' => 'TallPBX, PBX, telephony, VoIP, SIP, FreeSWITCH, TALL stack, Laravel, Livewire',
    ],

    'nav' => [
        'home' => 'Home',
        'features' => 'Features',
        'documentation' => 'Documentation',
        'client_sign_in' => 'Client Sign In',
        'administrator' => 'Administrator',
        'get_started' => 'Get Started',
        'sign_in' => 'Sign In',
    ],

    'hero' => [
        'title' => 'Modern Telephony Platform',
        'subtitle' => 'A modern PBX control plane built for multi-tenant administration, dynamic dialplan generation, and a clean web-based operator experience.',
        'summary' => 'TALL stands for Tailwind CSS, Alpine.js, Laravel, and Livewire, otherwise known as the TALL stack, helping the platform feel fast, responsive, and easy to use while managing everyday PBX tasks.',
        'modular_summary' => 'Its modular architecture keeps each installation focused on the features it needs, makes upgrades easier to manage, and encourages third-party developers to add new PBX capabilities from their own trusted repositories. TallPBX is a community-first project, released under the Apache 2.0 license.',
        'cta_primary' => 'Get Started',
        'cta_secondary' => 'Learn More',
    ],

    'features' => [
        'title' => 'Enterprise-Grade Telephony Features',
        'subtitle' => 'Manage FreeSWITCH configuration dynamically from a unified dashboard, with tenant-aware routing, provisioning, and operational controls designed for real PBX workflows.',
        'extensions' => [
            'title' => 'SIP Extensions',
            'tooltip' => 'Create and manage SIP extensions with granular permissions, voicemail, and device provisioning.',
            'desc' => 'Create and manage SIP extensions with granular permissions, voicemail, and device provisioning.',
        ],
        'trunks' => [
            'title' => 'SIP Trunks',
            'tooltip' => 'Connect to upstream carriers via SIP trunks with automatic failover and load balancing.',
            'desc' => 'Connect to upstream carriers via SIP trunks with automatic failover and load balancing.',
        ],
        'inbound' => [
            'title' => 'Inbound DID Rules',
            'tooltip' => 'Route incoming calls to extensions, IVR menus, ring groups, or voicemail with conditional logic.',
            'desc' => 'Route incoming calls to extensions, IVR menus, ring groups, or voicemail with conditional logic.',
        ],
        'outbound' => [
            'title' => 'Outbound Steering',
            'tooltip' => 'Steer outbound calls through the cheapest or most reliable carrier based on configurable routes.',
            'desc' => 'Steer outbound calls through the cheapest or most reliable carrier based on configurable routes.',
        ],
        'voicemail' => [
            'title' => 'Visual Voicemail',
            'desc' => 'Voicemail-to-email with audio attachments, visual indicators, and per-extension PIN security.',
        ],
        'ivr' => [
            'title' => 'IVR Menus',
            'desc' => 'Build multi-level interactive voice response menus with time-of-day routing and holiday scheduling.',
        ],
    ],

    'stats' => [
        'uptime' => '99.9%',
        'uptime_label' => 'Uptime',
        'extensions' => '10K+',
        'extensions_label' => 'Extensions Managed',
        'providers' => '50+',
        'providers_label' => 'Provider Integrations',
        'support' => '24/7',
        'support_label' => 'Expert Support',
    ],

    'testimonials' => [
        'title' => 'Trusted by Telephony Professionals',
        'subtitle' => 'See what our users say about TallPBX.',
        'items' => [
            [
                'quote' => 'TallPBX transformed how we manage our telephony infrastructure. The multi-tenant support is outstanding.',
                'author' => 'Alex Rivera',
                'role' => 'IT Director, TechCorp',
            ],
            [
                'quote' => 'The dynamic FreeSWITCH integration saved us countless hours of XML configuration. A game-changer for our team.',
                'author' => 'Sarah Chen',
                'role' => 'VoIP Engineer, ConnectTel',
            ],
            [
                'quote' => 'We migrated from FusionPBX and the process was seamless. The API-first approach is exactly what we needed.',
                'author' => 'Marcus Williams',
                'role' => 'CTO, CloudVoice',
            ],
        ],
    ],

    'footer' => [
        'copyright' => 'All rights reserved.',
        'version' => 'Version',
        'php_version' => 'PHP :version',
        'freeswitch' => 'FreeSWITCH Integration',
    ],

    'contact' => [
        'title' => 'Get In Touch',
        'subtitle' => 'Have questions? Our team is here to help.',
        'email_label' => 'Email',
        'email_placeholder' => 'you@example.com',
        'message_label' => 'Message',
        'message_placeholder' => 'How can we help you?',
        'submit' => 'Send Message',
        'success' => 'Thank you! Your message has been sent.',
    ],
];
