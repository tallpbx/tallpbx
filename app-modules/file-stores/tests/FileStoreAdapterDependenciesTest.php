<?php

declare(strict_types=1);

/**
 * Regression guard for the File Stores remote-provider adapter dependencies.
 *
 * The s3/ftp/sftp/ssh/dropbox providers built by FileStoreAdapterFactory depend
 * on Composer packages that used to arrive transitively through the removed
 * tallpbx/module-file-stores path package. When that pseudo-package was pruned
 * from the lockfile, the adapter classes vanished from vendor/ and every remote
 * file store profile started fataling with "Class not found". This test pins
 * the root composer.json requirements so the dependency can never silently
 * disappear again.
 */
it('ships every remote provider adapter class the file store factory builds', function (): void {
    $adapterClasses = [
        'Aws\S3\S3Client',
        'League\Flysystem\AwsS3V3\AwsS3V3Adapter',
        'League\Flysystem\Ftp\FtpAdapter',
        'League\Flysystem\PhpseclibV3\SftpAdapter',
        'Spatie\FlysystemDropbox\DropboxAdapter',
        'Spatie\Dropbox\Client',
    ];

    foreach ($adapterClasses as $class) {
        expect(class_exists($class))
            ->toBeTrue("Expected [{$class}] to be installed; the corresponding remote file store provider is broken without it.");
    }
});

it('declares the adapter packages in root composer.json requirements', function (): void {
    $composer = json_decode((string) file_get_contents(base_path('composer.json')), true);
    $required = array_keys($composer['require'] ?? []);

    $packages = [
        'league/flysystem-aws-s3-v3',
        'league/flysystem-ftp',
        'league/flysystem-sftp-v3',
        'spatie/flysystem-dropbox',
    ];

    foreach ($packages as $package) {
        expect($required)->toContain($package);
    }
});
