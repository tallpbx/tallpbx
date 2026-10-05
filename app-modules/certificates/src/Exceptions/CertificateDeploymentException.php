<?php

declare(strict_types=1);

namespace Modules\Certificates\Exceptions;

/**
 * Thrown when a certificate fails deployment to Nginx Web or FreeSWITCH Telephony.
 */
class CertificateDeploymentException extends CertificateException
{
}
