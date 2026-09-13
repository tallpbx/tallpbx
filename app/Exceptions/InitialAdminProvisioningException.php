<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Indicates that the one-time initial administrator setup cannot continue.
 */
class InitialAdminProvisioningException extends RuntimeException {}
