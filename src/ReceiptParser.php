<?php

class ReceiptParser
{
    public static function emptyDraft(): array
    {
        return [
            'receipt_no' => '',
            'date' => '',
            'unique_no' => '',
            'evidence_no' => DEFAULT_EVIDENCE_NO,
            'gross' => 0.0,
            'vat' => 0.0,
            'rates' => [],
            'items' => [],
            'lines' => [],
        ];
    }

    public static function parseTokens(array $tokens): array
    {
        $lines = self::groupLines($tokens);
        $textLines = [];
        foreach ($lines as $line) {
            $parts = $line['parts'];
            usort($parts, function ($a, $b) {
                return $a['x'] <=> $b['x'];
            });
            $text = [];
            foreach ($parts as $part) {
                $text[] = $part['text'];
            }
            $joined = self::normalize(implode(' ', $text));
            if ($joined !== '') {
                $textLines[] = $joined;
            }
        }

        $draft = self::emptyDraft();
        $draft['lines'] = $textLines;
        $draft = self::readHeader($textLines, $draft);
        $draft['items'] = self::readItems($textLines, $draft['rates']);

        $itemsGross = 0.0;
        foreach ($draft['items'] as $item) {
            $itemsGross += $item['value'];
        }
        $itemsGross = round($itemsGross, 2);
        $ratesGross = 0.0;
        foreach ($draft['rates'] as $rate) {
            if (isset($rate['gross']) && $rate['gross'] !== null) {
                $ratesGross += (float) $rate['gross'];
            }
        }
        $ratesGross = round($ratesGross, 2);

        // Choose the total closest to the sum of line items (OPODATK is often OCR'd wrong, e.g. 17 vs 77).
        if ($itemsGross > 0) {
            $suma = (float) $draft['gross'];
            $candidates = array_filter([$suma, $ratesGross, $itemsGross], function ($v) {
                return $v > 0;
            });
            $best = $itemsGross;
            $bestDiff = PHP_FLOAT_MAX;
            foreach ($candidates as $candidate) {
                $diff = abs($candidate - $itemsGross);
                if ($diff < $bestDiff) {
                    $bestDiff = $diff;
                    $best = $candidate;
                }
            }
            $draft['gross'] = round($best, 2);
        } elseif ($draft['gross'] <= 0) {
            $draft['gross'] = $ratesGross > 0 ? $ratesGross : 0.0;
        }
        if ($draft['vat'] <= 0 && $draft['gross'] > 0 && count($draft['rates']) === 1) {
            $only = reset($draft['rates']);
            $draft['vat'] = line_vat($draft['gross'], (float) $only['percent']);
        }

        return $draft;
    }

    private static function readHeader(array $lines, array $draft): array
    {
        $rates = [];
        $documentNo = '';
        $receiptNr = '';
        $paragonNo = '';
        foreach ($lines as $line) {
            if (preg_match('/NUMER\s+DOKU[A-Z]*ENTU\s*[:.]?\s*(\d{1,8})\b/iu', $line, $match)) {
                $documentNo = $match[1];
            }
            if (preg_match('/\bnr\s*[:.]?\s*(\d{1,8})\b/iu', $line, $match)) {
                $receiptNr = $match[1];
            }
            if (preg_match('/NUMER\s+PARAGONU\s*[:.]?\s*0*(\d{1,8})\b/iu', $line, $match)) {
                $paragonNo = $match[1];
            }
            if (preg_match('/\b(\d{4})-(\d{2})-(\d{2})\b/', $line, $match)) {
                if (checkdate((int) $match[2], (int) $match[3], (int) $match[1])) {
                    $draft['date'] = sprintf('%04d-%02d-%02d', $match[1], $match[2], $match[3]);
                }
            } elseif ($draft['date'] === '' && preg_match('/\b(\d{2})\.(\d{2})\.(\d{4})\b/', $line, $match)) {
                if (checkdate((int) $match[2], (int) $match[1], (int) $match[3])) {
                    $draft['date'] = sprintf('%04d-%02d-%02d', $match[3], $match[2], $match[1]);
                }
            }
            if (preg_match('/E[AE]P\s*([0-9]{8,16})/i', $line, $match)) {
                $draft['unique_no'] = 'EAP' . $match[1];
            }
            if (preg_match('/\b(20\d{2}\/\d{6,12})\b/', $line, $match)) {
                $draft['evidence_no'] = $match[1];
            }
            if (preg_match_all('/PTU\s+([A-G])\s+(\d{1,2})\s*%\s+([\d\s]+[.,]\d{2})/iu', $line, $found, PREG_SET_ORDER)) {
                foreach ($found as $hit) {
                    $letter = strtoupper($hit[1]);
                    $rates[$letter]['percent'] = (int) $hit[2];
                    $rates[$letter]['vat'] = money_to_float($hit[3]);
                    if (!isset($rates[$letter]['gross'])) {
                        $rates[$letter]['gross'] = null;
                    }
                }
            }
            if (preg_match('/OPODATK\.?\s*([A-G])\s+([\d\s]+[.,]\d{2})/iu', $line, $match)) {
                $letter = strtoupper($match[1]);
                $rates[$letter]['gross'] = money_to_float($match[2]);
                if (!isset($rates[$letter]['percent'])) {
                    $rates[$letter]['percent'] = self::defaultRate($letter);
                }
                if (!isset($rates[$letter]['vat'])) {
                    $rates[$letter]['vat'] = 0.0;
                }
            }
            if (preg_match('/SU[MH]A\s+PLN\s+([\d\s]+[.,]\d{2})/iu', $line, $match)) {
                $draft['gross'] = money_to_float($match[1]);
            }
            if (preg_match('/SU[MH]A\s+PTU\s+([\d\s]+[.,]\d{2})/iu', $line, $match)) {
                $draft['vat'] = money_to_float($match[1]);
            }
        }

        // Copy printouts use NUMER DOKUMENTU. Original receipts use nr:.
        $draft['receipt_no'] = $documentNo !== '' ? $documentNo : ($receiptNr !== '' ? $receiptNr : $paragonNo);

        if ($draft['vat'] <= 0) {
            $sum = 0.0;
            foreach ($rates as $rate) {
                $sum += (float) ($rate['vat'] ?? 0);
            }
            $draft['vat'] = round($sum, 2);
        }

        $draft['rates'] = $rates;
        return $draft;
    }

    private static function readItems(array $lines, array $rates): array
    {
        $items = [];
        $buffer = [];
        foreach ($lines as $line) {
            $price = self::matchPrice($line);
            if ($price !== null) {
                if ($price['name'] !== '') {
                    $buffer[] = $price['name'];
                }
                $name = self::cleanProductName(trim(preg_replace('/\s+/u', ' ', implode(' ', $buffer))));
                if ($name === '') {
                    $name = 'Pozycja';
                }
                $letter = $price['letter'];
                $percent = self::rateForLetter($letter, $rates);
                $unit = $price['unit'];
                if ($unit === '' && preg_match('/^Dostawa\b/iu', $name)) {
                    $unit = 'Sztuka';
                }
                $items[] = [
                    'name' => $name,
                    'qty' => $price['qty'],
                    'unit' => $unit,
                    'unit_price' => $price['unit_price'],
                    'value' => $price['value'],
                    'vat_rate' => $percent,
                ];
                $buffer = [];
                continue;
            }
            if (self::isMeta($line)) {
                $buffer = [];
                continue;
            }
            // Failed price-looking lines must not leak into the next product name.
            if (self::looksLikePriceLine($line)) {
                continue;
            }
            $buffer[] = $line;
        }
        return $items;
    }

    private static function looksLikePriceLine(string $line): bool
    {
        $line = self::normalizePriceLine($line);
        if (preg_match('/\d+(?:[.,]\d+)?\s*(?:s?zt\.?|para|kg)?\s*[x×]\s*\d+[.,]\d{2}/iu', $line)) {
            return true;
        }
        if (preg_match('/^[\{\[\(]*(?:\d+)?\s*(?:s?zt\.?|para)\b/iu', $line)
            && preg_match('/\d+[.,]\d{2}/', $line)) {
            return true;
        }
        return false;
    }

    private static function cleanProductName(string $name): string
    {
        // Strip header fragments OCR glued onto the first product.
        $name = preg_replace('/^(?:RODZAJ|NUMER)\s+DOKU[A-Z]*ENTU\s*:?\s*(?:PF|P[EF])?\s*/iu', '', $name);
        $name = preg_replace('/^NIP\s*:?\s*\d+\s*/iu', '', $name);
        $name = preg_replace('/\b(?:Dostav[ae]|Dostaua)\b/iu', 'Dostawa', $name);
        $name = preg_replace('/\b(?:[Zl]estau|[Zz]estau)\b/iu', 'Zestaw', $name);
        // Copy printouts often truncate "Rękawice" to "Reka."
        $name = preg_replace('/^Reka\.?\s*/iu', 'Rękawice ', $name);
        $name = preg_replace('/^[fF]?\(?\s*chr\./iu', 'Ochr.', $name);
        $name = preg_replace('/^Ochr,\s*/iu', 'Ochr. ', $name);
        // Drop a price fragment that OCR glued onto / into the product name.
        $name = preg_replace('/^[\{\[\(]*(?:\d+)?\s*(?:s?zt\.?|para)\s*[x×X].*?\d+[.,]\d{2}\s*[A-G0-9]?\s*/iu', '', $name);
        $name = preg_replace('/\s+\d+(?:[.,]\d+)?\s*s?zt\.?\s*[x×X].*$/u', '', $name);
        $name = preg_replace('/\s+\d+(?:[.,]\d+)?\s*para\s*[x×X].*$/iu', '', $name);
        // OCR often turns XP-1701 into X0-1/01 or similar.
        $name = preg_replace('/\bX[O0]-?1\/01\b/iu', 'XP-1701', $name);
        $name = preg_replace('/\bX[O0]-1701\b/iu', 'XP-1701', $name);
        $name = preg_replace('/\bXHRAP\b/iu', 'XWRAP', $name);
        $name = preg_replace('/\bKHRAP\b/iu', 'XWRAP', $name);
        $name = preg_replace('/\bREO\b/', 'RED', $name);
        $name = preg_replace('/\bAP-(\d+)/iu', 'XP-$1', $name);
        $name = preg_replace('/\b(\d+)0Z\b/u', '$1OZ', $name);
        $name = preg_replace('/\b(\d+)60Z\b/u', '$16OZ', $name);
        $name = preg_replace('/\b(XP-\d+)-60Z\b/iu', '$1-6OZ', $name);
        $name = preg_replace('/\b(XP-\d+)-602\b/iu', '$1-6OZ', $name);
        return trim($name, " \t-–:");
    }

    private static function matchPrice(string $line): ?array
    {
        $line = self::normalizePriceLine($line);
        // qty [unit] x unit_price value [VAT letter]
        $pattern = '/^(.*?)(\d+(?:[.,]\d+)?)\s*(s?zt\.?|sztuk[ai]?|para|kg|kpl|op\.?|opak\.?)?\s*[x×]\s*(\d{1,7}(?:[ ]\d{3})*[.,]\d{2})\s+(\d{1,7}(?:[ ]\d{3})*[.,]\d{2})\s*([A-G])?\s*$/iu';
        if (!preg_match($pattern, $line, $match)) {
            // Fallback: qty unit_price valueLETTER with no clear "x"
            $pattern = '/^(.*?)(\d+(?:[.,]\d+)?)\s*(s?zt\.?|sztuk[ai]?|para|kg|kpl|op\.?|opak\.?)?\s+(\d{1,7}(?:[ ]\d{3})*[.,]\d{2})\s+(\d{1,7}(?:[ ]\d{3})*[.,]\d{2})\s*([A-G])\s*$/iu';
            if (!preg_match($pattern, $line, $match)) {
                return null;
            }
        }
        $qty = money_to_float($match[2]);
        $unitPrice = money_to_float($match[4]);
        $value = money_to_float($match[5]);
        if ($value <= 0 && $qty > 0 && $unitPrice > 0) {
            $value = round($qty * $unitPrice, 2);
        }
        // OCR often turns "1szt" into "15zt". If unit price equals line total, quantity is 1.
        if ($qty > 1 && $unitPrice > 0 && abs($unitPrice - $value) < 0.001) {
            $qty = 1.0;
        }
        // When quantity is 1, unit price and line total should match. Prefer the unit price.
        if ($qty > 0 && abs($qty - 1.0) < 0.001 && $unitPrice > 0 && abs($unitPrice - $value) > 0.02) {
            $value = $unitPrice;
        } elseif ($qty > 0 && $unitPrice > 0 && $value > 0 && abs(round($qty * $unitPrice, 2) - $value) > 0.05) {
            // Prefer reconstructing unit price from the line total when OCR mangled the middle amount
            // (e.g. "2 para x/1,40 142,80" → unit should be 71,40).
            $derived = round($value / $qty, 2);
            if ($derived > 1 && abs(round($qty * $derived, 2) - $value) < 0.02) {
                $unitPrice = $derived;
            } else {
                $value = round($qty * $unitPrice, 2);
            }
        }
        $letter = strtoupper((string) ($match[6] ?? ''));
        if ($letter !== '' && !preg_match('/^[A-G]$/', $letter)) {
            $letter = '';
        }
        return [
            'name' => trim($match[1]),
            'qty' => $qty,
            'unit' => self::unitLabel($match[3] ?? ''),
            'unit_price' => $unitPrice,
            'value' => $value,
            'letter' => $letter,
        ];
    }

    private static function normalizePriceLine(string $line): string
    {
        $line = self::normalize($line);
        // Dostawa OCR: "(5zł" → "15zł" (must run before stripping "(").
        $line = preg_replace('/\((\d)zł/iu', '1$1zł', $line);
        // Strip OCR junk brackets/slashes/quotes glued to quantity.
        $line = preg_replace('/^[\{\[\(\\\\\'‘’`]+/u', '', $line);
        $line = preg_replace('/\s+[\{\[\(\\\\\'‘’`]+(?=para|szt)/iu', ' ', $line);
        // OCR often turns "para" into Cyrillic lookalikes: paгa, рага, etc.
        $line = preg_replace('/\bp[aа][rгp][aа]\b/iu', 'para', $line);
        // "2para" / "Zpara" / bare "para x..." → explicit quantity + unit
        $line = preg_replace('/\bZpara\b/iu', '2 para', $line);
        $line = preg_replace('/\b(\d+)\s*para\b/iu', '$1 para', $line);
        $line = preg_replace('/^(para)\b/iu', '1 para', $line);
        // "'szt," / "‘szt." → "1szt."
        $line = preg_replace('/[\'‘’`]\s*szt[,.]?/iu', '1szt.', $line);
        $line = preg_replace('/\bszt,/iu', 'szt.', $line);
        // Bare "szt. x" after a product name → "1szt. x"
        $line = preg_replace('/(?<=\s)szt\.?\s*[x×]/iu', '1szt. x', $line);
        // Common OCR mistakes for "1szt." / "{szt." / "Iszt."
        $line = preg_replace('/^[\{\[\(]*\s*szt\.?/iu', '1szt.', $line);
        $line = preg_replace('/\b15\s*zt\.?/iu', '1szt.', $line);
        $line = preg_replace('/\b[tlis]\s*szt\.?/iu', '1szt.', $line);
        $line = preg_replace('/\biszt\.?/iu', '1szt.', $line);
        $line = preg_replace('/\btszt\.?/iu', '1szt.', $line);
        $line = preg_replace('/\b[Il]\s*szt\.?/iu', '1szt.', $line);
        $line = preg_replace('/\b(\d+)\s*zt\.?/iu', '$1szt.', $line);
        // Tesseract often reads the multiply sign as « » ‹ › ¥ * or /
        $line = preg_replace('/\s*[x×XхХ«»‹›¥\*]\s*/u', ' x', $line);
        $line = preg_replace('/\sx\/(?=\d)/u', ' x', $line);
        // Digits inside money amounts misread as letters: 57,G0 → 57,00
        $line = preg_replace('/(\d[.,])[OoQG](\d)/u', '${1}0$2', $line);
        $line = preg_replace('/(\d[.,]\d)[OoQG](?=\D|$)/u', '${1}0', $line);
        // VAT letter misread as a digit/letter stuck to the amount.
        $line = preg_replace('/([.,]\d{2})([88])\s*$/u', '$1B', $line);
        $line = preg_replace('/([.,]\d{2})([44])\s*$/u', '$1A', $line);
        $line = preg_replace('/([.,]\d{2})R\s*$/u', '$1A', $line);
        return trim($line);
    }

    private static function unitLabel(string $raw): string
    {
        $raw = mb_strtolower(trim($raw, " \t."));
        if ($raw === '') {
            return '';
        }
        if (strpos($raw, 'szt') === 0 || $raw === 'zt') {
            return 'Sztuka';
        }
        if ($raw === 'para') {
            return 'Para';
        }
        if ($raw === 'kg') {
            return 'Kg';
        }
        return '';
    }

    private static function rateForLetter(string $letter, array $rates): float
    {
        if ($letter !== '' && isset($rates[$letter]['percent'])) {
            return (float) $rates[$letter]['percent'];
        }
        if ($letter === '' && count($rates) === 1) {
            $only = reset($rates);
            return (float) $only['percent'];
        }
        if ($letter !== '') {
            return (float) self::defaultRate($letter);
        }
        return 23.0;
    }

    private static function defaultRate(string $letter): int
    {
        $map = ['A' => 23, 'B' => 8, 'C' => 5, 'D' => 0, 'E' => 0, 'F' => 0, 'G' => 0];
        return $map[$letter] ?? 23;
    }

    private static function isMeta(string $line): bool
    {
        if (preg_match('/[x×]\s*\d+[.,]\d{2}/u', $line)) {
            return false;
        }
        if (preg_match('/PARAGON|FISKALNY|NIP\b|SUMA|PTU|SPRZED|OPODATK|ROZLICZ|PRZELEW|KIEROWNIK|GOTOW|KARTA/iu', $line)) {
            return true;
        }
        if (preg_match('/NUMER\s+DOKU[A-Z]*ENTU|RODZAJ\s+DOKU[A-Z]*ENTU|NUMER\s+KASY|OZNACZENIE\s+KASJERA|DATA\s+I\s+CZAS|PODPIS\s+CYFROWY|NUMER\s+UNIKATOWY|NUMER\s+UNTKATONY|CHRONIONEJ|HYDRUK|WYDRUK|PAMI[EĘ]CI/iu', $line)) {
            return true;
        }
        // Company header only — keep product lines like "XPUNCH Produkt XP-3000".
        if (preg_match('/SP\.\s*Z\s*O\.O/iu', $line)) {
            return true;
        }
        if (preg_match('/^XPUNCH\b/iu', $line) && !preg_match('/\bProdukt\b|XP-\d+/iu', $line)) {
            return true;
        }
        if (preg_match('/^\d{2}-\d{3}\b/', $line)) {
            return true;
        }
        if (preg_match('/\bnr\s*[:.]?\s*\d{1,8}\b/iu', $line)) {
            return true;
        }
        if (preg_match('/\b\d{4}-\d{2}-\d{2}\b/', $line) || preg_match('/\b\d{2}\.\d{2}\.\d{4}\b/', $line)) {
            return true;
        }
        if (preg_match('/E+AP\s*\d{6,}/i', $line)) {
            return true;
        }
        if (preg_match('/\b20\d{2}\/\d{6,}\b/', $line)) {
            return true;
        }
        $compact = preg_replace('/\s+/', '', $line);
        if (preg_match('/^[0-9A-F]{16,}$/i', $compact)) {
            return true;
        }
        return false;
    }

    public static function groupLines(array $tokens): array
    {
        if (!$tokens) {
            return [];
        }
        usort($tokens, function ($a, $b) {
            if ($a['y'] === $b['y']) {
                return $a['x'] <=> $b['x'];
            }
            return $a['y'] < $b['y'] ? 1 : -1;
        });

        $groups = [];
        foreach ($tokens as $token) {
            $token['text'] = self::normalize($token['text']);
            if ($token['text'] === '') {
                continue;
            }
            $last = count($groups) - 1;
            if ($last >= 0 && abs($groups[$last]['y'] - $token['y']) <= 0.016) {
                $groups[$last]['parts'][] = $token;
                continue;
            }
            $groups[] = [
                'y' => $token['y'],
                'parts' => [$token],
            ];
        }
        return $groups;
    }

    public static function normalize(string $text): string
    {
        $map = [
            'А' => 'A', 'В' => 'B', 'Е' => 'E', 'К' => 'K', 'М' => 'M', 'Н' => 'H',
            'О' => 'O', 'Р' => 'P', 'С' => 'C', 'Т' => 'T', 'Х' => 'X', 'І' => 'I',
            'Г' => 'R', 'а' => 'a', 'е' => 'e', 'о' => 'o', 'р' => 'p', 'с' => 'c',
            'у' => 'y', 'х' => 'x', 'і' => 'i', 'г' => 'r', 'к' => 'k', 'м' => 'm',
            'т' => 't', 'в' => 'b', 'н' => 'h',
        ];
        $text = strtr($text, $map);
        // Space inside money amounts; avoid \b because "40A" has no boundary before A.
        $text = preg_replace('/(\d),\s+(\d{2})(?=\D|$)/u', '$1,$2', $text);
        $text = preg_replace('/\s+/u', ' ', $text);
        return trim($text);
    }
}
