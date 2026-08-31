<?php
require_once __DIR__ . '/config.php';

function get_db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        if (DB_SOCKET) {
            $dsn = sprintf('mysql:unix_socket=%s;dbname=%s;charset=%s',
                DB_SOCKET, DB_NAME, DB_CHARSET);
        } else {
            $host = DB_HOST === 'localhost' ? '127.0.0.1' : DB_HOST;
            $dsn  = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s',
                $host, DB_PORT, DB_NAME, DB_CHARSET);
        }

        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            http_response_code(500);
            exit('Error de conexión a la base de datos: ' . htmlspecialchars($e->getMessage()));
        }

        init_schema($pdo);
    }
    return $pdo;
}

// Auto-create the Scrap-Ticket tables inside the existing "materials" DB.
function init_schema(PDO $pdo): void {
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

    // Seed the Delegation of Authority levels only if the table is empty.
    if ((int) $pdo->query('SELECT COUNT(*) FROM doa_levels')->fetchColumn() === 0) {
        $pdo->exec("
            INSERT INTO doa_levels (level_name, approver_role, min_amount, max_amount, level_order) VALUES
            ('Level 1 — Supervisor',  'Supervisor',       0.00,    500.00,  1),
            ('Level 2 — Manager',     'Manager',          500.01,  2000.00, 2),
            ('Level 3 — Director',    'Director',         2000.01, 10000.00,3),
            ('Level 4 — VP / Plant Manager', 'VP',        10000.01, NULL,   4)
        ");
    }

    // Line items — unlimited part numbers per ticket.
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS ticket_items (
            id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            ticket_id      INT UNSIGNED NOT NULL,
            part_number    VARCHAR(100) NOT NULL,
            description    TEXT,
            qty            DECIMAL(10,2) NOT NULL,
            unit_cost      DECIMAL(12,4) NOT NULL,
            amount         DECIMAL(14,2) NOT NULL,
            CONSTRAINT fk_item_ticket FOREIGN KEY (ticket_id) REFERENCES scrap_tickets (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // One-time migration: the legacy single-item columns become optional.
    $nullable = $pdo->query("
        SELECT IS_NULLABLE FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'scrap_tickets' AND COLUMN_NAME = 'part_number'
    ")->fetchColumn();
    if ($nullable === 'NO') {
        $pdo->exec("
            ALTER TABLE scrap_tickets
                MODIFY part_number VARCHAR(100)  NULL,
                MODIFY qty         DECIMAL(10,2) NULL,
                MODIFY unit_cost   DECIMAL(12,4) NULL
        ");
    }
}
