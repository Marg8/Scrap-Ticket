<?php
require_once __DIR__ . '/db.php';

$pdo = get_db();

// Filters
$status_filter = isset($_GET['status']) && in_array($_GET['status'], [STATUS_PENDING, STATUS_APPROVED, STATUS_REJECTED, STATUS_PARTIALLY_APPROVED], true)
    ? $_GET['status'] : '';
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

$where  = [];
$params = [];

if ($status_filter !== '') {
    $where[]  = 'status = :status';
    $params[':status'] = $status_filter;
}
if ($search !== '') {
    $where[]  = '(ticket_number LIKE :s OR bu LIKE :s2 OR line LIKE :s3 OR part_number LIKE :s4 OR created_by LIKE :s5)';
    $like = '%' . $search . '%';
    $params[':s']  = $like;
    $params[':s2'] = $like;
    $params[':s3'] = $like;
    $params[':s4'] = $like;
    $params[':s5'] = $like;
}

$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$stmt = $pdo->prepare("
    SELECT id, ticket_number, bu, line, part_number, qty, unit_cost, amount, status, created_by, created_at
    FROM scrap_tickets
    $whereSql
    ORDER BY created_at DESC
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

<nav class="navbar">
    <a class="brand" href="index.php">🏷️ <?= htmlspecialchars(APP_NAME) ?></a>
    <a class="nav-link" href="create_ticket.php">+ New Ticket</a>
</nav>

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
                            <th>Part Number</th>
                            <th>Qty</th>
                            <th>Unit Cost</th>
                            <th>Amount (USD)</th>
                            <th>Status</th>
                            <th>Created By</th>
                            <th>Date</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($tickets as $t): ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($t['ticket_number']) ?></strong></td>
                            <td><?= htmlspecialchars($t['bu']) ?></td>
                            <td><?= htmlspecialchars($t['line']) ?></td>
                            <td><?= htmlspecialchars($t['part_number']) ?></td>
                            <td><?= number_format((float)$t['qty'], 2) ?></td>
                            <td>$<?= number_format((float)$t['unit_cost'], 4) ?></td>
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
