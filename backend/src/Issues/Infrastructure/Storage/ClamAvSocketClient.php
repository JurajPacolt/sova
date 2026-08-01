<?php

declare(strict_types=1);

namespace Sova\Issues\Infrastructure\Storage;

use RuntimeException;

/**
 * Streams an upload to clamd without placing a host path in the scanner
 * container. The protocol frames each chunk with a network-order length and a
 * zero-length chunk terminates the request.
 */
final readonly class ClamAvSocketClient implements ClamAvClient
{
    private const int CHUNK_BYTES = 8192;

    public function __construct(
        private string $host,
        private int $port,
        private float $connectTimeoutSeconds,
        private int $readTimeoutSeconds,
    ) {}

    public function scan(string $path): string
    {
        $errorCode = 0;
        $errorMessage = '';
        $socket = @stream_socket_client(
            sprintf('tcp://%s:%d', $this->host, $this->port),
            $errorCode,
            $errorMessage,
            $this->connectTimeoutSeconds,
            STREAM_CLIENT_CONNECT,
        );

        unset($errorCode, $errorMessage);

        if ($socket === false) {
            throw new RuntimeException('The malware scanner could not be reached.');
        }

        $seconds = max(1, $this->readTimeoutSeconds);
        stream_set_timeout($socket, $seconds);

        $file = @fopen($path, 'rb');

        if ($file === false) {
            fclose($socket);

            throw new RuntimeException('The upload could not be opened for malware scanning.');
        }

        try {
            $this->writeAll($socket, "zINSTREAM\0");

            while (!feof($file)) {
                $chunk = fread($file, self::CHUNK_BYTES);

                if ($chunk === false) {
                    throw new RuntimeException('The upload could not be read for malware scanning.');
                }

                if ($chunk === '') {
                    continue;
                }

                $this->writeAll($socket, pack('N', strlen($chunk)));
                $this->writeAll($socket, $chunk);
            }

            $this->writeAll($socket, pack('N', 0));

            return $this->readResponse($socket);
        } finally {
            fclose($file);
            fclose($socket);
        }
    }

    /**
     * @param resource $stream
     */
    private function writeAll(mixed $stream, string $bytes): void
    {
        $offset = 0;
        $length = strlen($bytes);

        while ($offset < $length) {
            $written = fwrite($stream, substr($bytes, $offset));

            if ($written === false || $written === 0) {
                throw new RuntimeException('The malware scanner connection was interrupted.');
            }

            $offset += $written;
        }
    }

    /**
     * @param resource $stream
     */
    private function readResponse(mixed $stream): string
    {
        $response = '';

        while (!feof($stream) && strlen($response) <= 4096) {
            $chunk = fread($stream, 512);

            if ($chunk === false) {
                throw new RuntimeException('The malware scanner response could not be read.');
            }

            $response .= $chunk;
            $terminator = strpos($response, "\0");

            if ($terminator !== false) {
                return substr($response, 0, $terminator);
            }

            $metadata = stream_get_meta_data($stream);

            if ($metadata['timed_out']) {
                throw new RuntimeException('The malware scanner response timed out.');
            }
        }

        if ($response === '' || strlen($response) > 4096) {
            throw new RuntimeException('The malware scanner returned an invalid response.');
        }

        return rtrim($response, "\r\n");
    }
}
