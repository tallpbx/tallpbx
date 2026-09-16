<?php

declare(strict_types=1);

return [
    'seo' => [
        'title' => 'TallPBX — Plataforma Telefónica FreeSWITCH',
        'description' => 'TallPBX es una plataforma PBX moderna basada en TALL, construida con Tailwind CSS, Alpine.js, Laravel y Livewire. Administre extensiones, troncales SIP, enrutamiento, buzón de voz e IVR dinámicamente.',
        'keywords' => 'TallPBX, PBX, telefonía, VoIP, SIP, FreeSWITCH, TALL stack, Laravel, Livewire',
    ],

    'nav' => [
        'home' => 'Inicio',
        'features' => 'Características',
        'documentation' => 'Documentación',
        'client_sign_in' => 'Inicio de Sesión',
        'administrator' => 'Administrador',
        'get_started' => 'Comenzar',
        'sign_in' => 'Iniciar Sesión',
    ],

    'hero' => [
        'title' => 'Plataforma de Telefonía Moderna',
        'subtitle' => 'Un panel de control PBX moderno, creado para administración multiinquilino, generación dinámica de planes de marcado y una experiencia web clara para operadores.',
        'summary' => 'TALL significa Tailwind CSS, Alpine.js, Laravel y Livewire, también conocido como el stack TALL, lo que ayuda a que la plataforma se sienta rápida, responsiva y fácil de usar al administrar tareas PBX cotidianas.',
        'modular_summary' => 'Su arquitectura modular mantiene cada instalación enfocada en las funciones que necesita, facilita la administración de actualizaciones y anima a desarrolladores externos a agregar nuevas capacidades PBX desde sus propios repositorios confiables. TallPBX es un proyecto comunitario, publicado bajo la licencia Apache 2.0.',
        'cta_primary' => 'Comenzar',
        'cta_secondary' => 'Más Información',
    ],

    'features' => [
        'title' => 'Características de Telefonía Empresarial',
        'subtitle' => 'Administre la configuración de FreeSWITCH dinámicamente desde un panel unificado, con enrutamiento por inquilino, aprovisionamiento y controles operativos diseñados para flujos PBX reales.',
        'extensions' => [
            'title' => 'Extensiones SIP',
            'tooltip' => 'Cree y administre extensiones SIP con permisos detallados, buzón de voz y aprovisionamiento de dispositivos.',
            'desc' => 'Cree y administre extensiones SIP con permisos detallados, buzón de voz y aprovisionamiento de dispositivos.',
        ],
        'trunks' => [
            'title' => 'Troncales SIP',
            'tooltip' => 'Conéctese a operadores upstream mediante troncales SIP con conmutación por error y balanceo de carga.',
            'desc' => 'Conéctese a operadores upstream mediante troncales SIP con conmutación por error y balanceo de carga.',
        ],
        'inbound' => [
            'title' => 'Reglas DID Entrantes',
            'tooltip' => 'Enrute llamadas entrantes a extensiones, menús IVR, grupos de timbre o buzón de voz con lógica condicional.',
            'desc' => 'Enrute llamadas entrantes a extensiones, menús IVR, grupos de timbre o buzón de voz con lógica condicional.',
        ],
        'outbound' => [
            'title' => 'Direccionamiento de Salida',
            'tooltip' => 'Dirija llamadas salientes a través del operador más económico o confiable según rutas configurables.',
            'desc' => 'Dirija llamadas salientes a través del operador más económico o confiable según rutas configurables.',
        ],
        'voicemail' => [
            'title' => 'Buzón de Voz Visual',
            'desc' => 'Buzón de voz a correo electrónico con archivos de audio, indicadores visuales y seguridad PIN por extensión.',
        ],
        'ivr' => [
            'title' => 'Menús IVR',
            'desc' => 'Construya menús interactivos de respuesta de voz de múltiples niveles con enrutamiento por hora y horarios festivos.',
        ],
    ],

    'stats' => [
        'uptime' => '99.9%',
        'uptime_label' => 'Tiempo de actividad',
        'extensions' => '10K+',
        'extensions_label' => 'Extensiones Gestionadas',
        'providers' => '50+',
        'providers_label' => 'Integraciones de Proveedores',
        'support' => '24/7',
        'support_label' => 'Soporte Experto',
    ],

    'testimonials' => [
        'title' => 'Confiado por Profesionales de Telefonía',
        'subtitle' => 'Vea lo que nuestros usuarios dicen sobre TallPBX.',
        'items' => [
            [
                'quote' => 'TallPBX transformó nuestra gestión de infraestructura telefónica. El soporte multiinquilino es excepcional.',
                'author' => 'Alex Rivera',
                'role' => 'Director de TI, TechCorp',
            ],
            [
                'quote' => 'La integración dinámica con FreeSWITCH nos ahorró incontables horas de configuración XML. Un cambio radical para nuestro equipo.',
                'author' => 'Sarah Chen',
                'role' => 'Ingeniera VoIP, ConnectTel',
            ],
            [
                'quote' => 'Migramos desde FusionPBX y el proceso fue fluido. El enfoque API primero es exactamente lo que necesitábamos.',
                'author' => 'Marcus Williams',
                'role' => 'CTO, CloudVoice',
            ],
        ],
    ],

    'footer' => [
        'copyright' => 'Todos los derechos reservados.',
        'version' => 'Versión',
        'php_version' => 'PHP :version',
        'freeswitch' => 'Integración FreeSWITCH',
    ],

    'contact' => [
        'title' => 'Póngase en Contacto',
        'subtitle' => '¿Tiene preguntas? Nuestro equipo está aquí para ayudar.',
        'email_label' => 'Correo electrónico',
        'email_placeholder' => 'usted@ejemplo.com',
        'message_label' => 'Mensaje',
        'message_placeholder' => '¿Cómo podemos ayudarle?',
        'submit' => 'Enviar Mensaje',
        'success' => '¡Gracias! Su mensaje ha sido enviado.',
    ],
];
