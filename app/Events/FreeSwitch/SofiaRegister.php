<?php

declare(strict_types=1);

namespace App\Events\FreeSwitch;

/**
 * Dispatched when FreeSWITCH sends a SOFIA_REGISTER event
 * indicating a SIP endpoint has registered.
 */
class SofiaRegister extends FreeSwitchEvent {}
