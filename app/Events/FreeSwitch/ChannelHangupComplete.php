<?php

declare(strict_types=1);

namespace App\Events\FreeSwitch;

/**
 * Dispatched when a hangup is fully processed and the channel is destroyed.
 */
class ChannelHangupComplete extends FreeSwitchEvent {}
