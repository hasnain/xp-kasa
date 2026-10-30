<?php

class Ocr
{
    public static function read(string $imagePath): array
    {
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
            // Direct reading works in a terminal. The page uses the background reader.
        }

        return self::readTesseract($imagePath);
    }

    private static function readSocket(string $imagePath): array
    {
        $socket = @fsockopen('unix:///tmp/xpunch-ocr.sock', 0, $errno, $errstr, 2);
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
        $binary = '/opt/homebrew/bin/tesseract';
        if (!is_file($binary)) {
            $binary = 'tesseract';
        }

        $command = 'TESSDATA_PREFIX=/opt/homebrew/share/tessdata '
            . escapeshellarg($binary) . ' ' . escapeshellarg($imagePath)
            . ' stdout -l pol+eng --psm 6 tsv';
        $output = self::run($command);
        $tokens = self::tokensFromTsv($output);
        if (count($tokens) >= 3) {
            return $tokens;
        }

        $command = 'TESSDATA_PREFIX=/opt/homebrew/share/tessdata '
            . escapeshellarg($binary) . ' ' . escapeshellarg($imagePath)
            . ' stdout -l pol+eng --psm 4 tsv';
        $output = self::run($command);
        $tokens = self::tokensFromTsv($output);
        if (!$tokens) {
            throw new RuntimeException('No text was found on the receipt.');
        }
        return $tokens;
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

    private static function run(string $command): string
    {
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
        if ($code !== 0 && trim((string) $stdout) === '') {
            $detail = trim((string) $stderr);
            throw new RuntimeException($detail !== '' ? $detail : 'Receipt reader failed.');
        }
        return (string) $stdout;
    }
}
