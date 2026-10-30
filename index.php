<?php

session_start();

require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/src/bootstrap.php';
require __DIR__ . '/src/Auth.php';
require __DIR__ . '/src/Ocr.php';
require __DIR__ . '/src/ReceiptParser.php';
require __DIR__ . '/src/ProtocolPdf.php';

$error = '';

if (isset($_GET['logout']) || (($_POST['action'] ?? '') === 'logout')) {
    cleanup_session_draft();
    Auth::logout();
    header('Location: index.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'login') {
    $user = trim((string) ($_POST['username'] ?? ''));
    $pass = (string) ($_POST['password'] ?? '');
    if (Auth::attempt($user, $pass)) {
        header('Location: index.php');
        exit;
    }
    $error = 'Wrong username or password.';
}

if (!Auth::check()) {
    header('Content-Type: text/html; charset=UTF-8');
    render_login($error);
    exit;
}

if (isset($_GET['image'])) {
    stream_receipt_image();
}

if (isset($_GET['new'])) {
    cleanup_session_draft();
    header('Location: index.php');
    exit;
}

if (isset($_GET['ocr_status'])) {
    respond_ocr_status();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'upload') {
        try {
            handle_upload();
            header('Location: index.php');
            exit;
        } catch (Throwable $exception) {
            $error = $exception->getMessage();
        }
    } elseif ($action === 'pdf') {
        try {
            send_protocol();
        } catch (Throwable $exception) {
            $error = $exception->getMessage();
            remember_posted_draft();
        }
    }
}

$draft = $_SESSION['draft'] ?? null;
if (is_array($draft) && !empty($draft['ocr_job_id'])) {
    apply_finished_ocr_job($draft);
    $draft = $_SESSION['draft'] ?? $draft;
}
header('Content-Type: text/html; charset=UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>XPunch return protocol</title>
    <link rel="stylesheet" href="assets/app.css?v=<?= (int) @filemtime(__DIR__ . '/assets/app.css') ?>">
</head>
<body>
<div class="wrap">
    <div class="topbar">
        <div>
            <h1>Return protocol</h1>
            <p class="lede">Upload a receipt photo. Check the products, tick what was returned or cancelled, then download the PDF for the accountant.</p>
        </div>
        <div class="topbar-user">
            <span><?= e(Auth::user()) ?></span>
            <a class="button secondary small" href="index.php?logout=1">Log out</a>
        </div>
    </div>
    <?php if ($error !== ''): ?>
        <p class="error"><?= e($error) ?></p>
    <?php endif; ?>
    <?php if (!$draft): ?>
        <?php render_upload(); ?>
    <?php else: ?>
        <?php render_review($draft); ?>
    <?php endif; ?>
</div>
</body>
</html>
<?php

function render_login(string $error = ''): void
{
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Login — XPunch return protocol</title>
    <link rel="stylesheet" href="assets/app.css?v=<?= (int) @filemtime(__DIR__ . '/assets/app.css') ?>">
</head>
<body>
<div class="wrap login-wrap">
    <h1>Return protocol</h1>
    <p class="lede">Sign in to create return protocols.</p>
    <?php if ($error !== ''): ?>
        <p class="error"><?= e($error) ?></p>
    <?php endif; ?>
    <form class="card login-card" method="post">
        <input type="hidden" name="action" value="login">
        <label class="field">Username
            <input type="text" name="username" autocomplete="username" required autofocus>
        </label>
        <label class="field">Password
            <input type="password" name="password" autocomplete="current-password" required>
        </label>
        <div class="actions">
            <button type="submit">Log in</button>
        </div>
    </form>
</div>
</body>
</html>
    <?php
}

function handle_upload(): void
{
    if (!isset($_FILES['receipt']) || !is_uploaded_file($_FILES['receipt']['tmp_name'])) {
        throw new RuntimeException('Choose a receipt photo first.');
    }
    if (($_FILES['receipt']['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('The file did not upload. Try again.');
    }

    $tmp = $_FILES['receipt']['tmp_name'];
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($tmp) ?: '';
    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/heic' => 'heic',
        'image/heif' => 'heif',
        'application/pdf' => 'pdf',
    ];
    if (!isset($allowed[$mime])) {
        throw new RuntimeException('Use a JPG, PNG, or PDF of the receipt.');
    }

    purge_old_uploads();
    $dir = storage_path('uploads');
    if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
        throw new RuntimeException('Could not store the receipt.');
    }
    $tmpDir = storage_path('tmp');
    if (!is_dir($tmpDir) && !mkdir($tmpDir, 0777, true) && !is_dir($tmpDir)) {
        throw new RuntimeException('Could not store the receipt.');
    }

    $name = bin2hex(random_bytes(16)) . '.' . $allowed[$mime];
    $dest = $dir . '/' . $name;
    if (!move_uploaded_file($tmp, $dest)) {
        throw new RuntimeException('Could not store the receipt.');
    }
    chmod($dest, 0644);

    $previous = $_SESSION['draft']['image'] ?? '';
    if ($previous !== '') {
        delete_upload_files($previous);
    }
    $previousJob = $_SESSION['draft']['ocr_job_id'] ?? '';
    if ($previousJob !== '') {
        @unlink(storage_path('tmp/job-' . $previousJob . '.json'));
    }

    $image = normalize_upload($dest, $mime);
    $jobId = bin2hex(random_bytes(16));
    $jobFile = storage_path('tmp/job-' . $jobId . '.json');
    $job = [
        'id' => $jobId,
        'status' => 'queued',
        'image' => $image,
        'parsed' => null,
        'warning' => '',
        'created_at' => time(),
        'updated_at' => time(),
    ];
    if (file_put_contents($jobFile, json_encode($job)) === false) {
        throw new RuntimeException('Could not start receipt reading.');
    }

    start_ocr_job($jobId);

    $_SESSION['draft'] = [
        'image' => $image,
        'warning' => '',
        'parsed' => ReceiptParser::emptyDraft(),
        'form' => null,
        'ocr_job_id' => $jobId,
        'ocr_status' => 'queued',
    ];
}

function start_ocr_job(string $jobId): void
{
    $php = cli_php_binary();
    $script = app_path('bin/ocr_job.php');
    $cmd = escapeshellarg($php) . ' ' . escapeshellarg($script) . ' ' . escapeshellarg($jobId)
        . ' > /dev/null 2>&1 &';
    exec($cmd);
}

function cli_php_binary(): string
{
    $candidates = [
        '/usr/bin/php8.4',
        '/usr/bin/php8.3',
        '/usr/bin/php8.2',
        '/usr/bin/php',
        '/Applications/XAMPP/xamppfiles/bin/php',
    ];
    foreach ($candidates as $bin) {
        if (is_file($bin) && is_executable($bin)) {
            return $bin;
        }
    }
    $binary = PHP_BINARY ?: 'php';
    if (stripos($binary, 'fpm') !== false) {
        return 'php';
    }
    return $binary;
}

function respond_ocr_status(): void
{
    $draft = $_SESSION['draft'] ?? null;
    $jobId = is_array($draft) ? (string) ($draft['ocr_job_id'] ?? '') : '';
    if ($jobId === '') {
        json_response(['status' => 'idle', 'reload' => false]);
    }

    $status = apply_finished_ocr_job($_SESSION['draft']);
    json_response([
        'status' => $status,
        'reload' => $status === 'done',
    ]);
}

function apply_finished_ocr_job(array $draft): string
{
    $jobId = (string) ($draft['ocr_job_id'] ?? '');
    if ($jobId === '') {
        return 'idle';
    }

    $jobFile = storage_path('tmp/job-' . $jobId . '.json');
    if (!is_file($jobFile)) {
        // Job vanished — let the user edit manually.
        $_SESSION['draft']['ocr_job_id'] = null;
        $_SESSION['draft']['ocr_status'] = 'done';
        $_SESSION['draft']['warning'] = 'The photo could not be read automatically. Type the lines below. The photo will still be placed on the PDF.';
        return 'done';
    }

    $job = json_decode((string) file_get_contents($jobFile), true);
    if (!is_array($job)) {
        return 'running';
    }

    $status = (string) ($job['status'] ?? 'queued');
    $_SESSION['draft']['ocr_status'] = $status;
    if ($status !== 'done') {
        // Safety: if the worker never starts, stop waiting after 90s.
        $started = (int) ($job['created_at'] ?? time());
        if (time() - $started > 90) {
            $_SESSION['draft']['ocr_job_id'] = null;
            $_SESSION['draft']['ocr_status'] = 'done';
            $_SESSION['draft']['parsed'] = ReceiptParser::emptyDraft();
            $_SESSION['draft']['warning'] = 'Receipt reading took too long. Type the lines below. The photo will still be placed on the PDF.';
            @unlink($jobFile);
            return 'done';
        }
        return $status === 'queued' ? 'queued' : 'running';
    }

    $_SESSION['draft']['parsed'] = $job['parsed'] ?? ReceiptParser::emptyDraft();
    $_SESSION['draft']['warning'] = (string) ($job['warning'] ?? '');
    $_SESSION['draft']['ocr_job_id'] = null;
    $_SESSION['draft']['ocr_status'] = 'done';
    @unlink($jobFile);
    return 'done';
}

function json_response(array $payload): void
{
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');
    echo json_encode($payload);
    exit;
}

function normalize_upload(string $path, string $mime): string
{
    if ($mime === 'application/pdf') {
        $png = $path . '.png';
        $python = '/Library/Frameworks/Python.framework/Versions/3.11/bin/python3';
        if (!is_file($python)) {
            $python = 'python3';
        }
        $command = escapeshellarg($python) . ' ' . escapeshellarg(app_path('bin/render_pdf.py'))
            . ' ' . escapeshellarg($path) . ' ' . escapeshellarg($png) . ' 2>&1';
        exec($command, $output, $code);
        if ($code !== 0 || !is_file($png)) {
            throw new RuntimeException('Could not open that PDF. Upload a photo of the receipt instead.');
        }
        chmod($png, 0644);
        return $png;
    }

    if ($mime === 'image/heic' || $mime === 'image/heif') {
        $jpg = $path . '.jpg';
        $command = '/usr/bin/sips -s format jpeg ' . escapeshellarg($path) . ' --out ' . escapeshellarg($jpg) . ' 2>&1';
        exec($command, $output, $code);
        if ($code !== 0 || !is_file($jpg)) {
            throw new RuntimeException('Could not open that photo. Save it as JPG and try again.');
        }
        chmod($jpg, 0644);
        return $jpg;
    }

    return $path;
}

function remember_posted_draft(): void
{
    if (!isset($_SESSION['draft'])) {
        return;
    }
    $_SESSION['draft']['form'] = posted_protocol();
}

function send_protocol(): void
{
    $draft = $_SESSION['draft'] ?? null;
    $image = $draft['image'] ?? '';
    if ($image === '' || uploads_realpath($image) === '') {
        throw new RuntimeException('Upload the receipt again, then create the PDF.');
    }

    $posted = posted_protocol();
    $selected = array_values(array_filter($posted['items'], function ($item) {
        return !empty($item['selected']) && trim($item['name']) !== '';
    }));
    if ($posted['receipt_no'] === '') {
        throw new RuntimeException('Enter the receipt number.');
    }
    if ($posted['date'] === '') {
        throw new RuntimeException('Enter the receipt date.');
    }
    $dateLabel = format_date_pl($posted['date']);
    if ($dateLabel === '') {
        throw new RuntimeException('Use the date format 30.06.2026.');
    }
    if (!$selected) {
        throw new RuntimeException('Tick at least one returned product.');
    }

    $posted = fill_totals($posted, $selected);
    $vatRows = vat_rows_for($posted, $selected);
    $reason = reasons()[$posted['reason']]['pdf'] ?? reasons()['zwrot']['pdf'];

    $items = [];
    foreach ($selected as $item) {
        $items[] = [
            'name' => $item['name'],
            'unit' => $item['unit'],
            'qty_label' => qty_pl($item['qty']),
            'vat_label' => rtrim(rtrim(number_format($item['vat_rate'], 2, ',', ''), '0'), ',') . '%',
            'price_label' => money_pl($item['unit_price']),
            'value_label' => money_pl($item['value']),
        ];
    }

    $binary = ProtocolPdf::render([
        'receipt_no' => $posted['receipt_no'],
        'date_label' => $dateLabel,
        'unique_no' => $posted['unique_no'],
        'evidence_no' => $posted['evidence_no'],
        'reason' => $reason,
        'items' => $items,
        'vat_rows' => $vatRows,
        'gross_label' => money_pl($posted['gross']),
        'vat_label' => money_pl($posted['vat']),
        'net_label' => money_pl($posted['net']),
    ], $image);

    // Image is already embedded in the PDF; remove local files after download.
    cleanup_session_draft();

    $safeNo = preg_replace('/[^0-9A-Za-z_-]+/', '', $posted['receipt_no']);
    $filename = 'Protokol-anulowania-paragonu-' . ($safeNo !== '' ? $safeNo : 'paragon') . '.pdf';
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($binary));
    echo $binary;
    exit;
}

function cleanup_session_draft(): void
{
    $draft = $_SESSION['draft'] ?? null;
    if (is_array($draft)) {
        delete_upload_files($draft['image'] ?? '');
    }
    unset($_SESSION['draft']);
}

function delete_upload_files(string $path): void
{
    $real = uploads_realpath($path);
    if ($real === '') {
        return;
    }
    @unlink($real);
    // PDF uploads also create a rendered PNG beside the original.
    foreach ([$real . '.png', $real . '.jpg', preg_replace('/\.[^.]+$/', '', $real) . '.png'] as $extra) {
        if ($extra && uploads_realpath($extra) !== '') {
            @unlink($extra);
        }
    }
}

function posted_protocol(): array
{
    $reason = $_POST['reason'] ?? 'zwrot';
    if (!isset(reasons()[$reason])) {
        $reason = 'zwrot';
    }
    $items = [];
    foreach ($_POST['items'] ?? [] as $row) {
        if (!is_array($row)) {
            continue;
        }
        $items[] = [
            'selected' => !empty($row['selected']),
            'name' => trim((string) ($row['name'] ?? '')),
            'unit' => in_array(($row['unit'] ?? ''), ['', 'Para', 'Sztuka'], true) ? $row['unit'] : '',
            'qty' => money_to_float($row['qty'] ?? '1'),
            'vat_rate' => money_to_float($row['vat_rate'] ?? '23'),
            'unit_price' => money_to_float($row['unit_price'] ?? '0'),
            'value' => money_to_float($row['value'] ?? '0'),
        ];
    }
    return [
        'receipt_no' => trim((string) ($_POST['receipt_no'] ?? '')),
        'date' => trim((string) ($_POST['date'] ?? '')),
        'unique_no' => trim((string) ($_POST['unique_no'] ?? '')),
        'evidence_no' => trim((string) ($_POST['evidence_no'] ?? DEFAULT_EVIDENCE_NO)),
        'reason' => $reason,
        'gross' => money_to_float($_POST['gross'] ?? '0'),
        'vat' => money_to_float($_POST['vat'] ?? '0'),
        'net' => money_to_float($_POST['net'] ?? '0'),
        'receipt_gross' => money_to_float($_POST['receipt_gross'] ?? '0'),
        'receipt_vat' => money_to_float($_POST['receipt_vat'] ?? '0'),
        'original_count' => (int) ($_POST['original_count'] ?? 0),
        'items' => $items,
    ];
}

function fill_totals(array $posted, array $selected): array
{
    $sums = line_sums($selected);
    $named = array_values(array_filter($posted['items'], function ($item) {
        return trim($item['name']) !== '';
    }));
    $useReceipt = count($selected) === count($named)
        && count($named) === (int) $posted['original_count']
        && $posted['receipt_gross'] > 0
        && abs($sums['gross'] - $posted['receipt_gross']) < 0.05;

    if ($posted['gross'] <= 0) {
        $posted['gross'] = $useReceipt ? $posted['receipt_gross'] : $sums['gross'];
        $posted['vat'] = $useReceipt ? $posted['receipt_vat'] : $sums['vat'];
        $posted['net'] = round($posted['gross'] - $posted['vat'], 2);
        return $posted;
    }
    if ($posted['net'] <= 0) {
        $posted['net'] = round($posted['gross'] - $posted['vat'], 2);
    }
    return $posted;
}

function line_sums(array $selected): array
{
    $gross = 0.0;
    $vat = 0.0;
    foreach ($selected as $item) {
        $gross += $item['value'];
        $vat += line_vat($item['value'], $item['vat_rate']);
    }
    return [
        'gross' => round($gross, 2),
        'vat' => round($vat, 2),
    ];
}

function vat_rows_for(array $posted, array $selected): array
{
    $sums = line_sums($selected);
    $oneRate = count(array_unique(array_map(function ($item) {
        return (string) $item['vat_rate'];
    }, $selected))) <= 1;

    if ($oneRate && abs($posted['gross'] - $sums['gross']) < 0.05) {
        return [[
            'rate_label' => vat_percent_label(infer_rate($selected)),
            'vat_label' => money_pl($posted['vat']),
            'gross_label' => money_pl($posted['gross']),
        ]];
    }

    $groups = [];
    foreach ($selected as $item) {
        $rate = (string) $item['vat_rate'];
        if (!isset($groups[$rate])) {
            $groups[$rate] = ['gross' => 0.0, 'vat' => 0.0, 'rate' => $item['vat_rate']];
        }
        $groups[$rate]['gross'] += $item['value'];
        $groups[$rate]['vat'] += line_vat($item['value'], $item['vat_rate']);
    }
    $rows = [];
    foreach ($groups as $group) {
        $rows[] = [
            'rate_label' => vat_percent_label($group['rate']),
            'vat_label' => money_pl(round($group['vat'], 2)),
            'gross_label' => money_pl(round($group['gross'], 2)),
        ];
    }
    return $rows;
}

function infer_rate(array $items): float
{
    $rates = [];
    foreach ($items as $item) {
        $rates[(string) $item['vat_rate']] = $item['vat_rate'];
    }
    if (count($rates) === 1) {
        return (float) reset($rates);
    }
    return 23.0;
}

function vat_percent_label(float $rate): string
{
    return rtrim(rtrim(number_format($rate, 2, ',', ''), '0'), ',') . '%';
}

function format_date_pl(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }
    foreach (['d.m.Y', 'Y-m-d', 'd/m/Y', 'd-m-Y'] as $format) {
        $date = DateTime::createFromFormat('!' . $format, $value);
        $errors = DateTime::getLastErrors();
        if (!$date instanceof DateTime) {
            continue;
        }
        if (is_array($errors) && (($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0)) {
            continue;
        }
        return $date->format('d.m.Y');
    }
    return '';
}

function stream_receipt_image(): void
{
    $path = uploads_realpath($_SESSION['draft']['image'] ?? '');
    if ($path === '') {
        http_response_code(404);
        exit;
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($path) ?: 'image/jpeg';
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . filesize($path));
    readfile($path);
    exit;
}

function render_upload(): void
{
    ?>
    <form class="card" method="post" enctype="multipart/form-data" id="upload-form">
        <input type="hidden" name="action" value="upload">
        <label class="field" style="max-width:520px;">
            Receipt photo or PDF
            <input type="file" name="receipt" accept=".jpg,.jpeg,.png,.webp,.pdf,.heic,image/*,application/pdf" required>
        </label>
        <p class="note" id="upload-note" hidden>Uploading… reading starts in the background.</p>
        <div class="actions">
            <button type="submit" id="upload-btn">Read receipt</button>
        </div>
    </form>
    <script>
    document.getElementById('upload-form').addEventListener('submit', function () {
        var btn = document.getElementById('upload-btn');
        var note = document.getElementById('upload-note');
        btn.disabled = true;
        btn.textContent = 'Uploading…';
        note.hidden = false;
    });
    </script>
    <?php
}

function render_review(array $draft): void
{
    $ocrPending = !empty($draft['ocr_job_id']);
    $parsed = $draft['parsed'] ?? ReceiptParser::emptyDraft();
    $form = $draft['form'] ?? null;
    $items = $form['items'] ?? null;
    if ($items === null) {
        $items = [];
        foreach ($parsed['items'] as $item) {
            $items[] = [
                'selected' => true,
                'name' => $item['name'],
                'unit' => $item['unit'],
                'qty' => $item['qty'],
                'vat_rate' => $item['vat_rate'],
                'unit_price' => $item['unit_price'],
                'value' => $item['value'],
            ];
        }
    }
    if (!$items) {
        $items[] = blank_item();
        $items[] = blank_item();
    }

    $receiptNo = $form['receipt_no'] ?? $parsed['receipt_no'];
    $date = format_date_pl($form['date'] ?? $parsed['date']);
    $unique = $form['unique_no'] ?? $parsed['unique_no'];
    $evidence = $form['evidence_no'] ?? $parsed['evidence_no'];
    $reason = $form['reason'] ?? 'zwrot';
    $receiptGross = (float) $parsed['gross'];
    $receiptVat = (float) $parsed['vat'];
    $gross = $form['gross'] ?? null;
    $vat = $form['vat'] ?? null;
    $net = $form['net'] ?? null;
    ?>
    <?php if ($ocrPending): ?>
        <div class="card" id="ocr-wait">
            <h2>Reading the receipt…</h2>
            <p class="note">This runs in the background so the page does not time out. It usually finishes in under a minute.</p>
            <p class="note" id="ocr-wait-status">Starting…</p>
            <div class="actions">
                <a class="button secondary" href="index.php?new=1">Cancel</a>
            </div>
        </div>
        <script>
        (function () {
            var statusEl = document.getElementById('ocr-wait-status');
            var tries = 0;
            function poll() {
                tries += 1;
                statusEl.textContent = 'Still reading… (' + tries + ')';
                fetch('index.php?ocr_status=1', { credentials: 'same-origin', cache: 'no-store' })
                    .then(function (r) { return r.json(); })
                    .then(function (data) {
                        if (data.reload || data.status === 'done') {
                            statusEl.textContent = 'Done. Loading…';
                            window.location.reload();
                            return;
                        }
                        setTimeout(poll, 1500);
                    })
                    .catch(function () {
                        setTimeout(poll, 2000);
                    });
            }
            poll();
        })();
        </script>
        <?php
        return;
    endif;
    ?>
    <form method="post" id="protocol-form"
          data-receipt-gross="<?= e($receiptGross) ?>"
          data-receipt-vat="<?= e($receiptVat) ?>">
        <input type="hidden" name="action" value="pdf">
        <input type="hidden" name="receipt_gross" value="<?= e($receiptGross) ?>">
        <input type="hidden" name="receipt_vat" value="<?= e($receiptVat) ?>">
        <input type="hidden" name="original_count" value="<?= count($parsed['items']) ?>">

        <div class="card review-head">
            <div>
                <h2>Check the receipt</h2>
                <?php if (!empty($draft['warning'])): ?>
                    <p class="warn"><?= e($draft['warning']) ?></p>
                <?php endif; ?>
                <p class="note">Ticked lines go on the protocol. Untick a line that was not returned. You can correct any field before the PDF is created.</p>
                <div class="grid">
                    <label class="field">Receipt number
                        <input type="text" name="receipt_no" value="<?= e($receiptNo) ?>" required>
                    </label>
                    <label class="field">Date
                        <input type="text" name="date" value="<?= e($date) ?>" placeholder="30.06.2026" required>
                    </label>
                    <label class="field">Unique cash register no.
                        <input type="text" name="unique_no" value="<?= e($unique) ?>">
                    </label>
                    <label class="field">Evidential no.
                        <input type="text" name="evidence_no" value="<?= e($evidence) ?>">
                    </label>
                </div>
            </div>
            <img class="thumb" alt="Uploaded receipt" src="index.php?image=1">
        </div>

        <div class="card">
            <div class="row" style="justify-content:space-between;">
                <h2>Products</h2>
                <button type="button" class="secondary small" id="add-row">Add line</button>
            </div>
            <div class="table-wrap">
                <table class="products">
                    <thead>
                    <tr>
                        <th><label><input type="checkbox" id="select-all" checked> Returned</label></th>
                        <th>Name</th>
                        <th>Unit</th>
                        <th>Qty</th>
                        <th>VAT %</th>
                        <th>Unit price</th>
                        <th>Value</th>
                        <th></th>
                    </tr>
                    </thead>
                    <tbody id="item-rows">
                    <?php foreach ($items as $index => $item): ?>
                        <?php render_item_row($index, $item); ?>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card">
            <h2>Summary</h2>
            <label class="field" style="max-width:420px;">Reason
                <select name="reason" id="reason">
                    <?php foreach (reasons() as $key => $info): ?>
                        <option value="<?= e($key) ?>" <?= $reason === $key ? 'selected' : '' ?>><?= e($info['label']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <p class="note" id="total-note"></p>
            <div class="totals">
                <label class="field">Gross sale
                    <input type="text" name="gross" id="gross" value="<?= $gross === null ? '' : e(money_pl((float) $gross)) ?>">
                </label>
                <label class="field">VAT
                    <input type="text" name="vat" id="vat" value="<?= $vat === null ? '' : e(money_pl((float) $vat)) ?>">
                </label>
                <label class="field">Net sale
                    <input type="text" name="net" id="net" value="<?= $net === null ? '' : e(money_pl((float) $net)) ?>">
                </label>
            </div>
            <p class="note" id="vat-preview"></p>
            <p class="lede" style="margin:8px 0 0;">Receipt total read from the photo: <?= e(money_pl($receiptGross)) ?> gross, <?= e(money_pl($receiptVat)) ?> VAT.</p>
            <div class="actions">
                <button type="button" class="secondary" id="recalculate">Recalculate totals</button>
                <button type="submit">Create PDF</button>
                <a class="button secondary" href="index.php?new=1">Upload another receipt</a>
            </div>
        </div>
    </form>

    <?php if (!empty($parsed['lines'])): ?>
        <details>
            <summary>Text read from the photo</summary>
            <pre><?= e(implode("\n", $parsed['lines'])) ?></pre>
        </details>
    <?php endif; ?>

    <template id="row-template">
        <?php render_item_row('__INDEX__', blank_item()); ?>
    </template>
    <script>
    (function () {
        var form = document.getElementById('protocol-form');
        var tbody = document.getElementById('item-rows');
        var manual = false;
        var setting = false;
        var receiptGross = parseFloat(form.getAttribute('data-receipt-gross')) || 0;
        var receiptVat = parseFloat(form.getAttribute('data-receipt-vat')) || 0;

        function money(value) {
            var text = String(value).trim().replace(/\s/g, '').replace(',', '.');
            var number = parseFloat(text);
            return isNaN(number) ? 0 : number;
        }
        function format(number) {
            return (Math.round(number * 100) / 100).toFixed(2).replace('.', ',');
        }
        function setMoney(id, number) {
            setting = true;
            document.getElementById(id).value = format(number);
            setting = false;
        }
        function rows() {
            return Array.prototype.slice.call(tbody.querySelectorAll('tr'));
        }
        function selectedRows() {
            return rows().filter(function (row) {
                return row.querySelector('.picked').checked && row.querySelector('.name').value.trim() !== '';
            });
        }
        function syncValue(row) {
            var qty = money(row.querySelector('.qty').value);
            var price = money(row.querySelector('.price').value);
            if (qty > 0 && price > 0) {
                row.querySelector('.value').value = format(Math.round(qty * price * 100) / 100);
            }
        }
        function lineVat(gross, rate) {
            if (rate <= 0) return 0;
            return Math.round(gross * rate / (100 + rate) * 100) / 100;
        }
        function recalc() {
            var allRows = rows().filter(function (row) {
                return row.querySelector('.name').value.trim() !== '';
            });
            var picked = selectedRows();
            var lineGross = 0;
            var lineVatSum = 0;
            var groups = {};
            picked.forEach(function (row) {
                var value = money(row.querySelector('.value').value);
                var rate = money(row.querySelector('.vat').value);
                var vat = lineVat(value, rate);
                lineGross += value;
                lineVatSum += vat;
                var key = String(rate);
                if (!groups[key]) groups[key] = {rate: rate, gross: 0, vat: 0};
                groups[key].gross += value;
                groups[key].vat += vat;
            });
            lineGross = Math.round(lineGross * 100) / 100;
            lineVatSum = Math.round(lineVatSum * 100) / 100;
            var original = parseInt(form.querySelector('[name=original_count]').value, 10) || 0;
            var useReceipt = picked.length > 0
                && picked.length === allRows.length
                && allRows.length === original
                && receiptGross > 0
                && Math.abs(lineGross - receiptGross) < 0.05;

            var gross = useReceipt ? receiptGross : lineGross;
            var vat = useReceipt ? receiptVat : lineVatSum;
            var net = Math.round((gross - vat) * 100) / 100;
            if (!manual) {
                setMoney('gross', gross);
                setMoney('vat', vat);
                setMoney('net', net);
            }
            var note = document.getElementById('total-note');
            if (useReceipt) {
                note.textContent = 'Every product is ticked, so the total is the full amount from the receipt.';
            } else if (picked.length) {
                note.textContent = 'The total is only the ticked lines.';
            } else {
                note.textContent = 'Tick the products that were returned or cancelled.';
            }
            var preview = [];
            if (useReceipt) {
                var rate = picked.length ? money(picked[0].querySelector('.vat').value) : 23;
                preview.push(format(rate).replace(',00', '') + '% — VAT ' + format(receiptVat) + ' — gross ' + format(receiptGross));
            } else {
                Object.keys(groups).forEach(function (key) {
                    var group = groups[key];
                    preview.push(format(group.rate).replace(',00', '') + '% — VAT ' + format(group.vat) + ' — gross ' + format(group.gross));
                });
            }
            document.getElementById('vat-preview').textContent = preview.length ? ('VAT on the PDF: ' + preview.join('; ')) : '';
            var boxes = rows().map(function (row) { return row.querySelector('.picked'); });
            var selectAll = document.getElementById('select-all');
            selectAll.checked = boxes.length > 0 && boxes.every(function (box) { return box.checked; });
        }
        function bindRow(row) {
            row.querySelector('.picked').addEventListener('change', recalc);
            row.querySelector('.qty').addEventListener('input', function () { syncValue(row); recalc(); });
            row.querySelector('.price').addEventListener('input', function () { syncValue(row); recalc(); });
            row.querySelector('.vat').addEventListener('input', recalc);
            row.querySelector('.value').addEventListener('input', recalc);
            row.querySelector('.name').addEventListener('input', recalc);
            row.querySelector('.remove').addEventListener('click', function () {
                if (rows().length === 1) {
                    row.querySelector('.name').value = '';
                    row.querySelector('.value').value = '';
                    row.querySelector('.price').value = '';
                    recalc();
                    return;
                }
                row.remove();
                recalc();
            });
        }
        rows().forEach(bindRow);
        document.getElementById('select-all').addEventListener('change', function (event) {
            rows().forEach(function (row) { row.querySelector('.picked').checked = event.target.checked; });
            recalc();
        });
        ['gross', 'vat', 'net'].forEach(function (id) {
            document.getElementById(id).addEventListener('input', function () {
                if (!setting) manual = true;
            });
        });
        document.getElementById('recalculate').addEventListener('click', function () {
            manual = false;
            recalc();
        });
        document.getElementById('add-row').addEventListener('click', function () {
            var html = document.getElementById('row-template').innerHTML.replace(/__INDEX__/g, String(Date.now()));
            tbody.insertAdjacentHTML('beforeend', html);
            var row = tbody.lastElementChild;
            row.querySelector('.picked').checked = true;
            bindRow(row);
            recalc();
        });
        recalc();
    }());
    </script>
    <?php
}

function blank_item(): array
{
    return [
        'selected' => true,
        'name' => '',
        'unit' => '',
        'qty' => 1,
        'vat_rate' => 23,
        'unit_price' => 0,
        'value' => 0,
    ];
}

function render_item_row($index, array $item): void
{
    $units = ['' => 'Choose', 'Para' => 'Para', 'Sztuka' => 'Sztuka'];
    $qty = qty_pl((float) $item['qty']);
    $price = (float) $item['unit_price'] > 0 ? money_pl((float) $item['unit_price']) : '';
    $value = (float) $item['value'] > 0 ? money_pl((float) $item['value']) : '';
    $rate = rtrim(rtrim(number_format((float) $item['vat_rate'], 2, ',', ''), '0'), ',');
    ?>
    <tr>
        <td><input class="check picked" type="checkbox" name="items[<?= e($index) ?>][selected]" value="1" <?= !empty($item['selected']) ? 'checked' : '' ?>></td>
        <td><input class="name" type="text" name="items[<?= e($index) ?>][name]" value="<?= e($item['name']) ?>"></td>
        <td>
            <select name="items[<?= e($index) ?>][unit]">
                <?php foreach ($units as $valueKey => $label): ?>
                    <option value="<?= e($valueKey) ?>" <?= ($item['unit'] ?? '') === $valueKey ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </td>
        <td><input class="qty" type="text" name="items[<?= e($index) ?>][qty]" value="<?= e($qty) ?>"></td>
        <td><input class="vat" type="text" name="items[<?= e($index) ?>][vat_rate]" value="<?= e($rate) ?>"></td>
        <td><input class="price" type="text" name="items[<?= e($index) ?>][unit_price]" value="<?= e($price) ?>"></td>
        <td><input class="value" type="text" name="items[<?= e($index) ?>][value]" value="<?= e($value) ?>"></td>
        <td><button type="button" class="ghost remove">Remove</button></td>
    </tr>
    <?php
}
