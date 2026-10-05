<?php

declare(strict_types=1);

namespace Modules\Certificates\Exceptions;

/**
 * Thrown when a private key does not match the public key modulus of an X.509 certificate.
 */
class MismatchedKeypairException extends CertificateException
{
}
