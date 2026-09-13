<?php

declare(strict_types=1);

namespace Modules\FileStores\Enums;

/**
 * Identifies the TallPBX feature that owns a managed media file.
 */
enum MediaCategory: string
{
    case VoicemailMessage = 'voicemail-message';
    case CallRecording = 'call-recording';
    case FaxInbound = 'fax-inbound';
    case FaxOutbound = 'fax-outbound';
    case Recording = 'recording';
    case MusicOnHold = 'music-on-hold';
    case VoicemailGreeting = 'voicemail-greeting';
    case IvrGreeting = 'ivr-greeting';
    case ConferenceGreeting = 'conference-greeting';
}
