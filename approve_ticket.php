<?php
/**
 * approve_ticket.php — Process an approval or rejection action.
 * Accepts POST only. Redirects back to view_ticket.php after processing.
 */
require_once __DIR__ . '/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

$pdo = get_db();

$approval_id   = isset($_POST['approval_id'])   ? (int) $_POST['approval_id']   : 0;
$ticket_id     = isset($_POST['ticket_id'])     ? (int) $_POST['ticket_id']     : 0;
$action        = isset($_POST['action'])        ? trim($_POST['action'])         : '';
$approver_name = isset($_POST['approver_name']) ? trim($_POST['approver_name'])  : '';
$approver_role = isset($_POST['approver_role']) ? trim($_POST['approver_role'])  : '';
$comments      = isset($_POST['comments'])      ? trim($_POST['comments'])       : '';

// Validate action
if (!in_array($action, ['approved', 'rejected'], true)) {
    header('Location: view_ticket.php?id=' . $ticket_id . '&error=invalid_action');
    exit;
}

if ($approval_id <= 0 || $ticket_id <= 0) {
    header('Location: index.php');
    exit;
}

if ($approver_name === '' || $approver_role === '') {
    header('Location: view_ticket.php?id=' . $ticket_id . '&error=missing_name');
    exit;
}

$pdo->beginTransaction();
try {
    // Verify the approval row exists, belongs to the ticket, and is still pending
    $stmt = $pdo->prepare("
        SELECT a.*, d.level_order
        FROM approvals a
        JOIN doa_levels d ON d.id = a.doa_level_id
        WHERE a.id = ? AND a.ticket_id = ? AND a.action = 'pending'
        FOR UPDATE
    ");
    $stmt->execute([$approval_id, $ticket_id]);
    $approval = $stmt->fetch();

    if (!$approval) {
        $pdo->rollBack();
        header('Location: view_ticket.php?id=' . $ticket_id . '&error=already_actioned');
        exit;
    }

    // Confirm it is the lowest-order pending level (sequential enforcement)
    $stmt2 = $pdo->prepare("
        SELECT MIN(d.level_order)
        FROM approvals a
        JOIN doa_levels d ON d.id = a.doa_level_id
        WHERE a.ticket_id = ? AND a.action = 'pending'
    ");
    $stmt2->execute([$ticket_id]);
    $min_order = (int) $stmt2->fetchColumn();

    if ($approval['level_order'] !== $min_order) {
        $pdo->rollBack();
        header('Location: view_ticket.php?id=' . $ticket_id . '&error=out_of_order');
        exit;
    }

    // Update approval row
    $upd = $pdo->prepare("
        UPDATE approvals
        SET action        = :action,
            approver_name = :name,
            approver_role = :role,
            comments      = :comments,
            acted_at      = NOW()
        WHERE id = :id
    ");
    $upd->execute([
        ':action'   => $action,
        ':name'     => $approver_name,
        ':role'     => $approver_role,
        ':comments' => $comments,
        ':id'       => $approval_id,
    ]);

    // ── Recalculate ticket status ────────────────
    // Fetch all approvals for this ticket
    $all_stmt = $pdo->prepare("
        SELECT action FROM approvals WHERE ticket_id = ?
    ");
    $all_stmt->execute([$ticket_id]);
    $all_actions = $all_stmt->fetchAll(PDO::FETCH_COLUMN);

    if (in_array('rejected', $all_actions, true)) {
        $new_status = 'rejected';
    } elseif (in_array('pending', $all_actions, true)) {
        $new_status = 'partially_approved';
    } else {
        $new_status = 'approved'; // all approved
    }

    $pdo->prepare("UPDATE scrap_tickets SET status = ? WHERE id = ?")
        ->execute([$new_status, $ticket_id]);

    $pdo->commit();
    header('Location: view_ticket.php?id=' . $ticket_id . '&actioned=1');
    exit;

} catch (Exception $e) {
    $pdo->rollBack();
    header('Location: view_ticket.php?id=' . $ticket_id . '&error=' . urlencode($e->getMessage()));
    exit;
}
