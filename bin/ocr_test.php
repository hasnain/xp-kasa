<?php

/**
 * Manual OCR smoke test.
 * Usage: php bin/ocr_test.php /path/to/receipt.jpg
 */
if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

$cliArgs = $_SERVER['argv'] ?? ($GLOBALS['argv'] ?? []);
$path = $cliArgs[1] ?? '';
if ($path === '' || !is_file($path)) {
    fwrite(STDERR, "Usage: php bin/ocr_test.php /path/to/receipt.jpg\n");
    fwrite(STDERR, "Got path: " . ($path === '' ? '(empty)' : $path) . "\n");
    exit(1);
}

require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/src/bootstrap.php';
require dirname(__DIR__) . '/src/Ocr.php';
require dirname(__DIR__) . '/src/ReceiptParser.php';

echo "Image: $path (" . filesize($path) . " bytes)\n";
$t0 = microtime(true);
try {
    $tokens = Ocr::read($path);
    $parsed = ReceiptParser::parseTokens($tokens);
    $dt = round(microtime(true) - $t0, 2);
    echo "OK in {$dt}s\n";
    echo 'tokens=' . count($tokens) . ' items=' . count($parsed['items']) . "\n";
    echo "receipt_no={$parsed['receipt_no']} date={$parsed['date']} gross={$parsed['gross']} vat={$parsed['vat']}\n";
    foreach ($parsed['items'] as $item) {
        echo " - {$item['name']} | {$item['qty']} {$item['unit']} | {$item['unit_price']} => {$item['value']} VAT{$item['vat_rate']}\n";
    }
    echo "--- lines ---\n";
    foreach ($parsed['lines'] as $line) {
        echo $line . "\n";
    }
} catch (Throwable $exception) {
    $dt = round(microtime(true) - $t0, 2);
    fwrite(STDERR, "FAIL in {$dt}s: " . $exception->getMessage() . "\n");
    exit(1);
}
