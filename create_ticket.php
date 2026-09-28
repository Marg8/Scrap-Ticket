<?php
/**
 * create_ticket.php — Create a new scrap ticket.
 * GET  → display blank form
 * POST → validate, save to DB, create pending approval rows, redirect to view page
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';

$errors  = [];
$success = false;

$pdo        = get_db_or_null();
$db_offline = $pdo === null;

// Helper: generate a unique ticket number  ST-YYYYMMDD-XXXX
function generate_ticket_number(PDO $pdo): string {
    do {
        $num = 'ST-' . date('Ymd') . '-' . str_pad(random_int(1, 9999), 4, '0', STR_PAD_LEFT);
        $exists = $pdo->prepare('SELECT COUNT(*) FROM scrap_tickets WHERE ticket_number = ?');
        $exists->execute([$num]);
    } while ((int) $exists->fetchColumn() > 0);
    return $num;
}

// Helper: determine which DOA levels are required for a given amount
function get_required_doa_levels(PDO $pdo, float $amount): array {
    $stmt = $pdo->prepare("
        SELECT * FROM doa_levels
        WHERE min_amount <= :amount
        ORDER BY level_order ASC
    ");
    $stmt->execute([':amount' => $amount]);
    return $stmt->fetchAll();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($db_offline) {
        $errors[] = 'Sin conexión a la base de datos. No se procesará este ticket.';
    }

    $bu         = trim($_POST['bu']         ?? '');
    $line       = trim($_POST['line']       ?? '');
    $notes      = trim($_POST['notes']      ?? '');
    $created_by = trim($_POST['created_by'] ?? '');

    // Line items arrive as parallel arrays (one entry per row).
    $pn_arr    = (array) ($_POST['part_number'] ?? []);
    $desc_arr  = (array) ($_POST['item_desc']   ?? []);
    $um_arr    = (array) ($_POST['um']          ?? []);
    $qty_arr   = (array) ($_POST['qty']         ?? []);
    $uc_arr    = (array) ($_POST['unit_cost']   ?? []);
    $scrap_arr = (array) ($_POST['scrap_code']  ?? []);

    // Header validation
    if ($bu === '')         $errors[] = 'Business Unit (BU) is required.';
    if ($line === '')       $errors[] = 'Line is required.';
    if ($created_by === '') $errors[] = 'Created By (name) is required.';

    // Build & validate items, skipping fully-empty rows.
    $items      = [];
    $items_form = [];
    $rowCount   = max(count($pn_arr), count($qty_arr), count($uc_arr), count($desc_arr));
    for ($i = 0; $i < $rowCount; $i++) {
        $pn    = trim((string) ($pn_arr[$i]    ?? ''));
        $desc  = trim((string) ($desc_arr[$i]  ?? ''));
        $um    = trim((string) ($um_arr[$i]    ?? ''));
        $qraw  = trim((string) ($qty_arr[$i]   ?? ''));
        $craw  = trim((string) ($uc_arr[$i]    ?? ''));
        $scrap = trim((string) ($scrap_arr[$i] ?? ''));

        if ($pn === '' && $desc === '' && $qraw === '' && $craw === '') {
            continue; // ignore blank rows
        }
        $items_form[] = ['part_number' => $pn, 'item_desc' => $desc, 'um' => $um, 'qty_raw' => $qraw, 'unit_cost_raw' => $craw, 'scrap_code' => $scrap];

        $rowNo = count($items_form);
        if ($pn === '') $errors[] = "Item $rowNo: Part Number is required.";

        $q = filter_var($qraw, FILTER_VALIDATE_FLOAT);
        if ($q === false || $q <= 0) $errors[] = "Item $rowNo: Qty must be a positive number.";

        $c = filter_var($craw, FILTER_VALIDATE_FLOAT);
        if ($c === false || $c < 0) $errors[] = "Item $rowNo: Unit Cost must be a non-negative number.";

        if ($pn !== '' && $q !== false && $q > 0 && $c !== false && $c >= 0) {
            $items[] = [
                'part_number' => substr($pn, 0, 100),
                'description' => substr($desc, 0, 1000),
                'um'          => substr($um, 0, 20),
                'qty'         => $q,
                'unit_cost'   => $c,
                'amount'      => round($q * $c, 2),
                'scrap_code'  => substr($scrap, 0, 100),
            ];
        }
    }
    if (empty($items)) $errors[] = 'Add at least one valid line item.';

    if (empty($errors)) {
        $total = 0.0;
        foreach ($items as $it) $total += $it['amount'];
        $total = round($total, 2);

        $pdo->beginTransaction();
        try {
            $ticket_number = generate_ticket_number($pdo);

            // Insert ticket header (per-line fields now live in ticket_items).
            $stmt = $pdo->prepare("
                INSERT INTO scrap_tickets
                    (ticket_number, bu, line, part_number, description, qty, unit_cost, amount, created_by)
                VALUES
                    (:tn, :bu, :line, NULL, :desc, NULL, NULL, :amount, :cb)
            ");
            $stmt->execute([
                ':tn'     => $ticket_number,
                ':bu'     => $bu,
                ':line'   => $line,
                ':desc'   => $notes !== '' ? $notes : null,
                ':amount' => $total,
                ':cb'     => $created_by,
            ]);
            $ticket_id = (int) $pdo->lastInsertId();

            // Insert line items.
            $ins_item = $pdo->prepare("
                INSERT INTO ticket_items (ticket_id, part_number, description, um, qty, unit_cost, amount, scrap_code)
                VALUES (:tid, :pn, :desc, :um, :qty, :uc, :amount, :scrap)
            ");
            foreach ($items as $it) {
                $ins_item->execute([
                    ':tid'    => $ticket_id,
                    ':pn'     => $it['part_number'],
                    ':desc'   => $it['description'] !== '' ? $it['description'] : null,
                    ':um'     => $it['um'] !== '' ? $it['um'] : null,
                    ':qty'    => $it['qty'],
                    ':uc'     => $it['unit_cost'],
                    ':amount' => $it['amount'],
                    ':scrap'  => $it['scrap_code'] !== '' ? $it['scrap_code'] : null,
                ]);
            }

            // Create pending approval rows based on the ticket total.
            $doa_levels = get_required_doa_levels($pdo, $total);
            $ins_approval = $pdo->prepare("
                INSERT INTO approvals (ticket_id, doa_level_id, approver_role)
                VALUES (:tid, :dlid, :role)
            ");
            foreach ($doa_levels as $level) {
                $ins_approval->execute([
                    ':tid'  => $ticket_id,
                    ':dlid' => $level['id'],
                    ':role' => $level['approver_role'],
                ]);
            }

            $pdo->commit();
            header('Location: view_ticket.php?id=' . $ticket_id . '&created=1');
            exit;
        } catch (Exception $e) {
            $pdo->rollBack();
            error_log('create_ticket error: ' . $e->getMessage());
            $errors[] = 'Failed to save ticket. Please try again.';
        }
    }

    // Re-populate form values on error.
    $form = compact('bu', 'line', 'notes', 'created_by');
    if (empty($items_form)) {
        $items_form[] = ['part_number' => '', 'item_desc' => '', 'um' => '', 'qty_raw' => '', 'unit_cost_raw' => '', 'scrap_code' => ''];
    }
} else {
    $form = ['bu' => '', 'line' => '', 'notes' => '', 'created_by' => ''];
    $items_form = [];
    for ($i = 0; $i < 8; $i++) {
        $items_form[] = ['part_number' => '', 'item_desc' => '', 'um' => '', 'qty_raw' => '', 'unit_cost_raw' => '', 'scrap_code' => ''];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>New Scrap Ticket — <?= htmlspecialchars(APP_NAME) ?></title>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        /* ---- Scrap sheet (Boleta de Desperdicio) layout ---- */
        .scrap-sheet {
            --sheet-line: #2f6b3a;
            --sheet-head: #dcecdc;
            --sheet-alt:  #eef6ef;
            --sheet-brand:#1f7a34;
            width: 100%;
            max-width: 1180px;
            margin: 18px auto;
            background: #fff;
            border: 1px solid var(--sheet-line);
            color: #111;
            font-size: 11px;
            line-height: 1.2;
        }
        .scrap-sheet .sheet-title {
            text-align: center;
            font-weight: 700;
            font-size: 13px;
            letter-spacing: .3px;
            padding: 5px;
            background: var(--sheet-head);
            border-bottom: 1px solid var(--sheet-line);
            text-transform: uppercase;
        }
        .scrap-sheet table { width: 100%; border-collapse: collapse; table-layout: fixed; }
        .scrap-sheet th,
        .scrap-sheet td {
            border: 1px solid var(--sheet-line);
            padding: 2px 5px;
            vertical-align: middle;
            font-weight: 400;
            text-align: center;
            white-space: normal;
            background: #fff;
        }
        .scrap-sheet .meta td { height: 30px; }
        .scrap-sheet .logo { text-align: left; padding: 6px 8px; }
        .scrap-sheet .logo img { height: 26px; display: block; margin-bottom: 3px; }
        .scrap-sheet .logo .brand { display:block; font-size: 18px; font-weight: 800; color: var(--sheet-brand); letter-spacing:.2px; }
        .scrap-sheet .logo .company { display:block; margin-top:2px; font-size: 10px; font-weight: 600; }
        .scrap-sheet .meta-label { font-weight: 700; margin-right: 4px; }
        .scrap-sheet .main th {
            font-weight: 700;
            height: 30px;
            background: var(--sheet-head);
        }
        .scrap-sheet .main td { height: 24px; padding: 0; }
        .scrap-sheet .rownum { width: 34px; }
        .scrap-sheet .cell-input {
            width: 100%;
            border: 0;
            background: transparent;
            padding: 3px 5px;
            font: inherit;
            color: inherit;
            text-align: inherit;
            outline: none;
        }
        .scrap-sheet .cell-input:focus { background: #cfe6d1; }
        .scrap-sheet td.num-cell { text-align: right; }
        .scrap-sheet td.num-cell .cell-input { text-align: right; }
        .scrap-sheet .item-amount { text-align: right; padding: 3px 5px; font-weight: 600; }
        .scrap-sheet .row-del {
            border: 0; background: transparent; color: #b00020;
            cursor: pointer; font-size: 13px; line-height: 1; padding: 0;
        }
        .scrap-sheet tfoot td { font-weight: 700; height: 28px; background: var(--sheet-head); }
        .scrap-sheet .totals-label { text-align: right; }
        .scrap-sheet .approvals th { font-weight: 700; background: var(--sheet-head); }
        .scrap-sheet .approvals .title-cell { text-align: left; font-weight: 700; }
        .scrap-sheet .approvals .limit-cell { text-align: center; }
        .scrap-sheet .sig-cell { height: 46px; }
        .sheet-toolbar {
            width: 100%;
            max-width: 1180px;
            margin: 0 auto 10px;
            display: flex;
            gap: 8px;
            justify-content: flex-end;
            flex-wrap: wrap;
        }
        .sheet-tools {
            width: 100%;
            max-width: 1180px;
            margin: 10px auto 0;
        }
        .cell-input.is-invalid,
        .form-control.is-invalid {
            border: 1px solid #d32f2f !important;
            background: #fff3f3 !important;
            box-shadow: inset 0 0 0 1px rgba(211, 47, 47, 0.15);
            color: #7f1d1d;
        }
        .scrap-cell {
            padding: 0 !important;
            background: #fff;
            vertical-align: top;
        }
        .scrap-sheet .main { table-layout: auto; }
        .scrap-sheet .main th,
        .scrap-sheet .main td { white-space: nowrap; }
        .scrap-group-header {
            background: var(--sheet-head);
            font-weight: 700;
            padding: 0 !important;
        }
        .scrap-group-header .group-inner {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            padding: 4px 6px;
        }
        .scrap-group-header .add-scrap-col {
            border: 1px solid var(--sheet-line);
            background: #edf3ee;
            color: #14532d;
            border-radius: 3px;
            font-size: 11px;
            font-weight: 700;
            padding: 1px 6px;
            cursor: pointer;
            line-height: 1;
        }
        .scrap-code-header {
            position: relative;
            background: #d5e8d7 !important;
            padding: 0 !important;
            min-width: 80px;
        }
        .code-header-wrap {
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
            padding: 0 18px 0 4px;
        }
        .code-header-input {
            width: 100%;
            border: 0;
            background: transparent;
            text-align: center;
            font: inherit;
            font-weight: 700;
            color: #111;
            outline: none;
            padding: 4px 6px;
            box-sizing: border-box;
            font-size: 11px;
        }
        .code-header-input::placeholder {
            color: #466e49;
            opacity: 1;
        }
        .code-header-input:focus { background: #cfe6d1; }
        .remove-scrap-col {
            position: absolute;
            right: 2px;
            top: 50%;
            transform: translateY(-50%);
            border: 0;
            background: transparent;
            color: #b00020;
            font-size: 12px;
            font-weight: 700;
            cursor: pointer;
            line-height: 1;
            padding: 0 2px;
        }
        .code-qty-cell {
            padding: 0 !important;
            min-width: 70px;
        }
        .code-qty-input {
            width: 100%;
            border: 0;
            background: transparent;
            text-align: right;
            font: inherit;
            color: #111;
            outline: none;
            padding: 3px 6px;
            box-sizing: border-box;
        }
        .code-qty-input:focus { background: #cfe6d1; }
        @media (max-width: 1200px) {
            .scrap-sheet { overflow-x: auto; }
        }
    </style>
</head>
<body>

<?php
$active_page   = 'create';
$page_subtitle = 'New Scrap Ticket';
require __DIR__ . '/partials/header.php';
?>

<div class="container" style="max-width:1220px;">
    <h1 class="page-title">Boleta de Desperdicio (Scrap)</h1>

    <?php if (!empty($errors)): ?>
        <div class="alert alert-danger">
            <strong>Please fix the following errors:</strong>
            <ul style="margin:6px 0 0 18px;">
                <?php foreach ($errors as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <form method="post" action="create_ticket.php" novalidate>

        <div class="scrap-sheet">
            <div class="sheet-title">Boleta de Desperdicio (Scrap)</div>

            <!-- Meta header -->
            <table class="meta">
                <colgroup>
                    <col style="width:34%">
                    <col style="width:18%">
                    <col style="width:18%">
                    <col style="width:14%">
                    <col style="width:16%">
                </colgroup>
                <tr>
                    <td class="logo" rowspan="2">
                        <img src="assets/images/Littelfuse.png" alt="Littelfuse">
                        <span class="brand">Littelfuse</span>
                        <span class="company">Productos Electromecánicos BAC, S. de R.L. de C.V.</span>
                    </td>
                    <td>
                        <span class="meta-label">Área:</span>
                        <input type="text" name="bu" class="cell-input" maxlength="100"
                               value="<?= htmlspecialchars($form['bu']) ?>" placeholder="Área / BU">
                    </td>
                    <td>
                        <span class="meta-label">Responsable:</span>
                        <input type="text" name="created_by" class="cell-input" maxlength="100"
                               value="<?= htmlspecialchars($form['created_by']) ?>" placeholder="Responsable">
                    </td>
                    <td>
                        <span class="meta-label">No Parte:</span>
                        <input type="text" name="line" class="cell-input" maxlength="100"
                               value="<?= htmlspecialchars($form['line']) ?>" placeholder="Línea / No. Parte">
                    </td>
                    <td>
                        <span class="meta-label">Fecha:</span>
                        <input type="text" class="cell-input" value="<?= htmlspecialchars(date('d-M-y')) ?>" readonly>
                    </td>
                </tr>
                <tr>
                    <td colspan="3"></td>
                    <td><span class="meta-label">Folio:</span><em>Auto</em></td>
                </tr>
            </table>

            <!-- Main line-items table -->
            <table class="main" id="items_table">
                <colgroup>
                    <col style="width:34px">
                    <col style="width:120px">
                    <col style="width:280px">
                    <col style="width:56px">
                    <col style="width:110px">
                    <col style="width:110px">
                    <col style="width:100px">
                </colgroup>
                <thead>
                    <tr>
                        <th rowspan="2">#</th>
                        <th rowspan="2">Número de parte</th>
                        <th rowspan="2">Descripción</th>
                        <th rowspan="2">U/M</th>
                        <th rowspan="2">Costo unitario</th>
                        <th rowspan="2">Costo total</th>
                        <th rowspan="2">Cantidad total</th>
                        <th class="scrap-group-header" id="scrap_group_header" colspan="1">
                            <div class="group-inner">
                                <span>Código de Scrap</span>
                                <button type="button" id="add_scrap_col" class="add-scrap-col" title="Agregar columna">+</button>
                            </div>
                        </th>
                        <th rowspan="2"></th>
                    </tr>
                    <tr id="scrap_code_headers">
                        <th class="scrap-code-header">
                            <div class="code-header-wrap">
                                <input type="text" class="code-header-input" maxlength="20" value="" placeholder="A10">
                                <button type="button" class="remove-scrap-col" title="Eliminar columna">×</button>
                            </div>
                        </th>
                    </tr>
                </thead>
                <tbody id="items_body">
                    <?php foreach ($items_form as $idx => $it): ?>
                    <tr class="item-row">
                        <td class="rownum"><?= $idx + 1 ?></td>
                        <td><input type="text" name="part_number[]" class="cell-input item-pn" maxlength="100"
                                   value="<?= htmlspecialchars($it['part_number']) ?>" placeholder="ABC-12345"></td>
                        <td style="text-align:left;"><input type="text" name="item_desc[]" class="cell-input" maxlength="1000"
                                   style="text-align:left;" value="<?= htmlspecialchars($it['item_desc']) ?>" placeholder="Descripción / defecto…"></td>
                        <td><input type="text" name="um[]" class="cell-input item-um" maxlength="20"
                                   value="<?= htmlspecialchars($it['um'] ?? '') ?>" placeholder="EA"></td>
                        <td class="num-cell"><input type="number" name="unit_cost[]" class="cell-input item-uc" min="0" step="any"
                                   value="<?= htmlspecialchars($it['unit_cost_raw']) ?>" placeholder="0.00"></td>
                        <td class="item-amount">$0.00</td>
                        <td class="num-cell"><input type="number" name="qty[]" class="cell-input item-qty" min="0" step="any"
                                   value="<?= htmlspecialchars($it['qty_raw']) ?>" placeholder="0"></td>
                        <td class="code-qty-cell">
                            <input type="number" class="code-qty-input" min="0" step="any" value="" placeholder="">
                        </td>
                        <td><button type="button" class="row-del remove-row" title="Quitar">✖</button></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <td colspan="5" class="totals-label">Total</td>
                        <td id="grand_total" class="item-amount">$0.00</td>
                        <td id="grand_qty" class="num-cell" style="padding-right:5px;">0</td>
                        <td id="tfoot_scrap_pad" colspan="1"></td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>

            <!-- Approvals footer -->
            <table class="approvals">
                <colgroup>
                    <col style="width:22%">
                    <col style="width:26%">
                    <col style="width:14%">
                    <col style="width:24%">
                    <col style="width:14%">
                </colgroup>
                <thead>
                    <tr>
                        <th>Aprobador 1</th>
                        <th>Límite de aprobaciones</th>
                        <th>Firma Aprobador 1</th>
                        <th>Aprobador 2</th>
                        <th>Firma Aprobador 2</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td class="title-cell">Gerente de Operaciones</td>
                        <td class="limit-cell">$0 - $2,500 USD</td>
                        <td class="sig-cell"></td>
                        <td class="title-cell">Contralor</td>
                        <td class="sig-cell"></td>
                    </tr>
                    <tr>
                        <td class="title-cell">Gerente de Planta</td>
                        <td class="limit-cell">$2,501 - $15,000 USD</td>
                        <td class="sig-cell"></td>
                        <td class="title-cell">Contralor Regional de Ops.</td>
                        <td class="sig-cell"></td>
                    </tr>
                    <tr>
                        <td class="title-cell">Vicepresidente de unidad de negocio</td>
                        <td class="limit-cell">Aprobar el total mensual del sitio</td>
                        <td class="sig-cell"></td>
                        <td class="title-cell"></td>
                        <td class="sig-cell"></td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Tools: general notes + Excel paste + actions -->
        <div class="sheet-tools">
            <div class="excel-paste" style="margin-bottom:10px;">
                <label for="excel_box" style="font-size:12px;color:var(--muted);">
                    📋 Paste from Excel (columnas en el orden de la boleta: <strong>Número de parte · Descripción · U/M · Costo unitario · Cantidad total</strong>, opcional columnas de <strong>Código de Scrap</strong>) then click “Load rows”.
                </label>
                <textarea id="excel_box" class="form-control" rows="3"
                          placeholder="ABC-123&#9;Broken housing&#9;EA&#9;2.50&#9;10&#9;1&#9;10&#9;20&#10;XYZ-999&#9;Scrapped board&#9;EA&#9;12.00&#9;5"></textarea>
                <div style="display:flex;gap:8px;margin-top:6px;">
                    <button type="button" id="add_row" class="btn btn-secondary btn-sm">+ Add row</button>
                    <button type="button" id="load_rows" class="btn btn-secondary btn-sm">⬇ Load rows</button>
                    <button type="button" id="clear_rows" class="btn btn-secondary btn-sm">Clear all</button>
                </div>
            </div>

            <div class="form-group">
                <label for="notes">General Notes / Reason (optional)</label>
                <textarea id="notes" name="notes" class="form-control"
                          rows="2" maxlength="1000"
                          placeholder="Optional notes that apply to the whole ticket…"><?= htmlspecialchars($form['notes']) ?></textarea>
            </div>

            <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:10px;">
                <a href="index.php" class="btn btn-secondary">Cancel</a>
                <button type="submit" class="btn btn-primary">Create Ticket</button>
            </div>
        </div>

    </form>
</div>

<script>
(function () {
    const body       = document.getElementById('items_body');
    const grandTotal = document.getElementById('grand_total');
    const grandQty   = document.getElementById('grand_qty');
    const scrapGroup = document.getElementById('scrap_group_header');
    const scrapCodeRow = document.getElementById('scrap_code_headers');
    const tfootScrapPad = document.getElementById('tfoot_scrap_pad');
    const money = n => '$' + (Number(n) || 0).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
    const qtyFmt = n => (Number(n) || 0).toLocaleString('en-US', {minimumFractionDigits: 0, maximumFractionDigits: 3});
    const num   = v => parseFloat(String(v).replace(/[$,\s]/g, '')) || 0;
    const looksNumeric = v => v !== '' && !isNaN(num(v));

    function getScrapColCount() {
        return scrapCodeRow.querySelectorAll('.scrap-code-header').length;
    }

    function addScrapColumnGlobal() {
        const th = document.createElement('th');
        th.className = 'scrap-code-header';
        th.innerHTML = '<div class="code-header-wrap"><input type="text" class="code-header-input" maxlength="20" value="" placeholder="A' + (getScrapColCount() + 10) + '"><button type="button" class="remove-scrap-col" title="Eliminar columna">×</button></div>';
        scrapCodeRow.appendChild(th);

        body.querySelectorAll('.item-row').forEach(row => {
            const td = document.createElement('td');
            td.className = 'code-qty-cell';
            td.innerHTML = '<input type="number" class="code-qty-input" min="0" step="any" value="" placeholder="">';
            const delCell = row.lastElementChild;
            row.insertBefore(td, delCell);
        });

        const count = getScrapColCount();
        scrapGroup.setAttribute('colspan', count);
        tfootScrapPad.setAttribute('colspan', count);
    }

    function removeScrapColumnGlobal(button) {
        const th = button.closest('.scrap-code-header');
        if (!th) return;
        if (getScrapColCount() <= 1) return;

        const idx = Array.from(scrapCodeRow.querySelectorAll('.scrap-code-header')).indexOf(th);
        if (idx === -1) return;

        body.querySelectorAll('.item-row').forEach(row => {
            const cells = row.querySelectorAll('.code-qty-cell');
            if (cells[idx]) cells[idx].remove();
        });

        th.remove();

        const count = getScrapColCount();
        scrapGroup.setAttribute('colspan', count);
        tfootScrapPad.setAttribute('colspan', count);
        recalc();
    }

    function sumScrapQty(row) {
        let total = 0;
        row.querySelectorAll('.code-qty-input').forEach(input => {
            total += num(input.value);
        });
        return total;
    }

    function markInvalid(field, invalid) {
        if (!field) return;
        field.classList.toggle('is-invalid', !!invalid);
    }

    function validateRequiredFields(showAlert = false) {
        const headerFields = [
            document.querySelector('input[name="bu"]'),
            document.querySelector('input[name="line"]'),
            document.querySelector('input[name="created_by"]')
        ];

        headerFields.forEach(field => {
            markInvalid(field, !!field && field.value.trim() === '');
        });

        let hasInvalid = headerFields.some(field => !!field && field.value.trim() === '');

        body.querySelectorAll('.item-row').forEach(row => {
            const pn = row.querySelector('.item-pn');
            const qty = row.querySelector('.item-qty');
            const uc = row.querySelector('.item-uc');
            const rowHasData = [pn, qty, uc].some(input => input && input.value.trim() !== '');

            if (!rowHasData) {
                markInvalid(pn, false);
                markInvalid(qty, false);
                markInvalid(uc, false);
                return;
            }

            const rowInvalid = (pn && pn.value.trim() === '') || (qty && qty.value.trim() === '') || (uc && uc.value.trim() === '');
            markInvalid(pn, !!pn && pn.value.trim() === '');
            markInvalid(qty, !!qty && qty.value.trim() === '');
            markInvalid(uc, !!uc && uc.value.trim() === '');

            if (rowInvalid) hasInvalid = true;
        });

        if (showAlert && hasInvalid) {
            const firstInvalid = document.querySelector('.is-invalid');
            if (firstInvalid) firstInvalid.focus();
        }

        return !hasInvalid;
    }

    function renumber() {
        body.querySelectorAll('.item-row').forEach((row, i) => {
            row.querySelector('.rownum').textContent = i + 1;
        });
    }

    function recalc() {
        let total = 0, totalQty = 0;
        body.querySelectorAll('.item-row').forEach(row => {
            const qty = num(row.querySelector('.item-qty').value);
            const scrapTotal = sumScrapQty(row);
            const amt = (qty || scrapTotal) * num(row.querySelector('.item-uc').value);
            row.querySelector('.item-amount').textContent = money(amt);
            total += amt;
            totalQty += qty || scrapTotal;
        });
        grandTotal.textContent = money(total);
        grandQty.textContent   = qtyFmt(totalQty);
        renumber();
    }

    function makeRow(pn = '', desc = '', qty = '', uc = '', um = '', scraps = []) {
        const tr = document.createElement('tr');
        tr.className = 'item-row';
        const codeCols = getScrapColCount();
        let codeCells = '';
        for (let i = 0; i < codeCols; i++) {
            codeCells += '<td class="code-qty-cell"><input type="number" class="code-qty-input" min="0" step="any" value="" placeholder=""></td>';
        }
        tr.innerHTML =
            '<td class="rownum"></td>' +
            '<td><input type="text" name="part_number[]" class="cell-input item-pn" maxlength="100" placeholder="ABC-12345"></td>' +
            '<td style="text-align:left;"><input type="text" name="item_desc[]" class="cell-input" maxlength="1000" style="text-align:left;" placeholder="Descripción / defecto…"></td>' +
            '<td><input type="text" name="um[]" class="cell-input item-um" maxlength="20" placeholder="EA"></td>' +
            '<td class="num-cell"><input type="number" name="unit_cost[]" class="cell-input item-uc" min="0" step="any" placeholder="0.00"></td>' +
            '<td class="item-amount">$0.00</td>' +
            '<td class="num-cell"><input type="number" name="qty[]" class="cell-input item-qty" min="0" step="any" placeholder="0"></td>' +
            codeCells +
            '<td><button type="button" class="row-del remove-row" title="Quitar">✖</button></td>';
        tr.querySelector('.item-pn').value = pn;
        tr.querySelector('[name="item_desc[]"]').value = desc;
        tr.querySelector('.item-um').value  = um;
        tr.querySelector('.item-qty').value = qty;
        tr.querySelector('.item-uc').value  = uc;
        body.appendChild(tr);
        const codeInputs = tr.querySelectorAll('.code-qty-input');
        (scraps || []).forEach((val, i) => { if (codeInputs[i] && val !== '' && val != null) codeInputs[i].value = val; });
        return tr;
    }

    function rowIsEmpty(row) {
        return [...row.querySelectorAll('input')].every(i => i.value.trim() === '');
    }

    // Parse clipboard/Excel text following the sheet (boleta) column order:
    // Part Number · Description · U/M · Unit Cost · Qty · [Código de Scrap qty…].
    // U/M and the Scrap Code quantity columns are optional.
    function parseClipboard(text) {
        const out = [];
        text.replace(/\r/g, '').split('\n').forEach(line => {
            if (line.trim() === '') return;
            let cols = (line.indexOf('\t') !== -1 ? line.split('\t') : line.split(/ {2,}|,/)).map(c => c.trim());
            let pn = '', desc = '', um = '', uc = '', qty = '', scraps = [];
            if (cols.length >= 5) {
                [pn, desc, um, uc, qty] = cols;
                scraps = cols.slice(5);
            } else if (cols.length === 4) {
                [pn, desc, uc, qty] = cols;            // sin U/M
            } else if (cols.length === 3) {
                [pn, desc, qty] = cols;
            } else if (cols.length === 2) {
                pn = cols[0];
                looksNumeric(cols[1]) ? (qty = cols[1]) : (desc = cols[1]);
            } else {
                pn = cols[0];
            }
            out.push({
                pn, desc, um,
                uc:  looksNumeric(uc)  ? num(uc)  : uc,
                qty: looksNumeric(qty) ? num(qty) : qty,
                scraps: scraps.map(s => (looksNumeric(s) ? num(s) : s))
            });
        });
        return out;
    }

    function ensureScrapColumns(n) {
        while (getScrapColCount() < n) addScrapColumnGlobal();
    }

    function loadRows(rows) {
        if (!rows.length) return;
        // Add scrap-code columns first so pasted quantities land in the right cells.
        const maxScraps = rows.reduce((m, r) => Math.max(m, (r.scraps || []).length), 0);
        ensureScrapColumns(maxScraps);
        // Drop leading empty rows so pasted data replaces the blank starter row.
        [...body.querySelectorAll('.item-row')].forEach(r => { if (rowIsEmpty(r)) r.remove(); });
        rows.forEach(r => makeRow(r.pn, r.desc, r.qty, r.uc, r.um, r.scraps));
        if (!body.querySelector('.item-row')) makeRow();
        recalc();
    }

    // Events
    document.getElementById('add_row').addEventListener('click', () => { makeRow(); recalc(); });
    document.getElementById('add_scrap_col').addEventListener('click', () => { addScrapColumnGlobal(); });
    document.getElementById('load_rows').addEventListener('click', () => {
        const box = document.getElementById('excel_box');
        loadRows(parseClipboard(box.value));
        box.value = '';
    });
    document.getElementById('clear_rows').addEventListener('click', () => {
        body.innerHTML = '';
        for (let i = 0; i < 8; i++) makeRow();
        recalc();
    });

    document.querySelector('form').addEventListener('submit', function (event) {
        const valid = validateRequiredFields(true);
        if (!valid) {
            event.preventDefault();
        }
    });

    document.querySelectorAll('input[name="bu"], input[name="line"], input[name="created_by"]').forEach(input => {
        input.addEventListener('input', () => {
            markInvalid(input, input.value.trim() === '');
        });
    });

    body.addEventListener('input', () => {
        validateRequiredFields(false);
        recalc();
    });
    scrapCodeRow.addEventListener('click', e => {
        const removeCodeButton = e.target.closest('.remove-scrap-col');
        if (removeCodeButton) removeScrapColumnGlobal(removeCodeButton);
    });

    body.addEventListener('click', e => {
        if (e.target.classList.contains('remove-row')) {
            e.target.closest('.item-row').remove();
            if (!body.querySelector('.item-row')) makeRow();
            validateRequiredFields(false);
            recalc();
        }
    });

    // Paste multi-cell/multi-row Excel data directly into a Part Number cell.
    body.addEventListener('paste', e => {
        if (!e.target.classList.contains('item-pn')) return;
        const text = (e.clipboardData || window.clipboardData).getData('text');
        if (text.indexOf('\t') === -1 && text.indexOf('\n') === -1) return; // single value → default paste
        e.preventDefault();
        loadRows(parseClipboard(text));
    });

    // Keep the form at 8 blank rows by default on first render.
    if (!body.querySelector('.item-row')) {
        for (let i = 0; i < 8; i++) makeRow();
    }

    recalc();
})();
</script>
</body>
</html>
