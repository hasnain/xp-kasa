<?php

class ProtocolPdf
{
    public static function render(array $protocol, string $imagePath): string
    {
        $items = $protocol['items'];
        while (count($items) < 4) {
            $items[] = [
                'name' => '',
                'unit' => '',
                'qty_label' => '',
                'vat_label' => '',
                'price_label' => '',
                'value_label' => '',
            ];
        }

        $rows = '';
        foreach ($items as $index => $item) {
            $rows .= '<tr>'
                . '<td class="center">' . ($index + 1) . '.</td>'
                . '<td>' . e($item['name']) . '</td>'
                . '<td class="center">' . e($item['unit']) . '</td>'
                . '<td class="center">' . e($item['qty_label']) . '</td>'
                . '<td class="center">' . e($item['vat_label']) . '</td>'
                . '<td class="center">' . e($item['price_label']) . '</td>'
                . '<td class="center">' . e($item['value_label']) . '</td>'
                . '</tr>';
        }

        $vatRows = '';
        foreach ($protocol['vat_rows'] as $row) {
            $vatRows .= '<tr>'
                . '<td class="center">' . e($row['rate_label']) . '</td>'
                . '<td class="center">' . e($row['vat_label']) . '</td>'
                . '<td class="center">' . e($row['gross_label']) . '</td>'
                . '</tr>';
        }
        $vatRows .= '<tr><td>&nbsp;</td><td></td><td></td></tr>';

        $imageHtml = '';
        if ($imagePath !== '' && is_file($imagePath)) {
            $imageHtml = '<img src="' . e($imagePath) . '" style="width:68mm;">';
        }

        $html = '<style>
            body { font-family: aptos; font-size: 12pt; color: #000; }
            h1 { font-family: aptos; font-size: 12pt; margin: 0 0 8px; font-weight: bold; }
            .meta { margin: 0 0 12px; font-size: 12pt; }
            .lead { margin: 0 0 6px; font-size: 12pt; }
            table.items { width: 100%; border-collapse: collapse; margin: 0 0 16px; }
            table.items th, table.items td { border: 0.6pt solid #000; padding: 4px 4px; vertical-align: top; font-size: 12pt; }
            table.items th { font-weight: bold; text-align: center; font-size: 10pt; line-height: 1.2; }
            table.vat { width: 100%; border-collapse: collapse; margin: 4px 0 14px; }
            table.vat th, table.vat td { border: 0.6pt solid #000; padding: 4px 5px; font-size: 12pt; }
            table.vat th { font-weight: bold; text-align: center; font-size: 10pt; }
            table.bottom { width: 100%; border-collapse: collapse; }
            table.bottom td { vertical-align: top; }
            .center { text-align: center; }
            .reason { margin: 8pt 0 18pt; font-size: 12pt; line-height: 1.6; }
            .sum-intro { margin: 0 0 16pt; font-size: 12pt; line-height: 1.6; }
            .sum { margin: 0 0 14pt; font-size: 12pt; line-height: 1.6; }
            .sign { text-align: center; margin-top: 36pt; font-size: 12pt; line-height: 1.8; }
            .sign .label { margin-top: 4pt; }
            p { margin: 0; }
        </style>
        <h1>Protokół anulowania paragonu fiskalnego nr ' . e($protocol['receipt_no']) . ' z dnia ' . e($protocol['date_label']) . '</h1>
        <p class="meta">Kasa fiskalna o numerze unikatowym ' . e($protocol['unique_no']) . ' ewidencyjnym ' . e($protocol['evidence_no']) . '</p>
        <p class="lead">Wykaz pozycji zarejestrowanych na w/w paragonie:</p>
        <table class="items">
            <thead>
                <tr>
                    <th style="width:7%;">lp.</th>
                    <th style="width:31%;">nazwa/ name</th>
                    <th style="width:11%;">j.m. / unit</th>
                    <th style="width:11%;">ilość /<br>quantity</th>
                    <th style="width:12%;">stawka VAT/<br>VAT unit</th>
                    <th style="width:14%;">cena<br>jednostkowa /<br>price per unit</th>
                    <th style="width:14%;">wartość /<br>value</th>
                </tr>
            </thead>
            <tbody>' . $rows . '</tbody>
        </table>
        <table class="bottom">
            <tr>
                <td style="width:56%; padding-right:12px;">
                    <p class="lead">Rozliczenie podatku VAT</p>
                    <table class="vat">
                        <thead>
                            <tr>
                                <th>stawka VAT/<br>VAT unit</th>
                                <th>kwota VAT/<br>VAT amount</th>
                                <th>brutto</th>
                            </tr>
                        </thead>
                        <tbody>' . $vatRows . '</tbody>
                    </table>
                    <p class="reason">Powód anulowania paragonu: ' . e($protocol['reason']) . '</p>
                    <br>
                    <p class="sum-intro">W związku z powyższym zmniejsza się:</p>
                    <br>
                    <p class="sum">Sprzedaż brutto o kwotę: ' . e($protocol['gross_label']) . ' złotych</p>
                    <br>
                    <p class="sum">Podatek należny o kwotę: ' . e($protocol['vat_label']) . ' złotych</p>
                    <br>
                    <p class="sum">Sprzedaż netto o kwotę: ' . e($protocol['net_label']) . ' złotych</p>
                </td>
                <td style="width:44%;">
                    ' . $imageHtml . '
                    <div class="sign"><br><br>........................<br>podpis</div>
                </td>
            </tr>
        </table>';

        $mpdf = new \Mpdf\Mpdf([
            'mode' => 'utf-8',
            'format' => 'A4',
            'margin_left' => 15,
            'margin_right' => 15,
            'margin_top' => 16,
            'margin_bottom' => 16,
            'default_font' => 'aptos',
            'tempDir' => storage_path('tmp'),
            'fontDir' => [
                app_path('fonts'),
                (new \Mpdf\Config\ConfigVariables())->getDefaults()['fontDir'][0],
            ],
            'fontdata' => (new \Mpdf\Config\FontVariables())->getDefaults()['fontdata'] + [
                'aptos' => [
                    'R' => 'Aptos.ttf',
                    'B' => 'Aptos-Bold.ttf',
                    'I' => 'Aptos-Italic.ttf',
                    'BI' => 'Aptos-Bold-Italic.ttf',
                ],
            ],
        ]);
        $mpdf->WriteHTML($html);
        return $mpdf->Output('', \Mpdf\Output\Destination::STRING_RETURN);
    }
}
