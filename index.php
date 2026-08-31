<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';

$pdo = get_db();

// Filters
$status_filter = isset($_GET['status']) && in_array($_GET['status'], [STATUS_PENDING, STATUS_APPROVED, STATUS_REJECTED, STATUS_PARTIALLY_APPROVED], true)
    ? $_GET['status'] : '';
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

$where  = [];
$params = [];

if ($status_filter !== '') {
    $where[]  = 't.status = :status';
    $params[':status'] = $status_filter;
}
if ($search !== '') {
    $where[]  = '(t.ticket_number LIKE :s1 OR t.bu LIKE :s2 OR t.line LIKE :s3 OR t.created_by LIKE :s4 OR t.part_number LIKE :s5
                  OR EXISTS (SELECT 1 FROM ticket_items ti WHERE ti.ticket_id = t.id AND ti.part_number LIKE :s6))';
    $like = '%' . $search . '%';
    $params[':s1'] = $like;
    $params[':s2'] = $like;
    $params[':s3'] = $like;
    $params[':s4'] = $like;
    $params[':s5'] = $like;
    $params[':s6'] = $like;
}

$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$stmt = $pdo->prepare("
    SELECT t.id, t.ticket_number, t.bu, t.line, t.part_number AS legacy_part, t.qty AS legacy_qty,
           t.amount, t.status, t.created_by, t.created_at,
           COUNT(i.id)            AS item_count,
           COALESCE(SUM(i.qty),0) AS total_qty,
           MIN(i.part_number)     AS first_part
    FROM scrap_tickets t
    LEFT JOIN ticket_items i ON i.ticket_id = t.id
    $whereSql
    GROUP BY t.id, t.ticket_number, t.bu, t.line, t.part_number, t.qty, t.amount, t.status, t.created_by, t.created_at
    ORDER BY t.created_at DESC
");
$stmt->execute($params);
$tickets = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars(APP_NAME) ?></title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>

<?php
$active_page   = 'index';
$page_subtitle = 'Scrap Tickets';
require __DIR__ . '/partials/header.php';
?>

<div class="container">
    <h1 class="page-title">Scrap Tickets</h1>

    <!-- Filter / Search bar -->
    <div class="card">
        <div class="card-body" style="padding:14px 20px;">
            <form method="get" style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end;">
                <div class="form-group" style="margin:0;flex:1;min-width:160px;">
                    <label for="search">Search</label>
                    <input type="text" id="search" name="search" class="form-control"
                           placeholder="Ticket #, BU, Line, Part…"
                           value="<?= htmlspecialchars($search) ?>">
                </div>
                <div class="form-group" style="margin:0;min-width:160px;">
                    <label for="status">Status</label>
                    <select id="status" name="status" class="form-control">
                        <option value="">All statuses</option>
                        <?php foreach (['pending','partially_approved','approved','rejected'] as $s): ?>
                            <option value="<?= $s ?>" <?= $status_filter === $s ? 'selected' : '' ?>><?= ucfirst(str_replace('_',' ',$s)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div style="display:flex;gap:8px;align-items:flex-end;">
                    <button type="submit" class="btn btn-primary">Filter</button>
                    <a href="index.php" class="btn btn-secondary">Reset</a>
                </div>
            </form>
        </div>
    </div>

    <!-- Ticket table -->
    <div class="card">
        <div class="card-header">
            <h2>Tickets (<?= count($tickets) ?>)</h2>
            <a href="create_ticket.php" class="btn btn-primary btn-sm">+ New Ticket</a>
        </div>
        <div class="card-body" style="padding:0;">
            <?php if (empty($tickets)): ?>
                <p style="padding:20px;color:var(--muted);">No tickets found. <a href="create_ticket.php">Create one</a>.</p>
            <?php else: ?>
            <div class="table-wrapper">
                <table>
                    <thead>
                        <tr>
                            <th>Ticket #</th>
                            <th>BU</th>
                            <th>Line</th>
                            <th>Part Numbers</th>
                            <th>Total Qty</th>
                            <th>Amount (USD)</th>
                            <th>Status</th>
                            <th>Created By</th>
                            <th>Date</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($tickets as $t): ?>
                        <?php
                            $itemCount = (int) $t['item_count'];
                            if ($itemCount > 0) {
                                $partsLabel = htmlspecialchars((string) $t['first_part']);
                                if ($itemCount > 1) {
                                    $partsLabel .= ' <span style="color:var(--muted);">+' . ($itemCount - 1) . ' more</span>';
                                }
                                $qtyLabel = number_format((float) $t['total_qty'], 2);
                            } else {
                                $partsLabel = htmlspecialchars((string) $t['legacy_part']);
                                $qtyLabel   = $t['legacy_qty'] !== null ? number_format((float) $t['legacy_qty'], 2) : '—';
                            }
                        ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($t['ticket_number']) ?></strong></td>
                            <td><?= htmlspecialchars($t['bu']) ?></td>
                            <td><?= htmlspecialchars($t['line']) ?></td>
                            <td><?= $partsLabel !== '' ? $partsLabel : '<span style="color:var(--muted);">—</span>' ?></td>
                            <td><?= $qtyLabel ?></td>
                            <td><strong>$<?= number_format((float)$t['amount'], 2) ?></strong></td>
                            <td><span class="badge badge-<?= htmlspecialchars($t['status']) ?>"><?= htmlspecialchars(str_replace('_',' ',$t['status'])) ?></span></td>
                            <td><?= htmlspecialchars($t['created_by']) ?></td>
                            <td><?= htmlspecialchars(date('Y-m-d', strtotime($t['created_at']))) ?></td>
                            <td><a href="view_ticket.php?id=<?= (int)$t['id'] ?>" class="btn btn-info btn-sm">View</a></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>
</body>
</html>
