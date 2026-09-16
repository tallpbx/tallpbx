<?php

declare(strict_types=1);

return [
    'regards' => 'Regards',

    'welcome' => [
        'subject' => 'Welcome to :name',
        'greeting' => 'Welcome to :name!',
        'body' => 'Thank you for joining. We\'re excited to have you on board.',
    ],

    'password_reset' => [
        'subject' => 'Reset Your Password',
        'greeting' => 'Hello!',
        'body' => 'You are receiving this email because we received a password reset request for your account.',
        'action' => 'Reset Password',
        'expire' => 'This password reset link will expire in :count minutes.',
        'no_action' => 'If you did not request a password reset, no further action is required.',
    ],

    'invoice' => [
        'subject' => 'New Invoice from :name',
        'greeting' => 'Hello :name,',
        'body' => 'A new invoice has been generated for your account.',
        'total' => 'Total: :amount',
        'due_date' => 'Due Date: :date',
        'view_invoice' => 'View Invoice',
        'thank_you' => 'Thank you for your business.',
    ],
];

