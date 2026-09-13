<?php

declare(strict_types=1);

namespace App\Events\FreeSwitch;

/**
 * Dispatched when a bridge between two channels is broken.
 */
class ChannelUnbridge extends FreeSwitchEvent {}
