<?php

declare(strict_types=1);

namespace App\Events\FreeSwitch;

/**
 * Dispatched when FreeSWITCH sends a SOFIA_EXPIRE event
 * indicating a SIP registration has expired.
 */
class SofiaExpire extends FreeSwitchEvent {}
