<?php

declare(strict_types=1);

namespace Modules\FileStores\Services;

use Aws\S3\S3Client;
use League\Flysystem\AwsS3V3\AwsS3V3Adapter;
use League\Flysystem\Filesystem;
use League\Flysystem\Ftp\FtpAdapter;
use League\Flysystem\Ftp\FtpConnectionOptions;
use League\Flysystem\Local\LocalFilesystemAdapter;
use League\Flysystem\PhpseclibV3\SftpAdapter;
use League\Flysystem\PhpseclibV3\SftpConnectionProvider;
use Modules\FileStores\Models\FileStore;
use RuntimeException;
use Spatie\Dropbox\Client as DropboxClient;
use Spatie\FlysystemDropbox\DropboxAdapter;

/**
 * Creates Flysystem or email adapters from encrypted file store settings.
 */
class FileStoreAdapterFactory implements FileStoreAdapterFactoryInterface
{
    /**
     * Build the adapter for the profile's configured provider.
     */
    public function make(FileStore $fileStore): FileStoreAdapterInterface
    {
        $settings = $fileStore->settings;

        return match ($fileStore->provider) {
            'local' => new FlysystemFileStoreAdapter(
                new Filesystem(new LocalFilesystemAdapter($settings['root'])),
            ),
            's3' => new FlysystemFileStoreAdapter($this->makeS3Filesystem($settings)),
            'ftp' => new FlysystemFileStoreAdapter($this->makeFtpFilesystem($settings)),
            'sftp', 'ssh' => new FlysystemFileStoreAdapter($this->makeSftpFilesystem($settings)),
            'dropbox' => new FlysystemFileStoreAdapter(
                new Filesystem(new DropboxAdapter(new DropboxClient($settings['access_token']))),
            ),
            'email' => new EmailFileStoreAdapter($settings['recipient'], $settings['sender'] ?? null),
            default => throw new RuntimeException("Unsupported file store provider [{$fileStore->provider}]."),
        };
    }

    /**
     * Build an S3-compatible Flysystem filesystem without contacting the service.
     *
     * @param  array<string, mixed>  $settings
     */
    private function makeS3Filesystem(array $settings): Filesystem
    {
        $clientConfig = [
            'credentials' => [
                'key' => $settings['access_key'],
                'secret' => $settings['secret'],
            ],
            'region' => $settings['region'],
            'version' => 'latest',
        ];

        if (($settings['endpoint'] ?? null) !== null && $settings['endpoint'] !== '') {
            $clientConfig['endpoint'] = $settings['endpoint'];
        }

        return new Filesystem(new AwsS3V3Adapter(
            new S3Client($clientConfig),
            $settings['bucket'],
        ));
    }

    /**
     * Build an FTP Flysystem filesystem without opening a connection.
     *
     * @param  array<string, mixed>  $settings
     */
    private function makeFtpFilesystem(array $settings): Filesystem
    {
        return new Filesystem(new FtpAdapter(FtpConnectionOptions::fromArray([
            'host' => $settings['host'],
            'root' => $settings['root'] ?? '',
            'username' => $settings['username'],
            'password' => $settings['password'],
            'port' => (int) ($settings['port'] ?? 21),
            'ssl' => (bool) ($settings['ssl'] ?? false),
            'passive' => (bool) ($settings['passive'] ?? true),
            'timeout' => (int) ($settings['timeout'] ?? 10),
        ])));
    }

    /**
     * Build an SFTP filesystem for both SFTP and SSH profile types.
     *
     * @param  array<string, mixed>  $settings
     */
    private function makeSftpFilesystem(array $settings): Filesystem
    {
        $connection = new SftpConnectionProvider(
            $settings['host'],
            $settings['username'],
            $settings['password'] ?? null,
            $settings['private_key'] ?? null,
            $settings['passphrase'] ?? null,
            (int) ($settings['port'] ?? 22),
            false,
            (int) ($settings['timeout'] ?? 10),
            4,
            $settings['host_fingerprint'] ?? null,
        );

        return new Filesystem(new SftpAdapter($connection, $settings['root'] ?? ''));
    }
}
