<?php

declare(strict_types=1);

namespace Modules\FileStores\Services;

use Illuminate\Support\Facades\Mail;
use RuntimeException;

/**
 * Delivers a written file as an email attachment.
 *
 * Email destinations are write-only because sent messages cannot be listed or
 * read reliably through a generic SMTP transport.
 */
class EmailFileStoreAdapter implements FileStoreAdapterInterface
{
    /**
     * Create a write-only email destination.
     */
    public function __construct(private readonly string $recipient, private readonly ?string $sender = null) {}

    /**
     * Validate the destination address without transmitting a test email.
     */
    public function testConnection(): void
    {
        if (filter_var($this->recipient, FILTER_VALIDATE_EMAIL) === false) {
            throw new RuntimeException('The email file store recipient is invalid.');
        }
    }

    /**
     * Reject listing because SMTP is not a file listing protocol.
     *
     * @return never
     */
    public function listFiles(string $prefix): array
    {
        throw new RuntimeException('Email file stores do not support listing files.');
    }

    /**
     * Reject existence checks because SMTP cannot address delivered files.
     *
     * @return never
     */
    public function fileExists(string $path): bool
    {
        throw new RuntimeException('Email file stores do not support checking files.');
    }

    /**
     * Reject reads because SMTP does not retain addressable file streams.
     *
     * @return never
     */
    public function readStream(string $path)
    {
        throw new RuntimeException('Email file stores do not support reading files.');
    }

    /**
     * Deliver the stream as an attachment to the configured recipient.
     *
     * @param  resource  $stream
     */
    public function writeStream(string $path, $stream): void
    {
        $contents = stream_get_contents($stream);

        if ($contents === false) {
            throw new RuntimeException('The email attachment stream could not be read.');
        }

        Mail::raw('TallPBX file store delivery.', function ($message) use ($contents, $path): void {
            $message->to($this->recipient)
                ->subject('TallPBX file store delivery')
                ->attachData($contents, basename($path));

            if ($this->sender !== null && $this->sender !== '') {
                $message->from($this->sender);
            }
        });
    }

    /**
     * Reject deletion because SMTP cannot recall a delivered attachment.
     *
     * @return never
     */
    public function deleteFile(string $path): void
    {
        throw new RuntimeException('Email file stores do not support deleting files.');
    }
}
