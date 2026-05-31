<?php

declare(strict_types=1);

/**
 * Náhled faktury do HTML — pro ladění grafiky šablony bez generování PDF.
 *
 * Renderuje stejné soubory, které používá ostrý PDF render:
 *   - api/templates/invoice/invoice.twig
 *   - styles/invoice.css   (vloženo do {{ css|raw }})
 *
 * Plní demo data (faktura 20260009, neplátce DPH, CZK). HTML výstup je jen pro
 * vizuální ladění v prohlížeči — NENÍ to mPDF render, takže drobné rozdíly (rotace,
 * page-break, fonty) jsou očekávané. Pro finální kontrolu vždy vygeneruj reálné PDF.
 *
 * Spuštění (PHP + Twig jsou v Docker image, šablona/CSS bind-mountnuté z hostu):
 *
 *   docker compose run --rm --no-deps -v "$PWD:/src" -w /src app \
 *     php tools/preview-invoice.php
 *
 * Volitelně scénář a výstupní soubor:
 *   php tools/preview-invoice.php [vat|foreign|paid|proforma|plain] [out.html]
 *
 * Edituj styles/invoice.css nebo invoice.twig → spusť znovu → otevři výsledné HTML.
 */

$root = dirname(__DIR__);

// Twig autoloader — host vendor (pokud existuje) nebo image (/var/www/html/api/vendor).
$autoloadCandidates = [
    $root . '/api/vendor/autoload.php',
    '/var/www/html/api/vendor/autoload.php',
];
$autoload = null;
foreach ($autoloadCandidates as $cand) {
    if (is_file($cand)) { $autoload = $cand; break; }
}
if ($autoload === null) {
    fwrite(STDERR, "CHYBA: nenašel jsem vendor/autoload.php (Twig). Spusť skript uvnitř Docker image, kde je api/vendor.\n");
    fwrite(STDERR, "  docker compose run --rm --no-deps -v \"\$PWD:/src\" -w /src app php tools/preview-invoice.php\n");
    exit(1);
}
require $autoload;

use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

$scenario = $argv[1] ?? 'plain';
// Výchozí výstup do preview/ — stejnou složku serveruje náhledový server (.claude/launch.json).
// plain → preview/index.html (otevře se rovnou), ostatní scénáře → preview/<scenario>.html
$previewDir = $root . '/preview';
if (!is_dir($previewDir)) { @mkdir($previewDir, 0777, true); }
$outFile  = $argv[2] ?? ($previewDir . '/' . ($scenario === 'plain' ? 'index' : $scenario) . '.html');

$locale      = 'cs';
$dateFormat  = $locale === 'en' ? 'M j, Y' : 'j. n. Y';
$decimalSep  = $locale === 'en' ? '.' : ',';
$thousandSep = $locale === 'en' ? ',' : ' ';

/* ─── Demo data ─── */

$supplier = [
    'company_name'        => 'Lukáš Duží',
    'display_name'        => 'Lukáš Duží',
    'street'              => 'Na Prádle 3347/4',
    'zip'                 => '702 00',
    'city'                => 'Ostrava',
    'country_name_cs'     => 'Česká republika',
    'country_name_en'     => 'Czech Republic',
    'ic'                  => '03143015',
    'dic'                 => '',
    'is_vat_payer'        => false,
    'commercial_register' => 'SMO/233023/14/ŽÚ/BUP',
    'web'                 => '',
    'email'               => 'lukasduzi@gmail.com',
    'pdf_logo_show_name'  => false,
];

$client = [
    'company_name'    => 'HARDMIN s.r.o.',
    'first_name'      => '',
    'last_name'       => '',
    'street'          => 'Bělehradská 858/23',
    'zip'             => '120 00',
    'city'            => 'Praha',
    'country_name_cs' => 'Česká republika',
    'country_name_en' => 'Czech Republic',
    'ic'              => '14277131',
    'dic'             => 'CZ14277131',
];

$bank = [
    'account_number' => '1204382017',
    'bank_code'      => '3030',
    'iban'           => 'CZ62 3030 0000 0012 0438 2017',
    'bic'            => 'AIRACZPP',
    'bank_name'      => 'Air Bank a.s.',
];

$items = [
    [
        'item_kind'              => 'service',
        'description'            => 'Vývoj firmware BMS Baterii FEB',
        'quantity'               => 50.0,
        'unit'                   => 'h',
        'unit_price_without_vat' => 750.0,
        'vat_rate_snapshot'      => 21.0,
        'total_without_vat'      => 37500.0,
        'total_with_vat'         => 45375.0,
    ],
    [
        'item_kind'              => 'service',
        'description'            => 'Vývoj firmwaru pro CB4.0',
        'quantity'               => 84.0,
        'unit'                   => 'h',
        'unit_price_without_vat' => 750.0,
        'vat_rate_snapshot'      => 21.0,
        'total_without_vat'      => 63000.0,
        'total_with_vat'         => 76230.0,
    ],
];

$invoice = [
    'id'                  => 9,
    'invoice_type'        => 'invoice',
    'status'              => 'issued',
    'language'            => $locale,
    'varsymbol'           => '20260009',
    'issue_date'          => '2026-05-15',
    'tax_date'            => null,
    'due_date'            => '2026-05-29',
    'paid_at'             => null,
    'currency'            => 'CZK',
    'payment_method'      => 'bank_transfer',
    'project_name'        => '',
    'project_number'      => '',
    'contract_number'     => '',
    'note_above_items'    => '',
    'note_below_items'    => 'Dovolujeme si Vás upozornit, že v případě nedodržení data splatnosti uvedeného na faktuře Vám můžeme účtovat zákonný úrok z prodlení.',
    'reverse_charge'      => false,
    'items'               => $items,
    'vat_breakdown'       => [],
    'totals'              => ['without_vat' => 100500.0, 'vat' => 0.0, 'with_vat' => 100500.0],
    'advance_paid_amount' => 0.0,
    'amount_to_pay'       => 100500.0,
    'czk_recap'           => null,
];

$isPaid          = false;
$paymentMethod   = 'bank_transfer';
$isdocAttachment = true;
$docTypeLabel    = 'Faktura';

/* ─── Scénáře pro ladění dalších větví šablony ─── */
switch ($scenario) {
    case 'vat': // plátce DPH, sazba 21 % konzistentní s položkami
        $supplier['is_vat_payer'] = true;
        $supplier['dic'] = 'CZ03143015';
        $invoice['tax_date'] = '2026-05-15';
        $invoice['vat_breakdown'] = [
            ['rate' => 21.0, 'base' => 100500.0, 'vat' => 21105.0],
        ];
        $invoice['totals'] = ['without_vat' => 100500.0, 'vat' => 21105.0, 'with_vat' => 121605.0];
        $invoice['amount_to_pay'] = 121605.0;
        $docTypeLabel = 'Faktura — daňový doklad';
        break;

    case 'foreign': // cizí měna s přepočtem do CZK
        $invoice['currency'] = 'EUR';
        $invoice['totals'] = ['without_vat' => 4020.0, 'vat' => 0.0, 'with_vat' => 4020.0];
        $invoice['amount_to_pay'] = 4020.0;
        $bank['iban'] = 'CZ62 3030 0000 0012 0438 2017';
        $invoice['czk_recap'] = [
            'rate' => 25.0,
            'rate_date' => '14. 5. 2026',
            'breakdown' => [],
            'total_without_vat_czk' => 100500.0,
            'total_vat_czk' => 0.0,
            'total_with_vat_czk' => 100500.0,
        ];
        break;

    case 'paid': // zaplaceno
        $isPaid = true;
        $invoice['status'] = 'paid';
        $invoice['paid_at'] = '2026-05-20';
        break;

    case 'proforma':
        $invoice['invoice_type'] = 'proforma';
        $docTypeLabel = 'Proforma faktura';
        break;
}

/* ─── QR placeholder (data URI) — jen vizuální výplň, ne reálný QR ─── */
$qrUri = $isPaid ? null : qrPlaceholderDataUri((string) $invoice['varsymbol']);

/* ─── CSS ─── */
$cssPath = $root . '/styles/invoice.css';
$css = is_file($cssPath) ? (string) file_get_contents($cssPath) : '';

/* ─── Twig ─── */
$loader = new FilesystemLoader($root . '/api/templates/invoice');
$twig = new Environment($loader, ['autoescape' => 'html', 'cache' => false, 'strict_variables' => false]);
$twig->addFunction(new TwigFunction('t', static fn(string $cs, string $en): string => $locale === 'en' ? $en : $cs));

$html = $twig->render('invoice.twig', [
    'invoice'          => $invoice,
    'supplier'         => $supplier,
    'client'           => $client,
    'bank'             => $bank,
    'qr_data_uri'      => $qrUri,
    'is_paid'          => $isPaid,
    'payment_method'   => $paymentMethod,
    'locale'           => $locale,
    'doc_type_label'   => $docTypeLabel,
    'doc_title'        => $docTypeLabel,
    'parent_varsymbol' => null,
    'work_report'      => null,
    'date_format'      => $dateFormat,
    'decimal_sep'      => $decimalSep,
    'thousand_sep'     => $thousandSep,
    'css'              => $css,
    'logo_path'        => null,
    'logo_show_name'   => false,
    'isdoc_attachment' => $isdocAttachment,
]);

/* ─── mPDF-only tagy odstranit ─── */
// V prohlížeči se <sethtmlpagefooter ... /> nechová jako self-closing → pohltil by celý
// obsah faktury (a stránka by byla prázdná). V ostrém PDF (mPDF) zůstávají v šabloně.
$html = preg_replace('#<htmlpagefooter\b[^>]*>.*?</htmlpagefooter>#is', '', $html);
$html = preg_replace('#<sethtmlpagefooter\b[^>]*/?>#i', '', $html);

/* ─── Preview chrome: simulace A4 stránky ─── */
$chrome = <<<CSS
<style id="preview-chrome">
  /* POUZE pro tento HTML náhled — v ostrém PDF se neaplikuje. */
  html { background: #e9e7ef; }
  body {
    width: 210mm;
    min-height: 297mm;
    margin: 8mm auto;
    padding: 15mm 15mm 18mm;   /* = mPDF margin_top/left/right/bottom */
    background: #ffffff;
    box-shadow: 0 2mm 9mm rgba(0,0,0,.28);
  }
</style>
CSS;
$html = str_replace('</head>', $chrome . "\n</head>", $html);

file_put_contents($outFile, $html);
fwrite(STDERR, "Náhled vygenerován: {$outFile}\n");
fwrite(STDERR, "Scénář: {$scenario}  (další: vat | foreign | paid | proforma)\n");


/**
 * Deterministický pseudo-QR jako data:URI SVG — jen aby v náhledu seděl prostor a vzhled.
 */
function qrPlaceholderDataUri(string $seed): string
{
    $n = 25;
    $cell = 4;
    $size = $n * $cell;
    $h = crc32($seed);
    $rects = '';
    for ($y = 0; $y < $n; $y++) {
        for ($x = 0; $x < $n; $x++) {
            // Finder patterny v rozích
            $inFinder = ($x < 7 && $y < 7) || ($x >= $n - 7 && $y < 7) || ($x < 7 && $y >= $n - 7);
            if ($inFinder) {
                $lx = $x >= $n - 7 ? $x - ($n - 7) : $x;
                $ly = $y >= $n - 7 ? $y - ($n - 7) : $y;
                $on = ($lx === 0 || $lx === 6 || $ly === 0 || $ly === 6) || ($lx >= 2 && $lx <= 4 && $ly >= 2 && $ly <= 4);
            } else {
                $on = ((($x * 73 + $y * 151 + $h) >> (($x + $y) % 7)) & 1) === 1;
            }
            if ($on) {
                $px = $x * $cell;
                $py = $y * $cell;
                $rects .= "<rect x=\"{$px}\" y=\"{$py}\" width=\"{$cell}\" height=\"{$cell}\"/>";
            }
        }
    }
    $svg = "<svg xmlns=\"http://www.w3.org/2000/svg\" width=\"{$size}\" height=\"{$size}\" "
        . "viewBox=\"0 0 {$size} {$size}\"><rect width=\"{$size}\" height=\"{$size}\" fill=\"#fff\"/>"
        . "<g fill=\"#15131D\">{$rects}</g></svg>";
    return 'data:image/svg+xml;base64,' . base64_encode($svg);
}
