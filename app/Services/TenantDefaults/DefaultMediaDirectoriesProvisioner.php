<?php

declare(strict_types=1);

namespace App\Services\TenantDefaults;

use App\Models\Tenant;
use Illuminate\Support\Facades\File;
use Modules\FileStores\Enums\MediaCategory;

/**
 * Creates the per-tenant managed media directories.
 *
 * FreeSWITCH writes recordings, inbound faxes, and voicemail deposits
 * directly into these roots, and the web application stages remote-archive
 * transfers in the spool categories. Provisioning them with the shared
 * media group (mode 2775, inherited setgid) means both service users can
 * write without any per-file chown step.
 */
final class DefaultMediaDirectoriesProvisioner
{
    /**
     * Create any missing tenant media directories.
     *
     * @return array{created: int, skipped: int}
     */
    public function provision(Tenant $tenant): array
    {
        $created = 0;
        $skipped = 0;

        foreach ($this->directories((string) $tenant->id) as $directory) {
            if (is_dir($directory)) {
                $skipped++;

                continue;
            }

            File::ensureDirectoryExists($directory);

            // chmod is used (not mkdir mode) so the umask cannot strip the
            // group-write bit; the setgid bit keeps new files in the media group.
            chmod($directory, 02775);

            $created++;
        }

        return ['created' => $created, 'skipped' => $skipped];
    }

    /**
     * List every per-tenant media directory that must exist up front.
     *
     * @return list<string>
     */
    private function directories(string $tenantId): array
    {
        $spoolRoot = rtrim((string) config('media-storage.spool_root'), '/').'/'.$tenantId;
        $storeRoot = rtrim((string) config('media-storage.store_root'), '/');

        $directories = [];

        foreach (MediaCategory::cases() as $category) {
            $directories[] = $spoolRoot.'/'.$category->value;
        }

        // mod_voicemail deposits into {store}/runtime/{tenant}/voicemail-message
        // and creates the mailbox subdirectory itself.
        $directories[] = $storeRoot.'/runtime/'.$tenantId.'/voicemail-message';

        return $directories;
    }
}
