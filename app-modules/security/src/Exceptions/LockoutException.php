<?php

declare(strict_types=1);

namespace Modules\Security\Exceptions;

use RuntimeException;

/**
 * Exception thrown when a firewall rule or default policy change would lock out the administrator.
 *
 * Enforces the zero-lockout guarantee: administrators can never inadvertently cut off
 * their own management access when applying firewall policies.
 */
class LockoutException extends RuntimeException {}
