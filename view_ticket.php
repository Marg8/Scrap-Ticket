<?php
/**
 * view_ticket.php — View a scrap ticket and its DOA approval chain.
 */
require_once __DIR__ . '/db.php';

$pdo = get_db();

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($id <= 0) {
    header('Location: index.php');
    exit;
}

// Fetch ticket
$stmt = $pdo->prepare('SELECT * FROM scrap_tickets WHERE id = ?');
$stmt->execute([$id]);
$ticket = $stmt->fetch();

if (!$ticket) {
    header('Location: index.php');
    exit;
}

// Fetch approvals joined with DOA level info, ordered by level
$stmt = $pdo->prepare("
    SELECT a.*, d.level_name, d.level_order, d.approver_role AS doa_role, d.min_amount, d.max_amount
    FROM approvals a
    JOIN doa_levels d ON d.id = a.doa_level_id
    WHERE a.ticket_id = ?
    ORDER BY d.level_order ASC
");
$stmt->execute([$id]);
$approvals = $stmt->fetchAll();

$created_flash  = isset($_GET['created'])  && $_GET['created']  == '1';
$actioned_flash = isset($_GET['actioned']) && $_GET['actioned'] == '1';
$error_flash    = isset($_GET['error'])    ? htmlspecialchars($_GET['error']) : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ticket <?= htmlspecialchars($ticket['ticket_number']) ?> — <?= htmlspecialchars(APP_NAME) ?></title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>

<nav class="navbar">
    <a class="brand" href="index.php">🏷️ <?= htmlspecialchars(APP_NAME) ?></a>
    <a class="nav-link" href="index.php">← Ticket List</a>
</nav>

<div class="container" style="max-width:860px;">

    <?php if ($created_flash): ?>
        <div class="alert alert-success" style="margin-top:20px;">
            ✔ Ticket <strong><?= htmlspecialchars($ticket['ticket_number']) ?></strong> created successfully.
        </div>
    <?php endif; ?>

    <?php if ($actioned_flash): ?>
        <div class="alert alert-success" style="margin-top:20px;">
            ✔ Approval action recorded successfully.
        </div>
    <?php endif; ?>

    <?php if ($error_flash): ?>
        <div class="alert alert-danger" style="margin-top:20px;">
            ✖ Error: <?= $error_flash ?>
        </div>
    <?php endif; ?>

    <div style="display:flex;align-items:center;justify-content:space-between;margin:20px 0 16px;">
        <h1 class="page-title" style="margin:0;">
            <?= htmlspecialchars($ticket['ticket_number']) ?>
            <span class="badge badge-<?= htmlspecialchars($ticket['status']) ?>" style="font-size:13px;vertical-align:middle;margin-left:8px;">
                <?= htmlspecialchars(str_replace('_',' ', $ticket['status'])) ?>
            </span>
        </h1>
    </div>

    <!-- ── Ticket Details ──────────────────────── -->
    <div class="card">
        <div class="card-header"><h2>📄 Ticket Details</h2></div>
        <div class="card-body">
            <div class="detail-grid">
                <div class="detail-item">
                    <label>Business Unit (BU)</label>
                    <div class="value"><?= htmlspecialchars($ticket['bu']) ?></div>
                </div>
                <div class="detail-item">
                    <label>Line</label>
                    <div class="value"><?= htmlspecialchars($ticket['line']) ?></div>
                </div>
                <div class="detail-item">
                    <label>Part Number</label>
                    <div class="value"><?= htmlspecialchars($ticket['part_number']) ?></div>
                </div>
                <div class="detail-item">
                    <label>Quantity (Qty)</label>
                    <div class="value"><?= number_format((float)$ticket['qty'], 2) ?></div>
                </div>
                <div class="detail-item">
                    <label>Unit Cost (USD)</label>
                    <div class="value">$<?= number_format((float)$ticket['unit_cost'], 4) ?></div>
                </div>
                <div class="detail-item">
                    <label>Total Amount (USD)</label>
                    <div class="value" style="color:var(--primary);">$<?= number_format((float)$ticket['amount'], 2) ?></div>
                </div>
                <div class="detail-item">
                    <label>Submitted By</label>
                    <div class="value"><?= htmlspecialchars($ticket['created_by']) ?></div>
                </div>
                <div class="detail-item">
                    <label>Created At</label>
                    <div class="value"><?= htmlspecialchars($ticket['created_at']) ?></div>
                </div>
            </div>

            <?php if (!empty($ticket['description'])): ?>
                <div style="margin-top:16px;padding-top:16px;border-top:1px solid var(--border);">
                    <label style="font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:var(--muted);">Description / Reason for Scrap</label>
                    <p style="margin-top:4px;"><?= nl2br(htmlspecialchars($ticket['description'])) ?></p>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- ── DOA Approval Chain ──────────────────── -->
    <div class="card">
        <div class="card-header"><h2>✅ Approval Chain (DOA)</h2></div>
        <div class="card-body">
            <?php if (empty($approvals)): ?>
                <p style="color:var(--muted);">No approval levels required for this ticket amount.</p>
            <?php else: ?>
                <?php
                // Determine which level is "active" (first pending after all approved)
                $active_level_order = null;
                foreach ($approvals as $ap) {
                    if ($ap['action'] === ACTION_PENDING) {
                        $active_level_order = $ap['level_order'];
                        break;
                    }
                }
                ?>
                <?php foreach ($approvals as $ap): ?>
                    <?php
                    $step_class = 'approval-step';
                    if ($ap['action'] === 'approved') $step_class .= ' done';
                    elseif ($ap['action'] === 'rejected') $step_class .= ' denied';
                    elseif ($ap['level_order'] === $active_level_order) $step_class .= ' active';
                    ?>
                    <div class="approval-row">
                        <div class="<?= $step_class ?>"><?= (int)$ap['level_order'] ?></div>
                        <div class="approval-info">
                            <div class="approval-title">
                                <?= htmlspecialchars($ap['level_name']) ?>
                                <span class="badge badge-<?= htmlspecialchars($ap['action']) ?>" style="margin-left:8px;">
                                    <?= htmlspecialchars($ap['action']) ?>
                                </span>
                            </div>
                            <div class="approval-meta">
                                Required role: <strong><?= htmlspecialchars($ap['doa_role']) ?></strong>
                                &nbsp;|&nbsp;
                                Amount range: $<?= number_format((float)$ap['min_amount'],2) ?>
                                — <?= $ap['max_amount'] !== null ? '$'.number_format((float)$ap['max_amount'],2) : 'No limit' ?>
                            </div>
                            <?php if ($ap['action'] !== 'pending'): ?>
                                <div class="approval-meta" style="margin-top:4px;">
                                    <?php if ($ap['approver_name']): ?>
                                        Actioned by: <strong><?= htmlspecialchars($ap['approver_name']) ?></strong>
                                        (<?= htmlspecialchars($ap['approver_role']) ?>)
                                        on <?= htmlspecialchars($ap['acted_at']) ?>
                                    <?php endif; ?>
                                    <?php if (!empty($ap['comments'])): ?>
                                        <br>Comments: <em><?= nl2br(htmlspecialchars($ap['comments'])) ?></em>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>

                            <?php
                            // Show action form only for the currently active (pending) level
                            // and only if the ticket is not fully approved/rejected
                            $can_act = ($ap['action'] === ACTION_PENDING)
                                    && ($ap['level_order'] === $active_level_order)
                                    && !in_array($ticket['status'], [STATUS_APPROVED, STATUS_REJECTED], true);
                            ?>
                            <?php if ($can_act): ?>
                                <form method="post" action="approve_ticket.php" style="margin-top:10px;background:var(--light);padding:12px;border-radius:var(--radius);">
                                    <input type="hidden" name="approval_id" value="<?= (int)$ap['id'] ?>">
                                    <input type="hidden" name="ticket_id"   value="<?= (int)$ticket['id'] ?>">
                                    <div class="form-row" style="flex-wrap:wrap;gap:10px;">
                                        <div class="form-group" style="margin:0;flex:1;min-width:160px;">
                                            <label>Your Name <span style="color:var(--danger)">*</span></label>
                                            <input type="text" name="approver_name" class="form-control" required maxlength="100" placeholder="Full name">
                                        </div>
                                        <div class="form-group" style="margin:0;flex:1;min-width:140px;">
                                            <label>Your Role / Title <span style="color:var(--danger)">*</span></label>
                                            <input type="text" name="approver_role" class="form-control" required maxlength="100"
                                                   value="<?= htmlspecialchars($ap['doa_role']) ?>"
                                                   placeholder="<?= htmlspecialchars($ap['doa_role']) ?>">
                                        </div>
                                    </div>
                                    <div class="form-group" style="margin:8px 0 0;">
                                        <label>Comments (optional)</label>
                                        <textarea name="comments" class="form-control" rows="2" maxlength="1000"
                                                  placeholder="Reason for approval / rejection…"></textarea>
                                    </div>
                                    <div class="approval-actions" style="margin-top:10px;">
                                        <button type="submit" name="action" value="approved"
                                                class="btn btn-success">✔ Approve</button>
                                        <button type="submit" name="action" value="rejected"
                                                class="btn btn-danger">✖ Reject</button>
                                    </div>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

</div>
</body>
</html>
