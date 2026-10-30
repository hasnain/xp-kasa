<?php

const DEFAULT_EVIDENCE_NO = '2025/000112830';

function app_path(string $path = ''): string
{
    $root = dirname(__DIR__);
    return $path === '' ? $root : $root . '/' . ltrim($path, '/');
}

function storage_path(string $path = ''): string
{
    $root = app_path('storage');
    return $path === '' ? $root : $root . '/' . ltrim($path, '/');
}

function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function money_to_float($value): float
{
    $value = str_replace(["\xc2\xa0", ' '], '', trim((string) $value));
    if ($value === '') {
        return 0.0;
    }
    $value = str_replace(',', '.', $value);
    return round((float) $value, 2);
}

function money_pl(float $value): string
{
    return number_format($value, 2, ',', ' ');
}

function qty_pl(float $value): string
{
    if (abs($value - round($value)) < 0.001) {
        return (string) (int) round($value);
    }
    $text = number_format($value, 3, ',', '');
    return rtrim(rtrim($text, '0'), ',');
}

function line_vat(float $gross, float $rate): float
{
    if ($rate <= 0 || $gross == 0.0) {
        return 0.0;
    }
    return round($gross * $rate / (100 + $rate), 2);
}

function reasons(): array
{
    return [
        'zwrot' => [
            'pdf' => 'Zwrot',
            'label' => 'Zwrot — Return',
        ],
        'cancel' => [
            'pdf' => 'wydruk przez pomyłkę',
            'label' => 'Wydruk przez pomyłkę — Cancelled',
        ],
    ];
}

function uploads_realpath(string $path): string
{
    $real = realpath($path);
    $root = realpath(storage_path('uploads'));
    if ($real === false || $root === false) {
        return '';
    }
    $prefix = $root . DIRECTORY_SEPARATOR;
    if (strpos($real, $prefix) !== 0) {
        return '';
    }
    return $real;
}

function purge_old_uploads(): void
{
    $limit = time() - 172800;
    foreach (glob(storage_path('uploads/*')) ?: [] as $file) {
        if (is_file($file) && filemtime($file) < $limit) {
            @unlink($file);
        }
    }
}
