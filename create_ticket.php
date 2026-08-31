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
    $bu         = trim($_POST['bu']         ?? '');
    $line       = trim($_POST['line']       ?? '');
    $notes      = trim($_POST['notes']      ?? '');
    $created_by = trim($_POST['created_by'] ?? '');

    // Line items arrive as parallel arrays (one entry per row).
    $pn_arr   = (array) ($_POST['part_number'] ?? []);
    $desc_arr = (array) ($_POST['item_desc']   ?? []);
    $qty_arr  = (array) ($_POST['qty']         ?? []);
    $uc_arr   = (array) ($_POST['unit_cost']   ?? []);

    // Header validation
    if ($bu === '')         $errors[] = 'Business Unit (BU) is required.';
    if ($line === '')       $errors[] = 'Line is required.';
    if ($created_by === '') $errors[] = 'Created By (name) is required.';

    // Build & validate items, skipping fully-empty rows.
    $items      = [];
    $items_form = [];
    $rowCount   = max(count($pn_arr), count($qty_arr), count($uc_arr), count($desc_arr));
    for ($i = 0; $i < $rowCount; $i++) {
        $pn   = trim((string) ($pn_arr[$i]   ?? ''));
        $desc = trim((string) ($desc_arr[$i] ?? ''));
        $qraw = trim((string) ($qty_arr[$i]  ?? ''));
        $craw = trim((string) ($uc_arr[$i]   ?? ''));

        if ($pn === '' && $desc === '' && $qraw === '' && $craw === '') {
            continue; // ignore blank rows
        }
        $items_form[] = ['part_number' => $pn, 'item_desc' => $desc, 'qty_raw' => $qraw, 'unit_cost_raw' => $craw];

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
                'qty'         => $q,
                'unit_cost'   => $c,
                'amount'      => round($q * $c, 2),
            ];
        }
    }
    if (empty($items)) $errors[] = 'Add at least one valid line item.';

    if (empty($errors)) {
        $total = 0.0;
        foreach ($items as $it) $total += $it['amount'];
        $total = round($total, 2);

        $pdo = get_db();
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
                INSERT INTO ticket_items (ticket_id, part_number, description, qty, unit_cost, amount)
                VALUES (:tid, :pn, :desc, :qty, :uc, :amount)
            ");
            foreach ($items as $it) {
                $ins_item->execute([
                    ':tid'    => $ticket_id,
                    ':pn'     => $it['part_number'],
                    ':desc'   => $it['description'] !== '' ? $it['description'] : null,
                    ':qty'    => $it['qty'],
                    ':uc'     => $it['unit_cost'],
                    ':amount' => $it['amount'],
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
        $items_form[] = ['part_number' => '', 'item_desc' => '', 'qty_raw' => '', 'unit_cost_raw' => ''];
    }
} else {
    $form = ['bu' => '', 'line' => '', 'notes' => '', 'created_by' => ''];
    $items_form = [['part_number' => '', 'item_desc' => '', 'qty_raw' => '', 'unit_cost_raw' => '']];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>New Scrap Ticket — <?= htmlspecialchars(APP_NAME) ?></title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>

<?php
$active_page   = 'create';
$page_subtitle = 'New Scrap Ticket';
require __DIR__ . '/partials/header.php';
?>

<div class="container" style="max-width:760px;">
    <h1 class="page-title">New Scrap Ticket</h1>

    <?php if (!empty($errors)): ?>
        <div class="alert alert-danger">
            <strong>Please fix the following errors:</strong>
            <ul style="margin:6px 0 0 18px;">
                <?php foreach ($errors as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <div class="card">
        <div class="card-header"><h2>Ticket Information</h2></div>
        <div class="card-body">
            <form method="post" action="create_ticket.php" novalidate>

                <!-- Row 1: BU + Line -->
                <div class="form-row">
                    <div class="form-group">
                        <label for="bu">Business Unit (BU) <span style="color:var(--danger)">*</span></label>
                        <input type="text" id="bu" name="bu" class="form-control"
                               maxlength="100" required
                               value="<?= htmlspecialchars($form['bu']) ?>"
                               placeholder="e.g. Electronics, Plastics…">
                    </div>
                    <div class="form-group">
                        <label for="line">Line <span style="color:var(--danger)">*</span></label>
                        <input type="text" id="line" name="line" class="form-control"
                               maxlength="100" required
                               value="<?= htmlspecialchars($form['line']) ?>"
                               placeholder="e.g. Line A, Line 3…">
                    </div>
                </div>

                <!-- Line items (unlimited part numbers) -->
                <div class="form-group">
                    <label>Part Numbers / Line Items <span style="color:var(--danger)">*</span></label>
                    <div class="excel-paste">
                        <label for="excel_box" style="font-size:12px;color:var(--muted);">
                            📋 Paste from Excel (columns: <strong>Part Number · Description · Qty · Unit Cost</strong>) then click “Load rows”.
                        </label>
                        <textarea id="excel_box" class="form-control" rows="3"
                                  placeholder="ABC-123&#9;Broken housing&#9;10&#9;2.50&#10;XYZ-999&#9;Scrapped board&#9;5&#9;12.00"></textarea>
                        <div style="display:flex;gap:8px;margin-top:6px;">
                            <button type="button" id="load_rows" class="btn btn-secondary btn-sm">⬇ Load rows</button>
                            <button type="button" id="clear_rows" class="btn btn-secondary btn-sm">Clear all</button>
                        </div>
                    </div>

                    <div class="table-wrapper" style="margin-top:12px;">
                        <table id="items_table">
                            <thead>
                                <tr>
                                    <th style="width:22%;">Part Number *</th>
                                    <th>Description</th>
                                    <th style="width:110px;">Qty *</th>
                                    <th style="width:130px;">Unit Cost *</th>
                                    <th style="width:120px;text-align:right;">Amount</th>
                                    <th style="width:44px;"></th>
                                </tr>
                            </thead>
                            <tbody id="items_body">
                                <?php foreach ($items_form as $it): ?>
                                <tr class="item-row">
                                    <td><input type="text" name="part_number[]" class="form-control item-pn" maxlength="100"
                                               value="<?= htmlspecialchars($it['part_number']) ?>" placeholder="ABC-12345"></td>
                                    <td><input type="text" name="item_desc[]" class="form-control" maxlength="1000"
                                               value="<?= htmlspecialchars($it['item_desc']) ?>" placeholder="Reason / defect…"></td>
                                    <td><input type="number" name="qty[]" class="form-control item-qty" min="0.01" step="any"
                                               value="<?= htmlspecialchars($it['qty_raw']) ?>" placeholder="0"></td>
                                    <td><input type="number" name="unit_cost[]" class="form-control item-uc" min="0" step="any"
                                               value="<?= htmlspecialchars($it['unit_cost_raw']) ?>" placeholder="0.00"></td>
                                    <td class="item-amount" style="text-align:right;font-weight:600;">$0.00</td>
                                    <td><button type="button" class="btn btn-danger btn-sm remove-row" title="Remove">✖</button></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot>
                                <tr>
                                    <td colspan="4" style="text-align:right;font-weight:600;">Total (USD)</td>
                                    <td id="grand_total" style="text-align:right;font-weight:700;color:var(--primary);">$0.00</td>
                                    <td></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                    <button type="button" id="add_row" class="btn btn-secondary btn-sm" style="margin-top:8px;">+ Add row</button>
                </div>

                <!-- General notes -->
                <div class="form-group">
                    <label for="notes">General Notes / Reason (optional)</label>
                    <textarea id="notes" name="notes" class="form-control"
                              rows="2" maxlength="1000"
                              placeholder="Optional notes that apply to the whole ticket…"><?= htmlspecialchars($form['notes']) ?></textarea>
                </div>

                <hr style="margin:16px 0;border-color:var(--border);">

                <!-- Created by -->
                <div class="form-group">
                    <label for="created_by">Submitted By (Your Name) <span style="color:var(--danger)">*</span></label>
                    <input type="text" id="created_by" name="created_by" class="form-control"
                           maxlength="100" required
                           value="<?= htmlspecialchars($form['created_by']) ?>"
                           placeholder="Full name">
                </div>

                <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:8px;">
                    <a href="index.php" class="btn btn-secondary">Cancel</a>
                    <button type="submit" class="btn btn-primary">Create Ticket</button>
                </div>

            </form>
        </div>
    </div>

    <!-- DOA Info card -->
    <div class="card">
        <div class="card-header"><h3>📋 Approval Levels (DOA)</h3></div>
        <div class="card-body" style="padding:0;">
            <div class="table-wrapper">
                <?php
                $pdo_info = get_db();
                $doa_all  = $pdo_info->query('SELECT * FROM doa_levels ORDER BY level_order')->fetchAll();
                ?>
                <table>
                    <thead>
                        <tr>
                            <th>Level</th>
                            <th>Approver Role</th>
                            <th>Amount Range (USD)</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($doa_all as $d): ?>
                        <tr>
                            <td><?= htmlspecialchars($d['level_name']) ?></td>
                            <td><?= htmlspecialchars($d['approver_role']) ?></td>
                            <td>
                                $<?= number_format((float)$d['min_amount'], 2) ?>
                                — <?= $d['max_amount'] !== null ? '$' . number_format((float)$d['max_amount'], 2) : 'No limit' ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <p style="padding:10px 16px;font-size:12px;color:var(--muted);">
                All levels whose <em>minimum amount</em> ≤ the ticket amount will be required to approve.
            </p>
        </div>
    </div>

</div>

<script>
(function () {
    const body       = document.getElementById('items_body');
    const grandTotal = document.getElementById('grand_total');
    const money = n => '$' + (Number(n) || 0).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
    const num   = v => parseFloat(String(v).replace(/[$,\s]/g, '')) || 0;
    const looksNumeric = v => v !== '' && !isNaN(num(v));

    function recalc() {
        let total = 0;
        body.querySelectorAll('.item-row').forEach(row => {
            const amt = num(row.querySelector('.item-qty').value) * num(row.querySelector('.item-uc').value);
            row.querySelector('.item-amount').textContent = money(amt);
            total += amt;
        });
        grandTotal.textContent = money(total);
    }

    function makeRow(pn = '', desc = '', qty = '', uc = '') {
        const tr = document.createElement('tr');
        tr.className = 'item-row';
        tr.innerHTML =
            '<td><input type="text" name="part_number[]" class="form-control item-pn" maxlength="100" placeholder="ABC-12345"></td>' +
            '<td><input type="text" name="item_desc[]" class="form-control" maxlength="1000" placeholder="Reason / defect…"></td>' +
            '<td><input type="number" name="qty[]" class="form-control item-qty" min="0.01" step="any" placeholder="0"></td>' +
            '<td><input type="number" name="unit_cost[]" class="form-control item-uc" min="0" step="any" placeholder="0.00"></td>' +
            '<td class="item-amount" style="text-align:right;font-weight:600;">$0.00</td>' +
            '<td><button type="button" class="btn btn-danger btn-sm remove-row" title="Remove">✖</button></td>';
        tr.querySelector('.item-pn').value = pn;
        tr.querySelector('[name="item_desc[]"]').value = desc;
        tr.querySelector('.item-qty').value = qty;
        tr.querySelector('.item-uc').value  = uc;
        body.appendChild(tr);
        return tr;
    }

    function rowIsEmpty(row) {
        return [...row.querySelectorAll('input')].every(i => i.value.trim() === '');
    }

    // Parse clipboard/Excel text into [pn, desc, qty, uc] tuples.
    function parseClipboard(text) {
        const out = [];
        text.replace(/\r/g, '').split('\n').forEach(line => {
            if (line.trim() === '') return;
            let cols = (line.indexOf('\t') !== -1 ? line.split('\t') : line.split(/ {2,}|,/)).map(c => c.trim());
            let pn = '', desc = '', qty = '', uc = '';
            if (cols.length >= 4) {
                [pn, desc, qty, uc] = cols;
            } else if (cols.length === 3) {
                if (looksNumeric(cols[1]) && looksNumeric(cols[2])) { pn = cols[0]; qty = cols[1]; uc = cols[2]; }
                else { pn = cols[0]; desc = cols[1]; qty = cols[2]; }
            } else if (cols.length === 2) {
                pn = cols[0];
                looksNumeric(cols[1]) ? (qty = cols[1]) : (desc = cols[1]);
            } else {
                pn = cols[0];
            }
            out.push([pn, desc, looksNumeric(qty) ? num(qty) : qty, looksNumeric(uc) ? num(uc) : uc]);
        });
        return out;
    }

    function loadRows(rows) {
        if (!rows.length) return;
        // Drop leading empty rows so pasted data replaces the blank starter row.
        [...body.querySelectorAll('.item-row')].forEach(r => { if (rowIsEmpty(r)) r.remove(); });
        rows.forEach(r => makeRow(r[0], r[1], r[2] === '' ? '' : r[2], r[3] === '' ? '' : r[3]));
        if (!body.querySelector('.item-row')) makeRow();
        recalc();
    }

    // Events
    document.getElementById('add_row').addEventListener('click', () => { makeRow(); });
    document.getElementById('load_rows').addEventListener('click', () => {
        const box = document.getElementById('excel_box');
        loadRows(parseClipboard(box.value));
        box.value = '';
    });
    document.getElementById('clear_rows').addEventListener('click', () => {
        body.innerHTML = '';
        makeRow();
        recalc();
    });

    body.addEventListener('input', recalc);
    body.addEventListener('click', e => {
        if (e.target.classList.contains('remove-row')) {
            e.target.closest('.item-row').remove();
            if (!body.querySelector('.item-row')) makeRow();
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

    recalc();
})();
</script>
</body>
</html>
