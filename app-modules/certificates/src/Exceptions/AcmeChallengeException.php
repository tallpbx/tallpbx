<?php

declare(strict_types=1);

namespace Modules\Certificates\Exceptions;

/**
 * Thrown when an ACME Let's Encrypt challenge fails or encounters an unrecoverable error.
 */
class AcmeChallengeException extends CertificateException {}
