<?php
/**
 * setup.php — Run this script once to create the database and seed initial data.
 * Usage: php setup.php  OR  visit http://your-server/setup.php
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

$messages = [];
$errors   = [];
$db_offline = false;

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

} catch (DbConnectionException $e) {
    $db_offline = true;
    $errors[] = 'Sin conexión a la base de datos: ' . htmlspecialchars($e->getMessage());
} catch (PDOException $e) {
    error_log('setup.php error: ' . $e->getMessage());
    $errors[] = 'A database error occurred. Please check your configuration and server logs.';
}

// Última actualización de cada documento (MAX(imported_at) por tabla).
$data_sources = [
    'Item Master' => [
        'table' => 'item_master', 'url' => 'item_master.php', 'icon' => '📦',
        'note'  => 'Obtener del correo de <b>Master Data</b>.',
    ],
    'BOM (Bill of Material)' => [
        'table' => 'bom_records', 'url' => 'bom.php', 'icon' => '🧩',
        'note'  => 'Obtener del correo de <b>Master Data</b>, archivo <b>BOM multilevel</b>.',
    ],
    'MRP List' => [
        'table' => 'mrp_records', 'url' => 'mrp.php', 'icon' => '📋',
        'note'  => 'Obtener de <b>SAP</b>, transacción <b>SE16N</b>, tabla <b>T024D</b>.',
    ],
    'Códigos de Scrap' => [
        'table' => 'scrap_codes', 'url' => 'scrap_codes.php', 'icon' => '♻️',
        'note'  => 'Columnas: <b>Language Key · Movement Type · Reason for Movement</b>.',
    ],
];
$last_updates = [];
if (!$db_offline && isset($pdo)) {
    $table_exists = fn(string $t): bool => (bool) $pdo->query("SHOW TABLES LIKE " . $pdo->quote($t))->fetchColumn();
    foreach ($data_sources as $name => $info) {
        $last = null;
        $rows = 0;
        if ($table_exists($info['table'])) {
            try {
                $stmt = $pdo->query("SELECT COUNT(*) AS n, MAX(imported_at) AS last FROM `" . $info['table'] . "`");
                $r = $stmt->fetch();
                $rows = (int) ($r['n'] ?? 0);
                $last = $r['last'] ?? null;
            } catch (PDOException $e) {
                // Tabla sin columna imported_at o sin acceso; se muestra como sin datos.
            }
        }
        $last_updates[$name] = ['last' => $last, 'rows' => $rows] + $info;
    }
} else {
    foreach ($data_sources as $name => $info) {
        $last_updates[$name] = ['last' => null, 'rows' => 0] + $info;
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Setup — <?= htmlspecialchars(APP_NAME) ?></title>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .data-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
            gap: 16px;
        }
        .data-tile {
            display: flex;
            flex-direction: column;
            text-decoration: none;
            color: inherit;
            background: #fff;
            border: 1px solid #d9e2dc;
            border-left: 5px solid #1f7a34;
            border-radius: 10px;
            padding: 16px 18px;
            transition: transform .12s ease, box-shadow .12s ease, border-color .12s ease;
        }
        .data-tile:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 20px rgba(31,122,52,.15);
            border-left-color: #14532d;
        }
        .data-tile.is-empty { border-left-color: #b00020; }
        .data-tile.is-empty:hover { border-left-color: #7f1d1d; box-shadow: 0 8px 20px rgba(176,0,32,.15); }
        .data-tile-head {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 8px;
        }
        .data-tile-icon { font-size: 26px; line-height: 1; }
        .data-tile-title { font-size: 16px; font-weight: 700; color: #14532d; }
        .data-tile-note { font-size: 12px; color: var(--muted); margin-bottom: 12px; flex-grow: 1; }
        .data-tile-meta {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 12px;
            border-top: 1px dashed #d9e2dc;
            padding-top: 10px;
        }
        .data-tile-status { font-weight: 600; }
        .data-tile-status.ok  { color: #1f7a34; }
        .data-tile-status.bad { color: #b00020; }
        .data-tile-cta {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            margin-top: 12px;
            align-self: flex-start;
            background: #1f7a34;
            color: #fff;
            font-weight: 700;
            font-size: 13px;
            padding: 7px 14px;
            border-radius: 6px;
        }
        .data-tile:hover .data-tile-cta { background: #14532d; }
    </style>
</head>
<body>

<?php
$active_page   = 'setup';
$page_subtitle = 'Database Setup';
require __DIR__ . '/partials/header.php';
?>

<div class="container" style="max-width:1000px;margin-top:60px;">
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

    <div class="card" style="margin-top:20px;">
        <div class="card-header">
            <h2>📥 Carga de datos</h2>
        </div>
        <div class="card-body">
            <?php if ($db_offline): ?>
                <p class="alert alert-warning">Sin conexión a la base de datos: no es posible cargar archivos ni mostrar fechas.</p>
            <?php endif; ?>
            <p style="color:var(--muted);margin:0 0 16px;">Haz clic en cualquier documento para <b>cargar o actualizar</b> su archivo.</p>
            <div class="data-grid">
                <?php foreach ($last_updates as $name => $info): ?>
                    <?php
                        $has_data = $info['last'] !== null;
                        $when = $has_data ? date('d-M-Y H:i', strtotime((string) $info['last'])) : 'Sin cargar';
                    ?>
                    <a class="data-tile <?= $has_data ? '' : 'is-empty' ?>" href="<?= htmlspecialchars($info['url']) ?>">
                        <div class="data-tile-head">
                            <span class="data-tile-icon"><?= $info['icon'] ?></span>
                            <span class="data-tile-title"><?= htmlspecialchars($name) ?></span>
                        </div>
                        <div class="data-tile-note"><?= $info['note'] ?></div>
                        <div class="data-tile-meta">
                            <span><b><?= number_format($info['rows']) ?></b> registros</span>
                            <span class="data-tile-status <?= $has_data ? 'ok' : 'bad' ?>">
                                <?= $has_data ? '✔ ' : '✘ ' ?><?= htmlspecialchars($when) ?>
                            </span>
                        </div>
                        <span class="data-tile-cta">⬆ Cargar / actualizar</span>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>
</body>
</html>
