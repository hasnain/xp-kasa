<?php

class Ocr
{
    public static function read(string $imagePath): array
    {
        // Mac-only fast path. Linux servers go straight to Tesseract.
        if (PHP_OS_FAMILY === 'Darwin') {
            $socketTokens = self::readSocket($imagePath);
            if (count($socketTokens) >= 3) {
                return $socketTokens;
            }
            try {
                $tokens = self::readVision($imagePath);
                if (count($tokens) >= 3) {
                    return $tokens;
                }
            } catch (Throwable $exception) {
                // Fall through to Tesseract.
            }
        }

        return self::readTesseract($imagePath);
    }

    private static function readSocket(string $imagePath): array
    {
        if (!is_file('/tmp/xpunch-ocr.sock')) {
            return [];
        }
        $socket = @fsockopen('unix:///tmp/xpunch-ocr.sock', 0, $errno, $errstr, 1);
        if (!$socket) {
            return [];
        }
        stream_set_timeout($socket, 30);
        fwrite($socket, $imagePath . "\n");
        $output = stream_get_contents($socket);
        fclose($socket);
        if ($output === false || $output === '' || strpos($output, 'ERR ') === 0) {
            return [];
        }
        return self::tokensFromVision($output);
    }

    private static function readVision(string $imagePath): array
    {
        $binary = app_path('bin/ocr');
        if (!is_file($binary)) {
            throw new RuntimeException('Receipt reader is missing.');
        }

        $command = escapeshellarg($binary) . ' ' . escapeshellarg($imagePath);
        $descriptors = [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = proc_open($command, $descriptors, $pipes);
        if (!is_resource($process)) {
            throw new RuntimeException('Could not start the receipt reader.');
        }

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($process);

        if ($code !== 0) {
            $detail = trim($stderr) !== '' ? trim($stderr) : 'reader failed';
            throw new RuntimeException($detail);
        }

        return self::tokensFromVision((string) $stdout);
    }

    private static function tokensFromVision(string $stdout): array
    {
        $tokens = [];
        foreach (preg_split("/\r\n|\n|\r/", $stdout) as $line) {
            if ($line === '') {
                continue;
            }
            $parts = explode("\t", $line, 3);
            if (count($parts) < 3 || !is_numeric($parts[0])) {
                continue;
            }
            $tokens[] = [
                'y' => (float) $parts[0],
                'x' => (float) $parts[1],
                'text' => $parts[2],
            ];
        }

        return $tokens;
    }

    private static function readTesseract(string $imagePath): array
    {
        $workPath = self::prepareImageForOcr($imagePath);
        $cleanup = $workPath !== $imagePath;

        try {
            $binary = self::tesseractBinary();
            $prefix = self::tessdataPrefix();
            // Env must come before timeout(1), otherwise timeout tries to exec "TESSDATA_PREFIX=...".
            // OMP_THREAD_LIMIT=1 stops OpenMP thrashing on small VPS (was 3+ minutes for a 1MP photo).
            $envParts = ['OMP_THREAD_LIMIT=1'];
            if ($prefix !== '') {
                $envParts[] = 'TESSDATA_PREFIX=' . escapeshellarg($prefix);
            }
            $env = implode(' ', $envParts) . ' ';

            // Background job can wait longer than Cloudflare; still hard-cap hung processes.
            $timeout = self::timeoutPrefix(90);
            $command = $env . $timeout . escapeshellarg($binary) . ' ' . escapeshellarg($workPath)
                . ' stdout -l pol+eng --oem 1 --psm 6'
                . ' -c tessedit_do_invert=0'
                . ' tsv';
            $output = self::run($command, 95);
            $tokens = self::tokensFromTsv($output);

            // Copy printouts sometimes need a different page segmentation mode.
            if (count($tokens) < 5 || !self::tsvLooksLikeReceipt($output)) {
                $command = $env . $timeout . escapeshellarg($binary) . ' ' . escapeshellarg($workPath)
                    . ' stdout -l pol+eng --oem 1 --psm 4'
                    . ' -c tessedit_do_invert=0'
                    . ' tsv';
                $output2 = self::run($command, 95);
                $tokens2 = self::tokensFromTsv($output2);
                if (count($tokens2) > count($tokens)) {
                    $tokens = $tokens2;
                }
            }

            if (!$tokens) {
                throw new RuntimeException('No text was found on the receipt.');
            }
            return $tokens;
        } finally {
            if ($cleanup && is_file($workPath)) {
                @unlink($workPath);
            }
        }
    }

    /**
     * Shrink phone photos before Tesseract. Keeps the original for the PDF.
     */
    private static function prepareImageForOcr(string $imagePath): string
    {
        if (!function_exists('imagecreatefromjpeg')) {
            return $imagePath;
        }

        $info = @getimagesize($imagePath);
        if (!$info || empty($info[0]) || empty($info[1])) {
            return $imagePath;
        }

        $width = (int) $info[0];
        $height = (int) $info[1];
        $maxWidth = 700;
        $fileSize = @filesize($imagePath) ?: 0;

        // Already small enough — skip GD work.
        if ($width <= $maxWidth && $fileSize > 0 && $fileSize < 250000) {
            return $imagePath;
        }

        switch ($info[2]) {
            case IMAGETYPE_JPEG:
                $src = @imagecreatefromjpeg($imagePath);
                break;
            case IMAGETYPE_PNG:
                $src = @imagecreatefrompng($imagePath);
                break;
            case IMAGETYPE_WEBP:
                $src = function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($imagePath) : false;
                break;
            default:
                return $imagePath;
        }
        if (!$src) {
            return $imagePath;
        }

        $newWidth = min($width, $maxWidth);
        $newHeight = (int) max(1, round($height * ($newWidth / $width)));
        $dst = imagecreatetruecolor($newWidth, $newHeight);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
        // Grayscale only — heavy contrast hurts copy printouts (WYDRUK DANYCH…).
        imagefilter($dst, IMG_FILTER_GRAYSCALE);

        $tmpDir = storage_path('tmp');
        if (!is_dir($tmpDir)) {
            @mkdir($tmpDir, 0777, true);
        }
        $out = $tmpDir . '/ocr-' . bin2hex(random_bytes(8)) . '.jpg';
        imagejpeg($dst, $out, 65);
        imagedestroy($src);
        imagedestroy($dst);

        return is_file($out) ? $out : $imagePath;
    }

    private static function tsvLooksLikeReceipt(string $tsv): bool
    {
        return (bool) preg_match('/(?:para|szt|SUMA|PTU|OPODATK|DOKUMENTU|PARAGON)/iu', $tsv);
    }

    private static function timeoutPrefix(int $seconds): string
    {
        if (PHP_OS_FAMILY === 'Windows') {
            return '';
        }
        // Prefer coreutils timeout so a hung Tesseract cannot pin PHP-FPM.
        foreach (['/usr/bin/timeout', '/bin/timeout'] as $bin) {
            if (is_file($bin) && is_executable($bin)) {
                return escapeshellarg($bin) . ' ' . (int) $seconds . ' ';
            }
        }
        return '';
    }

    private static function tesseractBinary(): string
    {
        foreach (['/opt/homebrew/bin/tesseract', '/usr/bin/tesseract', 'tesseract'] as $binary) {
            if ($binary === 'tesseract' || is_file($binary)) {
                return $binary;
            }
        }
        return 'tesseract';
    }

    private static function tessdataPrefix(): string
    {
        $candidates = [
            '/opt/homebrew/share/tessdata',
            '/usr/share/tesseract-ocr/5/tessdata',
            '/usr/share/tesseract-ocr/4.00/tessdata',
            '/usr/share/tessdata',
        ];
        foreach ($candidates as $path) {
            if (is_dir($path)) {
                return $path;
            }
        }
        return '';
    }

    private static function tokensFromTsv(string $tsv): array
    {
        $lines = [];
        foreach (preg_split("/\r\n|\n|\r/", $tsv) as $row) {
            if ($row === '' || strpos($row, 'level') === 0) {
                continue;
            }
            $cols = str_getcsv($row, "\t");
            if (count($cols) < 12 || (int) $cols[0] !== 5) {
                continue;
            }
            $text = trim($cols[11]);
            if ($text === '') {
                continue;
            }
            $key = $cols[1] . ':' . $cols[2] . ':' . $cols[3] . ':' . $cols[4];
            $lines[$key][] = [
                'left' => (int) $cols[6],
                'top' => (int) $cols[7],
                'text' => $text,
            ];
        }

        $tokens = [];
        foreach ($lines as $words) {
            usort($words, function ($a, $b) {
                return $a['left'] <=> $b['left'];
            });
            $text = [];
            $top = $words[0]['top'];
            foreach ($words as $word) {
                $text[] = $word['text'];
                $top = min($top, $word['top']);
            }
            $tokens[] = [
                'y' => -1 * $top,
                'x' => 0,
                'text' => implode(' ', $text),
            ];
        }

        return $tokens;
    }

    private static function run(string $command, int $deadlineSeconds = 95): string
    {
        $descriptors = [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = proc_open($command, $descriptors, $pipes);
        if (!is_resource($process)) {
            throw new RuntimeException('Could not start the receipt reader.');
        }

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
        $stderr = '';
        $deadline = microtime(true) + max(5, $deadlineSeconds);
        while (true) {
            $status = proc_get_status($process);
            $stdout .= stream_get_contents($pipes[1]);
            $stderr .= stream_get_contents($pipes[2]);
            if (!$status['running']) {
                break;
            }
            if (microtime(true) >= $deadline) {
                proc_terminate($process, 9);
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($process);
                throw new RuntimeException('Receipt reading timed out.');
            }
            usleep(50000);
        }

        $stdout .= stream_get_contents($pipes[1]);
        $stderr .= stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($process);

        // timeout(1) exits 124 when the command is killed.
        if (($code !== 0 && $code !== 124) && trim($stdout) === '') {
            $detail = trim($stderr);
            throw new RuntimeException($detail !== '' ? $detail : 'Receipt reader failed.');
        }
        if ($code === 124 && trim($stdout) === '') {
            throw new RuntimeException('Receipt reading timed out.');
        }
        return $stdout;
    }
}
