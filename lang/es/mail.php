<?php

declare(strict_types=1);

return [
    'regards' => 'Saludos cordiales',

    'welcome' => [
        'subject' => 'Bienvenido a :name',
        'greeting' => '¡Bienvenido a :name!',
        'body' => 'Gracias por unirse. Estamos emocionados de tenerle a bordo.',
    ],

    'password_reset' => [
        'subject' => 'Restablezca su Contraseña',
        'greeting' => '¡Hola!',
        'body' => 'Recibe este correo porque recibimos una solicitud de restablecimiento de contraseña para su cuenta.',
        'action' => 'Restablecer Contraseña',
        'expire' => 'Este enlace de restablecimiento expirará en :count minutos.',
        'no_action' => 'Si no solicitó un restablecimiento de contraseña, no se requiere ninguna acción adicional.',
    ],

    'invoice' => [
        'subject' => 'Nueva Factura de :name',
        'greeting' => 'Hola :name,',
        'body' => 'Se ha generado una nueva factura para su cuenta.',
        'total' => 'Total: :amount',
        'due_date' => 'Fecha de Vencimiento: :date',
        'view_invoice' => 'Ver Factura',
        'thank_you' => 'Gracias por su preferencia.',
    ],

    'impersonation' => [
        'subject' => 'Alerta de Suplantación',
        'body' => 'Un administrador accedió a su cuenta.',
    ],
];
