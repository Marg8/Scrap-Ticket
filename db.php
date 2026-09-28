<?php
require_once __DIR__ . '/config.php';

/** Se lanza cuando ninguna configuración de base de datos pudo conectar. */
class DbConnectionException extends RuntimeException {}

/** Registra un aviso no fatal (p.ej. falta de privilegios) para mostrarlo luego en la consola del navegador. */
function schema_warning(string $message): void {
    error_log($message);
    $GLOBALS['__schema_warnings'][] = $message;
}

/** Imprime en un <script> los avisos acumulados vía schema_warning(), si hay alguno. */
function schema_warnings_flush_to_console(): void {
    if (empty($GLOBALS['__schema_warnings'])) {
        return;
    }
    echo "<script>\n";
    foreach ($GLOBALS['__schema_warnings'] as $msg) {
        echo 'console.warn(' . json_encode('[schema] ' . $msg) . ");\n";
    }
    echo "</script>\n";
    $GLOBALS['__schema_warnings'] = [];
}

function get_db(): PDO {
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }

    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];

    // Config principal (XAMPP / producción). Si es rechazada —caso típico al
    // trabajar fuera del servidor— se intenta la config de respaldo opcional.
    // ⛔ ENTORNO MYSQL TEMPORAL: si TEMP_DB_ACTIVE está encendido, DB_HOST/USER/…
    //    provienen de .env.temp (nube gratuita) y SUSTITUYEN a la config normal.
    //    Todo esto se elimina al indicar "borrar el entorno mysql temporal".
    $candidates = [[
        'host' => DB_HOST, 'port' => DB_PORT, 'name' => DB_NAME,
        'user' => DB_USER, 'pass' => DB_PASS, 'socket' => DB_SOCKET,
    ]];
    if (DB_FALLBACK_HOST !== '' || DB_FALLBACK_SOCKET !== '') {
        $candidates[] = [
            'host' => DB_FALLBACK_HOST, 'port' => DB_FALLBACK_PORT, 'name' => DB_FALLBACK_NAME,
            'user' => DB_FALLBACK_USER, 'pass' => DB_FALLBACK_PASS, 'socket' => DB_FALLBACK_SOCKET,
        ];
    }

    $lastError = null;
    $attempts  = [];
    foreach ($candidates as $cfg) {
        if ($cfg['socket']) {
            $dsn = sprintf('mysql:unix_socket=%s;dbname=%s;charset=%s',
                $cfg['socket'], $cfg['name'], DB_CHARSET);
        } else {
            $host = $cfg['host'] === 'localhost' ? '127.0.0.1' : $cfg['host'];
            $dsn  = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s',
                $host, $cfg['port'], $cfg['name'], DB_CHARSET);
        }
        try {
            $pdo = new PDO($dsn, $cfg['user'], $cfg['pass'], $options);
            break;
        } catch (PDOException $e) {
            $lastError = $e;
            $attempts[] = $cfg['host'] . ': ' . $e->getMessage();
        }
    }

    if ($pdo === null) {
        $detail = implode(' | ', $attempts);
        error_log('DB connection failed: ' . $detail);
        throw new DbConnectionException($detail !== '' ? $detail : 'desconocido');
    }

    init_schema($pdo);
    return $pdo;
}

/**
 * Igual que get_db() pero nunca lanza ni detiene la ejecución: retorna null
 * si no hay conexión disponible, para que la página siga cargando en modo
 * "sin conexión" (solo lectura de UI, sin ningún acceso a datos).
 */
function get_db_or_null(): ?PDO {
    static $pdo = false;
    static $tried = false;
    if (!$tried) {
        $tried = true;
        try {
            $pdo = get_db();
        } catch (DbConnectionException $e) {
            $pdo = null;
        }
    }
    return $pdo;
}

// Auto-create the Scrap-Ticket tables inside the existing "materials" DB.
function init_schema(PDO $pdo): void {
    // Guard de rendimiento: toda la verificación/creación de esquema es costosa
    // (DDL + information_schema, aún más contra la BD en la nube). Se corre una
    // sola vez por versión y por base; los demás requests la omiten por completo.
    if (schema_already_current()) {
        return;
    }

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
            um             VARCHAR(20)  NULL,
            qty            DECIMAL(10,2) NOT NULL,
            unit_cost      DECIMAL(12,4) NOT NULL,
            amount         DECIMAL(14,2) NOT NULL,
            scrap_code     VARCHAR(100) NULL,
            CONSTRAINT fk_item_ticket FOREIGN KEY (ticket_id) REFERENCES scrap_tickets (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // Add the U/M and scrap-code columns to pre-existing installs.
    foreach (['um' => "VARCHAR(20) NULL AFTER description", 'scrap_code' => "VARCHAR(100) NULL AFTER amount"] as $col => $ddl) {
        $exists = $pdo->query("
            SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ticket_items' AND COLUMN_NAME = " . $pdo->quote($col) . "
        ")->fetchColumn();
        if ((int) $exists === 0) {
            try {
                $pdo->exec("ALTER TABLE ticket_items ADD COLUMN $col $ddl");
            } catch (PDOException $e) {
                schema_warning('init_schema: no se pudo agregar la columna "' . $col . '" en ticket_items: ' . $e->getMessage());
            }
        }
    }

    // One-time migration: the legacy single-item columns become optional.
    $nullable = $pdo->query("
        SELECT IS_NULLABLE FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'scrap_tickets' AND COLUMN_NAME = 'part_number'
    ")->fetchColumn();
    if ($nullable === 'NO') {
        try {
            $pdo->exec("
                ALTER TABLE scrap_tickets
                    MODIFY part_number VARCHAR(100)  NULL,
                    MODIFY qty         DECIMAL(10,2) NULL,
                    MODIFY unit_cost   DECIMAL(12,4) NULL
            ");
        } catch (PDOException $e) {
            schema_warning('init_schema: no se pudo modificar columnas de scrap_tickets: ' . $e->getMessage());
        }
    }

    init_admin_schema($pdo);

    require_once __DIR__ . '/item_master_lib.php';
    item_master_ensure_table($pdo);

    schema_mark_current();
}

/** Versión del esquema: súbela cuando cambies tablas/columnas para forzar una revalidación. */
const SCHEMA_VERSION = '2026-09-28.1';

/** Ruta del archivo centinela que recuerda que el esquema ya fue verificado. */
function schema_sentinel_path(): string {
    $env = (defined('TEMP_DB_ACTIVE') && TEMP_DB_ACTIVE) ? 'temp' : 'main';
    $key = md5(DB_HOST . '|' . DB_PORT . '|' . DB_NAME . '|' . $env);
    return sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'scrapticket_schema_' . $key . '.ver';
}

/** True si el esquema ya se verificó para esta versión y base (omite el DDL). */
function schema_already_current(): bool {
    $path = schema_sentinel_path();
    return is_file($path) && trim((string) @file_get_contents($path)) === SCHEMA_VERSION;
}

/** Registra que el esquema quedó verificado para esta versión y base. */
function schema_mark_current(): void {
    @file_put_contents(schema_sentinel_path(), SCHEMA_VERSION);
}

/**
 * Admin module — roles, usuarios, niveles DOA, asignaciones y suplencias.
 * Tablas: roles, usuarios, doa_niveles, doa_usuarios, usuario_backups.
 */
function init_admin_schema(PDO $pdo): void {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS roles (
            id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            clave       VARCHAR(30)  NOT NULL UNIQUE,
            nombre      VARCHAR(80)  NOT NULL,
            descripcion VARCHAR(255) NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // Seed the four fixed roles only if empty.
    if ((int) $pdo->query('SELECT COUNT(*) FROM roles')->fetchColumn() === 0) {
        $pdo->exec("
            INSERT INTO roles (clave, nombre, descripcion) VALUES
            ('admin',      'Administrador', 'Acceso total al sistema y al panel de configuración.'),
            ('capturista', 'Capturista',    'Creación de tickets de scrap y consulta del estatus de sus propios tickets.'),
            ('aprobador',  'Aprobador',     'Recibe notificaciones y aprueba/rechaza tickets de su nivel DOA.'),
            ('observador', 'Observador',    'Acceso de solo lectura a todo el sistema.')
        ");
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS usuarios (
            id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            email           VARCHAR(190) NOT NULL UNIQUE,
            username        VARCHAR(100) NULL,
            nombre_completo VARCHAR(150) NOT NULL,
            role_id         INT UNSIGNED NOT NULL,
            activo          TINYINT(1)   NOT NULL DEFAULT 1,
            created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_usuarios_role (role_id),
            CONSTRAINT fk_usuarios_role FOREIGN KEY (role_id) REFERENCES roles (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // Seed the initial administrator (idempotente: solo si aún no existe).
    $admin_email = 'mrodriguez17@littelfuse.com';
    $has_admin_user = $pdo->prepare('SELECT COUNT(*) FROM usuarios WHERE email = ?');
    $has_admin_user->execute([$admin_email]);
    if ((int) $has_admin_user->fetchColumn() === 0) {
        $seed = $pdo->prepare("
            INSERT INTO usuarios (email, username, nombre_completo, role_id, activo)
            SELECT ?, ?, ?, r.id, 1 FROM roles r WHERE r.clave = 'admin' LIMIT 1
        ");
        $seed->execute([$admin_email, 'mrodriguez17', 'Mrodriguez17']);
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS doa_niveles (
            id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            nivel      TINYINT UNSIGNED NOT NULL UNIQUE,
            nombre     VARCHAR(100) NOT NULL,
            min_amount DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            max_amount DECIMAL(14,2) NULL COMMENT 'NULL = sin límite superior',
            activo     TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            CONSTRAINT chk_nivel_rango CHECK (nivel BETWEEN 1 AND 10)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // Asignación usuario ↔ nivel DOA (un usuario pertenece a un solo nivel).
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS doa_usuarios (
            id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            nivel_id   INT UNSIGNED NOT NULL,
            usuario_id INT UNSIGNED NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_doa_usuario (usuario_id),
            KEY idx_doa_nivel (nivel_id),
            CONSTRAINT fk_doau_nivel   FOREIGN KEY (nivel_id)   REFERENCES doa_niveles (id) ON DELETE CASCADE,
            CONSTRAINT fk_doau_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios    (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // Suplencias: un titular tiene un usuario backup durante un periodo.
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS usuario_backups (
            id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            titular_id   INT UNSIGNED NOT NULL,
            backup_id    INT UNSIGNED NOT NULL,
            fecha_inicio DATE NULL,
            fecha_fin    DATE NULL,
            activo       TINYINT(1) NOT NULL DEFAULT 1,
            created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_backup_titular (titular_id),
            KEY idx_backup_backup  (backup_id),
            CONSTRAINT fk_backup_titular FOREIGN KEY (titular_id) REFERENCES usuarios (id) ON DELETE CASCADE,
            CONSTRAINT fk_backup_backup  FOREIGN KEY (backup_id)  REFERENCES usuarios (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}
