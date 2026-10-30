<?php

/**
 * Background OCR job for the web upload flow.
 * Usage: php bin/ocr_job.php <jobId>
 */
if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

$cliArgs = $_SERVER['argv'] ?? ($GLOBALS['argv'] ?? []);
$jobId = $cliArgs[1] ?? '';
if (!preg_match('/^[a-f0-9]{16,64}$/', $jobId)) {
    fwrite(STDERR, "bad job id: " . $jobId . "\n");
    exit(1);
}

require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/src/bootstrap.php';
require dirname(__DIR__) . '/src/Ocr.php';
require dirname(__DIR__) . '/src/ReceiptParser.php';

$jobFile = storage_path('tmp/job-' . $jobId . '.json');
if (!is_file($jobFile)) {
    fwrite(STDERR, "missing job file\n");
    exit(1);
}

$job = json_decode((string) file_get_contents($jobFile), true);
if (!is_array($job) || empty($job['image'])) {
    fwrite(STDERR, "bad job payload\n");
    exit(1);
}

$job['status'] = 'running';
$job['updated_at'] = time();
file_put_contents($jobFile, json_encode($job));

try {
    set_time_limit(0);
    $tokens = Ocr::read($job['image']);
    $parsed = ReceiptParser::parseTokens($tokens);
    $warning = '';
    if (empty($parsed['items'])) {
        $warning = 'No product lines were read. Add them below. The photo will still be placed on the PDF.';
    }
    $job['status'] = 'done';
    $job['parsed'] = $parsed;
    $job['warning'] = $warning;
    $job['updated_at'] = time();
} catch (Throwable $exception) {
    $job['status'] = 'done';
    $job['parsed'] = ReceiptParser::emptyDraft();
    $job['warning'] = 'The photo could not be read automatically. Type the lines below. The photo will still be placed on the PDF.';
    $job['error'] = $exception->getMessage();
    $job['updated_at'] = time();
    @file_put_contents(
        storage_path('tmp/ocr-last-error.log'),
        date('c') . ' job=' . $jobId . ' ' . $exception->getMessage() . "\n",
        FILE_APPEND
    );
}

file_put_contents($jobFile, json_encode($job));
