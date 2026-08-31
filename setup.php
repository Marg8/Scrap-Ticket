<?php
/**
 * setup.php — Run this script once to create the database and seed initial data.
 * Usage: php setup.php  OR  visit http://your-server/setup.php
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

$messages = [];
$errors   = [];

try {
    // Connect to the existing "materials" DB and create the Scrap-Ticket tables.
    $pdo = get_db();
    $messages[] = 'Connected to database "' . DB_NAME . '".';

    $has = fn(string $t) => (bool) $pdo->query("SHOW TABLES LIKE " . $pdo->quote($t))->fetchColumn();
    foreach (['scrap_tickets', 'doa_levels', 'approvals'] as $table) {
        $messages[] = $has($table)
            ? 'Table "' . $table . '" ready.'
            : 'Table "' . $table . '" could not be created.';
    }

    $count = (int) $pdo->query('SELECT COUNT(*) FROM doa_levels')->fetchColumn();
    $messages[] = $count > 0
        ? 'DOA levels present (' . $count . ' levels).'
        : 'DOA levels table is empty.';

} catch (PDOException $e) {
    error_log('setup.php error: ' . $e->getMessage());
    $errors[] = 'A database error occurred. Please check your configuration and server logs.';
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Setup — <?= htmlspecialchars(APP_NAME) ?></title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>

<?php
$active_page   = 'setup';
$page_subtitle = 'Database Setup';
require __DIR__ . '/partials/header.php';
?>

<div class="container" style="max-width:640px;margin-top:60px;">
    <div class="card">
        <div class="card-header">
            <h2>⚙️ Database Setup</h2>
        </div>
        <div class="card-body">
            <?php foreach ($messages as $msg): ?>
                <p class="alert alert-success">✔ <?= htmlspecialchars($msg) ?></p>
            <?php endforeach; ?>
            <?php foreach ($errors as $err): ?>
                <p class="alert alert-danger">✖ <?= $err ?></p>
            <?php endforeach; ?>
            <?php if (empty($errors)): ?>
                <p>Setup complete. <a href="index.php">Go to the application →</a></p>
            <?php endif; ?>
        </div>
    </div>
</div>
</body>
</html>
