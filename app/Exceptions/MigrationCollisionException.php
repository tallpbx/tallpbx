<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * Thrown when a custom module migration collides with the existing schema:
 * creating a table that already exists or altering a protected core table
 * (plan §1.7.3). The migrator halts before executing any SQL.
 */
class MigrationCollisionException extends \RuntimeException {}
