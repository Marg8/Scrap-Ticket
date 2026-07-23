<?php
/**
 * setup.php — Run this script once to create the database and seed initial data.
 * Usage: php setup.php  OR  visit http://your-server/setup.php
 */

require_once __DIR__ . '/config.php';

$messages = [];
$errors   = [];

try {
    // Connect without selecting a database first so we can create it
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';charset=' . DB_CHARSET,
        DB_USER,
        DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    // Create database
    $pdo->exec('CREATE DATABASE IF NOT EXISTS `' . DB_NAME . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $messages[] = 'Database "' . DB_NAME . '" created (or already exists).';

    $pdo->exec('USE `' . DB_NAME . '`');

    // ----------------------------------------------------------------
    // Table: scrap_tickets
    // ----------------------------------------------------------------
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS scrap_tickets (
            id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            ticket_number  VARCHAR(20)  NOT NULL UNIQUE,
            bu             VARCHAR(100) NOT NULL,
            line           VARCHAR(100) NOT NULL,
            part_number    VARCHAR(100) NOT NULL,
            description    TEXT,
            qty            DECIMAL(10,2) NOT NULL,
            unit_cost      DECIMAL(12,4) NOT NULL,
            amount         DECIMAL(14,2) NOT NULL,
            status         ENUM('pending','approved','rejected','partially_approved') NOT NULL DEFAULT 'pending',
            created_by     VARCHAR(100) NOT NULL,
            created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $messages[] = 'Table "scrap_tickets" ready.';

    // ----------------------------------------------------------------
    // Table: doa_levels  (Delegation of Authority)
    // ----------------------------------------------------------------
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS doa_levels (
            id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            level_name     VARCHAR(100) NOT NULL,
            approver_role  VARCHAR(100) NOT NULL,
            min_amount     DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            max_amount     DECIMAL(14,2) NULL COMMENT 'NULL means no upper limit',
            level_order    TINYINT UNSIGNED NOT NULL,
            UNIQUE KEY uq_level_order (level_order)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $messages[] = 'Table "doa_levels" ready.';

    // Seed DOA levels only if empty
    $count = (int) $pdo->query('SELECT COUNT(*) FROM doa_levels')->fetchColumn();
    if ($count === 0) {
        $pdo->exec("
            INSERT INTO doa_levels (level_name, approver_role, min_amount, max_amount, level_order) VALUES
            ('Level 1 — Supervisor',  'Supervisor',       0.00,    500.00,  1),
            ('Level 2 — Manager',     'Manager',          500.01,  2000.00, 2),
            ('Level 3 — Director',    'Director',         2000.01, 10000.00,3),
            ('Level 4 — VP / Plant Manager', 'VP',        10000.01, NULL,   4)
        ");
        $messages[] = 'DOA levels seeded (4 levels).';
    } else {
        $messages[] = 'DOA levels already seeded — skipped.';
    }

    // ----------------------------------------------------------------
    // Table: approvals
    // ----------------------------------------------------------------
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS approvals (
            id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            ticket_id      INT UNSIGNED NOT NULL,
            doa_level_id   INT UNSIGNED NOT NULL,
            approver_name  VARCHAR(100) NOT NULL DEFAULT '',
            approver_role  VARCHAR(100) NOT NULL DEFAULT '',
            action         ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
            comments       TEXT,
            acted_at       DATETIME NULL,
            UNIQUE KEY uq_ticket_level (ticket_id, doa_level_id),
            CONSTRAINT fk_approval_ticket FOREIGN KEY (ticket_id)    REFERENCES scrap_tickets (id) ON DELETE CASCADE,
            CONSTRAINT fk_approval_doa    FOREIGN KEY (doa_level_id) REFERENCES doa_levels    (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $messages[] = 'Table "approvals" ready.';

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
