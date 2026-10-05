<?php

declare(strict_types=1);

namespace Modules\Certificates\Exceptions;

/**
 * Thrown when an X.509 certificate PEM or private key cannot be parsed or is syntactically invalid.
 */
class InvalidCertificateException extends CertificateException
{
}
