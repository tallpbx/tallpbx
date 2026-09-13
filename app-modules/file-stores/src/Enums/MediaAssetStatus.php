<?php

declare(strict_types=1);

namespace Modules\FileStores\Enums;

/**
 * Represents the lifecycle state of a managed media asset.
 */
enum MediaAssetStatus: string
{
    case Pending = 'pending';
    case Transferring = 'transferring';
    case Available = 'available';
    case Failed = 'failed';
    case Deleting = 'deleting';
    case Missing = 'missing';
}
