<?php

namespace App\Modules\Security;

use RuntimeException;

final class FileScanService
{
    public function ping(): bool
    {
        if ($fake = config('services.clamav.fake_result')) {
            return $fake !== 'unavailable';
        }

        $socket = $this->socket();
        fwrite($socket, "zPING\0");
        $response = stream_get_contents($socket) ?: '';
        fclose($socket);

        return str_contains($response, 'PONG');
    }

    public function scan(string $path): string
    {
        $fake = config('services.clamav.fake_result');
        if ($fake) {
            if ($fake === 'unavailable') {
                throw new RuntimeException('ClamAV tidak tersedia.');
            }

            return $fake === 'rejected' ? 'rejected' : 'clean';
        }

        $socket = $this->socket();
        $file = fopen($path, 'rb');
        if (! $file) {
            fclose($socket);
            throw new RuntimeException('File quarantine tidak dapat dibaca.');
        }
        stream_set_timeout($socket, (int) config('services.clamav.timeout', 5));
        fwrite($socket, "zINSTREAM\0");
        while (! feof($file)) {
            $chunk = fread($file, 8192);
            if ($chunk !== false && $chunk !== '') {
                fwrite($socket, pack('N', strlen($chunk)).$chunk);
            }
        }
        fwrite($socket, pack('N', 0));
        fclose($file);
        $response = stream_get_contents($socket) ?: '';
        fclose($socket);

        if (str_contains($response, 'FOUND')) {
            return 'rejected';
        }
        if (! str_contains($response, 'OK')) {
            throw new RuntimeException('ClamAV tidak memberi hasil scan yang sah.');
        }

        return 'clean';
    }

    /** @return resource */
    private function socket()
    {
        $errno = 0;
        $error = '';
        $socket = @fsockopen(
            (string) config('services.clamav.host', '127.0.0.1'),
            (int) config('services.clamav.port', 3310),
            $errno,
            $error,
            (float) config('services.clamav.timeout', 5),
        );
        if (! $socket) {
            throw new RuntimeException("ClamAV tidak tersedia: {$error}");
        }
        stream_set_timeout($socket, (int) config('services.clamav.timeout', 5));

        return $socket;
    }
}
