<?php

declare(strict_types=1);

namespace App\Events\FreeSwitch;

/**
 * Dispatched when a channel is destroyed (resources released).
 */
class ChannelDestroy extends FreeSwitchEvent {}
