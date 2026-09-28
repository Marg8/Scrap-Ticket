<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/admin_lib.php';
require_once __DIR__ . '/item_master_lib.php';

$pdo = get_db_or_null();
$db_offline = $pdo === null;
if (!$db_offline) {
    admin_require_admin($pdo);
}

$scrap_columns = ['Language Key', 'Movement Type', 'Reason for Movement', 'Reason for Movement'];
$scrap_db_names = ['language', 'mvt', 'reas', 'reason'];
$scrap_table = 'scrap_codes';
$alerts = [];
$summary = null;
$row_count = 0;

if (!$db_offline) {
    try {
        $create_sql = "CREATE TABLE IF NOT EXISTS `$scrap_table` (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            language VARCHAR(20) NULL,
            mvt VARCHAR(20) NULL,
            reas VARCHAR(40) NULL,
            reason VARCHAR(255) NULL,
            imported_by VARCHAR(150) NULL,
            imported_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_scrap_reas (reas),
            UNIQUE KEY uq_scrap_all (language, mvt, reas, reason(150))
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        $pdo->exec($create_sql);

        // Migración: quitar índices únicos heredados que sólo miran 2 columnas
        // (uq_scrap_code = mvt+reas) y colapsan filas que difieren en otras columnas.
        $stale = ['uq_scrap_code', 'uq_scrap_hash'];
        $present = [];
        foreach ($stale as $idx_name) {
            $exists = (int) $pdo->query("SELECT COUNT(*) FROM information_schema.STATISTICS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '$scrap_table' AND INDEX_NAME = '$idx_name'")->fetchColumn();
            if ($exists > 0) {
                $present[] = $idx_name;
            }
        }
        foreach ($present as $idx_name) {
            try {
                $pdo->exec("ALTER TABLE `$scrap_table` DROP INDEX $idx_name");
            } catch (PDOException $e) {
                schema_warning("scrap_codes: no se pudo quitar el índice $idx_name (¿sin permiso ALTER?): " . $e->getMessage());
            }
        }
        // Si el ALTER fue denegado y el índice de 2 columnas sigue ahí, reconstruir
        // la tabla (preservando los datos) sin esa restricción.
        $still_bad = (int) $pdo->query("SELECT COUNT(*) FROM information_schema.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '$scrap_table' AND INDEX_NAME = 'uq_scrap_code'")->fetchColumn();
        if ($still_bad > 0) {
            try {
                $old_rows = $pdo->query("SELECT language, mvt, reas, reason, imported_by, imported_at FROM `$scrap_table`")->fetchAll(PDO::FETCH_ASSOC);
                $pdo->exec("DROP TABLE `$scrap_table`");
                $pdo->exec($create_sql);
                if ($old_rows) {
                    $reinsert = $pdo->prepare("INSERT IGNORE INTO `$scrap_table`
                        (language, mvt, reas, reason, imported_by, imported_at) VALUES (?, ?, ?, ?, ?, ?)");
                    foreach ($old_rows as $r) {
                        $reinsert->execute([$r['language'], $r['mvt'], $r['reas'], $r['reason'], $r['imported_by'], $r['imported_at']]);
                    }
                }
            } catch (PDOException $e) {
                schema_warning('scrap_codes: no se pudo reconstruir la tabla sin uq_scrap_code: ' . $e->getMessage());
                $alerts[] = ['danger', 'La tabla aún tiene el índice único uq_scrap_code (mvt+reas) y no se pudo quitar. '
                    . 'Pide al administrador de la base de datos ejecutar: ALTER TABLE `' . $scrap_table . '` DROP INDEX uq_scrap_code;'];
            }
        }
        $row_count = (int) $pdo->query("SELECT COUNT(*) FROM `$scrap_table`")->fetchColumn();
    } catch (PDOException $e) {
        $alerts[] = ['danger', 'No se pudo preparar la tabla de códigos de scrap: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8')];
    }
}

if (isset($_GET['template'])) {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="scrap_codes_template.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, $scrap_columns, ',', '"', '');
    fclose($out);
    exit;
}

if (!$db_offline && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!admin_check_csrf()) {
            throw new RuntimeException('Token de seguridad inválido. Recarga la página e intenta de nuevo.');
        }
        $file = $_FILES['scrap_file'] ?? null;
        if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Selecciona un archivo de códigos de scrap válido para cargar.');
        }
        if (!is_uploaded_file($file['tmp_name'])) {
            throw new RuntimeException('El archivo recibido no es válido.');
        }
        $ext = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, item_master_allowed_extensions(), true)) {
            throw new RuntimeException('Formato no soportado. Usa .xlsx, .csv o .txt.');
        }

        // Colas por encabezado normalizado: tolera columnas con el mismo nombre
        // (p. ej. "Reason for Movement" aparece dos veces) consumiéndolas en orden.
        $pending = [];
        foreach ($scrap_columns as $index => $label) {
            $pending[item_master_normalize($label)][] = $index;
        }
        $mapping = null;
        $inserted = 0;
        $skipped = 0;
        $duplicates = 0;
        $dup_list = [];
        $seen = [];
        $errors = [];
        $line_no = 0;
        $mode = ($_POST['mode'] ?? 'replace') === 'append' ? 'append' : 'replace';
        $user = (string) ($_SESSION['user']['mail'] ?? ($_SESSION['user']['username'] ?? 'sistema'));

        $pdo->beginTransaction();
        if ($mode === 'replace') {
            $pdo->exec("DELETE FROM `$scrap_table`");
        } else {
            // En modo agregar, pre-cargar las filas existentes para no duplicarlas.
            foreach ($pdo->query("SELECT language, mvt, reas, reason FROM `$scrap_table`") as $existing) {
                $key = implode("\x1f", array_map(static fn($v) => (string) ($v ?? ''), [
                    $existing['language'], $existing['mvt'], $existing['reas'], $existing['reason'],
                ]));
                $seen[$key] = true;
            }
        }
        $stmt = null;
        foreach (item_master_read_file($file['tmp_name'], $ext) as $row) {
            $line_no++;
            if ($mapping === null) {
                if (!array_filter($row, static fn($value) => trim((string) $value) !== '')) {
                    continue;
                }
                $queues = $pending;
                $mapping = [];
                foreach ($row as $idx => $heading) {
                    $key = item_master_normalize((string) $heading);
                    if (!empty($queues[$key])) {
                        $mapping[array_shift($queues[$key])] = $idx;
                    }
                }
                if (count($mapping) !== count($scrap_columns)) {
                    $missing_labels = [];
                    foreach ($queues as $indexes) {
                        foreach ($indexes as $i) {
                            $missing_labels[] = $scrap_columns[$i];
                        }
                    }
                    throw new RuntimeException('Faltan encabezados requeridos: ' . implode(', ', array_unique($missing_labels)));
                }
                $insert_columns = array_merge($scrap_db_names, ['imported_by']);
                $stmt = $pdo->prepare(
                    "INSERT INTO `$scrap_table` (`" . implode('`, `', $insert_columns) . '`) VALUES ('
                    . implode(', ', array_fill(0, count($insert_columns), '?')) . ')'
                );
                continue;
            }

            if (!array_filter($row, static fn($value) => trim((string) $value) !== '')) {
                $skipped++;
                continue;
            }
            $values = [];
            foreach ($scrap_db_names as $column_index => $db_name) {
                $value = trim((string) ($row[$mapping[$column_index]] ?? ''));
                $values[] = $value === '' ? null : $value;
            }
            // Requisito: 'reas' (índice 2) y 'reason' (índice 3) no pueden ir vacíos.
            // Las demás columnas (language, mvt) sí admiten NULL.
            if ($values[2] === null || $values[3] === null) {
                $skipped++;
                continue;
            }
            // Clave de todas las columnas: sólo es duplicado si TODOS los valores coinciden.
            $key = implode("\x1f", array_map(static fn($v) => (string) ($v ?? ''), $values));
            if (isset($seen[$key])) {
                $duplicates++;
                if (count($dup_list) < 50) {
                    $dup_list[] = 'Fila ' . $line_no . ': ' . implode(' · ', array_map(static fn($v) => (string) ($v ?? ''), $values));
                }
                continue;
            }
            $seen[$key] = true;
            try {
                $stmt->execute([...$values, $user]);
                $inserted++;
            } catch (PDOException $e) {
                $skipped++;
                if (count($errors) < 10) {
                    $errors[] = 'Fila ' . $line_no . ': ' . $e->getMessage();
                }
            }
        }
        if ($mapping === null) {
            throw new RuntimeException('El archivo está vacío o no contiene encabezados.');
        }
        $pdo->commit();
        $summary = ['processed' => $inserted, 'skipped' => $skipped, 'duplicates' => $duplicates, 'dup_list' => $dup_list, 'errors' => $errors];
        $alerts[] = ['success', sprintf('Carga de códigos de scrap completada: %d registros cargados, %d duplicados omitidos, %d filas omitidas (vacías o sin Reason for Movement).', $inserted, $duplicates, $skipped)];
        $row_count = (int) $pdo->query("SELECT COUNT(*) FROM `$scrap_table`")->fetchColumn();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $alerts[] = ['danger', htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8')];
    }
}

$preview = [];
if (!$db_offline && $row_count > 0) {
    try {
        $preview = $pdo->query("SELECT language, mvt, reas, reason, imported_at FROM `$scrap_table` ORDER BY imported_at DESC, id DESC LIMIT 25")->fetchAll();
    } catch (PDOException $e) {
        // Tabla recién creada sin datos legibles; se ignora.
    }
}

$active_page = 'setup';
$page_subtitle = 'Códigos de Scrap';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Códigos de Scrap — <?= htmlspecialchars(APP_NAME) ?></title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<?php require __DIR__ . '/partials/header.php'; ?>
<div class="container" style="max-width:1000px;">
    <h1 class="page-title">Códigos de Scrap</h1>
    <?php foreach ($alerts as [$type, $message]): ?>
        <div class="alert alert-<?= $type ?>"><?= $message ?></div>
    <?php endforeach; ?>
    <?php if ($db_offline): ?>
        <div class="alert alert-warning">Sin conexión a la base de datos; no es posible cargar los códigos de scrap.</div>
    <?php endif; ?>

    <div class="card">
        <div class="card-header"><h2>Agregar / actualizar códigos de scrap</h2></div>
        <div class="card-body">
            <p>La primera fila debe incluir estos <?= count($scrap_columns) ?> encabezados. Se aceptan en cualquier orden; deben coincidir por nombre.</p>
            <p class="im-mono"><?= htmlspecialchars(implode(' · ', $scrap_columns)) ?></p>
            <p>Registros actuales: <b><?= number_format($row_count) ?></b></p>
            <a class="btn btn-info" href="scrap_codes.php?template=csv">Descargar plantilla CSV</a>
            <form method="post" enctype="multipart/form-data" style="margin-top:18px;">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(admin_csrf_token()) ?>">
                <div class="form-row">
                    <div class="form-group">
                        <label for="scrap_file">Archivo (.xlsx, .csv, .txt)</label>
                        <input type="file" id="scrap_file" name="scrap_file" class="form-control" accept=".xlsx,.csv,.txt" required <?= $db_offline ? 'disabled' : '' ?>>
                    </div>
                    <div class="form-group">
                        <label for="mode">Modo de carga</label>
                        <select id="mode" name="mode" class="form-control" <?= $db_offline ? 'disabled' : '' ?>>
                            <option value="replace">Reemplazar todos los códigos (recomendado)</option>
                            <option value="append">Agregar registros</option>
                        </select>
                    </div>
                </div>
                <button type="submit" class="btn btn-primary" <?= $db_offline ? 'disabled' : '' ?>>Cargar códigos de scrap</button>
                <a href="setup.php" class="btn btn-secondary">Volver a Setup</a>
            </form>
        </div>
    </div>

    <?php if ($summary && $summary['errors']): ?>
        <div class="card">
            <div class="card-header"><h2>Errores de filas</h2></div>
            <div class="card-body"><ul><?php foreach ($summary['errors'] as $error): ?><li><?= htmlspecialchars($error) ?></li><?php endforeach; ?></ul></div>
        </div>
    <?php endif; ?>

    <?php if ($summary && !empty($summary['dup_list'])): ?>
        <div class="card">
            <div class="card-header"><h2>Duplicados omitidos (<?= number_format($summary['duplicates']) ?>)</h2></div>
            <div class="card-body">
                <p style="color:var(--muted);margin:0 0 10px;">Filas cuyos <b>4 valores</b> coinciden exactamente con otra ya cargada.</p>
                <ul><?php foreach ($summary['dup_list'] as $dup): ?><li class="im-mono"><?= htmlspecialchars($dup) ?></li><?php endforeach; ?></ul>
                <?php if ($summary['duplicates'] > count($summary['dup_list'])): ?>
                    <p style="color:var(--muted);">… y <?= number_format($summary['duplicates'] - count($summary['dup_list'])) ?> más.</p>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($preview): ?>
        <div class="card">
            <div class="card-header"><h2>Códigos cargados</h2></div>
            <div class="card-body table-wrapper">
                <table class="table">
                    <thead><tr><th>Language Key</th><th>Movement Type</th><th>Reason for Movement</th><th>Reason for Movement</th><th>Actualizado</th></tr></thead>
                    <tbody>
                    <?php foreach ($preview as $r): ?>
                        <tr>
                            <td><?= htmlspecialchars((string) ($r['language'] ?? '')) ?></td>
                            <td class="im-mono"><?= htmlspecialchars((string) ($r['mvt'] ?? '')) ?></td>
                            <td class="im-mono"><?= htmlspecialchars((string) ($r['reas'] ?? '')) ?></td>
                            <td><?= htmlspecialchars((string) ($r['reason'] ?? '')) ?></td>
                            <td><?= htmlspecialchars((string) ($r['imported_at'] ?? '')) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>
</div>
</body>
</html>
