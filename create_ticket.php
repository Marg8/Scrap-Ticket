<?php
/**
 * create_ticket.php — Create a new scrap ticket.
 * GET  → display blank form
 * POST → validate, save to DB, create pending approval rows, redirect to view page
 */
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
    $bu          = trim($_POST['bu']          ?? '');
    $line        = trim($_POST['line']        ?? '');
    $part_number = trim($_POST['part_number'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $qty_raw     = trim($_POST['qty']         ?? '');
    $unit_cost_raw = trim($_POST['unit_cost'] ?? '');
    $created_by  = trim($_POST['created_by']  ?? '');

    // Validation
    if ($bu === '')          $errors[] = 'Business Unit (BU) is required.';
    if ($line === '')        $errors[] = 'Line is required.';
    if ($part_number === '') $errors[] = 'Part Number is required.';
    if ($created_by === '')  $errors[] = 'Created By (name) is required.';

    $qty = filter_var($qty_raw, FILTER_VALIDATE_FLOAT);
    if ($qty === false || $qty <= 0) $errors[] = 'Qty must be a positive number.';

    $unit_cost = filter_var($unit_cost_raw, FILTER_VALIDATE_FLOAT);
    if ($unit_cost === false || $unit_cost < 0) $errors[] = 'Unit Cost must be a non-negative number.';

    if (empty($errors)) {
        $amount = round($qty * $unit_cost, 2);

        $pdo = get_db();
        $pdo->beginTransaction();
        try {
            $ticket_number = generate_ticket_number($pdo);

            // Insert ticket
            $stmt = $pdo->prepare("
                INSERT INTO scrap_tickets
                    (ticket_number, bu, line, part_number, description, qty, unit_cost, amount, created_by)
                VALUES
                    (:tn, :bu, :line, :pn, :desc, :qty, :uc, :amount, :cb)
            ");
            $stmt->execute([
                ':tn'     => $ticket_number,
                ':bu'     => $bu,
                ':line'   => $line,
                ':pn'     => $part_number,
                ':desc'   => $description,
                ':qty'    => $qty,
                ':uc'     => $unit_cost,
                ':amount' => $amount,
                ':cb'     => $created_by,
            ]);
            $ticket_id = (int) $pdo->lastInsertId();

            // Create pending approval rows based on DOA
            $doa_levels = get_required_doa_levels($pdo, $amount);
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
            $errors[] = 'Failed to save ticket: ' . htmlspecialchars($e->getMessage());
        }
    }

    // Re-populate form values on error
    $form = compact('bu','line','part_number','description','qty_raw','unit_cost_raw','created_by');
} else {
    $form = ['bu'=>'','line'=>'','part_number'=>'','description'=>'','qty_raw'=>'','unit_cost_raw'=>'','created_by'=>''];
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

<nav class="navbar">
    <a class="brand" href="index.php">🏷️ <?= htmlspecialchars(APP_NAME) ?></a>
    <a class="nav-link" href="index.php">← Ticket List</a>
</nav>

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

                <!-- Row 2: Part Number -->
                <div class="form-group">
                    <label for="part_number">Part Number <span style="color:var(--danger)">*</span></label>
                    <input type="text" id="part_number" name="part_number" class="form-control"
                           maxlength="100" required
                           value="<?= htmlspecialchars($form['part_number']) ?>"
                           placeholder="e.g. ABC-12345">
                </div>

                <!-- Description -->
                <div class="form-group">
                    <label for="description">Description / Reason for Scrap</label>
                    <textarea id="description" name="description" class="form-control"
                              rows="3" maxlength="1000"
                              placeholder="Optional: describe the defect or reason…"><?= htmlspecialchars($form['description']) ?></textarea>
                </div>

                <!-- Row 3: Qty + Unit Cost + Amount (auto-calc) -->
                <div class="form-row">
                    <div class="form-group">
                        <label for="qty">Quantity (Qty) <span style="color:var(--danger)">*</span></label>
                        <input type="number" id="qty" name="qty" class="form-control"
                               min="0.01" step="any" required
                               value="<?= htmlspecialchars($form['qty_raw']) ?>"
                               placeholder="0">
                    </div>
                    <div class="form-group">
                        <label for="unit_cost">Unit Cost (USD) <span style="color:var(--danger)">*</span></label>
                        <input type="number" id="unit_cost" name="unit_cost" class="form-control"
                               min="0" step="any" required
                               value="<?= htmlspecialchars($form['unit_cost_raw']) ?>"
                               placeholder="0.00">
                    </div>
                    <div class="form-group">
                        <label for="amount_preview">Amount (USD) — auto</label>
                        <input type="text" id="amount_preview" class="form-control"
                               readonly style="background:#f8f9fa;font-weight:600;"
                               value="" placeholder="Qty × Unit Cost">
                    </div>
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
    const qtyEl  = document.getElementById('qty');
    const ucEl   = document.getElementById('unit_cost');
    const amtEl  = document.getElementById('amount_preview');

    function recalc() {
        const q  = parseFloat(qtyEl.value)  || 0;
        const uc = parseFloat(ucEl.value)   || 0;
        const a  = q * uc;
        amtEl.value = a > 0 ? '$' + a.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2}) : '';
    }

    qtyEl.addEventListener('input', recalc);
    ucEl.addEventListener('input',  recalc);
    recalc();
})();
</script>
</body>
</html>
