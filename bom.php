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

$bom_columns = [
    'Material', 'MRP', 'TLRT', 'IPT', 'PTF', 'Plant', 'Alternative BOM',
    'Material Description', 'Base quantity', 'BOM Level', 'Assembly',
    'Assembly Dec', 'Component', 'Component Description', 'Quantity', 'Unit',
];
$bom_table = 'bom_records';
$alerts = [];
$summary = null;
$row_count = 0;

if (!$db_offline) {
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS `$bom_table` (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            material VARCHAR(100) NULL,
            mrp VARCHAR(100) NULL,
            tlrt VARCHAR(100) NULL,
            ipt VARCHAR(100) NULL,
            ptf VARCHAR(100) NULL,
            plant VARCHAR(100) NULL,
            alternative_bom VARCHAR(100) NULL,
            material_description TEXT NULL,
            base_quantity DECIMAL(20,6) NULL,
            bom_level VARCHAR(100) NULL,
            assembly VARCHAR(100) NULL,
            assembly_dec TEXT NULL,
            component VARCHAR(100) NULL,
            component_description TEXT NULL,
            quantity DECIMAL(20,6) NULL,
            unit VARCHAR(40) NULL,
            imported_by VARCHAR(150) NULL,
            imported_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_bom_material (material),
            KEY idx_bom_component (component)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $row_count = (int) $pdo->query("SELECT COUNT(*) FROM `$bom_table`")->fetchColumn();
    } catch (PDOException $e) {
        $alerts[] = ['danger', 'No se pudo preparar la tabla BOM: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8')];
    }
}

if (isset($_GET['template'])) {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="bom_template.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, $bom_columns, ',', '"', '');
    fclose($out);
    exit;
}

if (!$db_offline && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!admin_check_csrf()) {
            throw new RuntimeException('Token de seguridad inválido. Recarga la página e intenta de nuevo.');
        }
        $file = $_FILES['bom_file'] ?? null;
        if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Selecciona un archivo BOM válido para cargar.');
        }
        if (!is_uploaded_file($file['tmp_name'])) {
            throw new RuntimeException('El archivo recibido no es válido.');
        }
        $ext = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, item_master_allowed_extensions(), true)) {
            throw new RuntimeException('Formato no soportado. Usa .xlsx, .csv o .txt.');
        }

        $header_map = [];
        foreach ($bom_columns as $index => $label) {
            $header_map[item_master_normalize($label)] = $index;
        }
        $db_names = [
            'material', 'mrp', 'tlrt', 'ipt', 'ptf', 'plant', 'alternative_bom',
            'material_description', 'base_quantity', 'bom_level', 'assembly',
            'assembly_dec', 'component', 'component_description', 'quantity', 'unit',
        ];
        $mapping = null;
        $inserted = 0;
        $skipped = 0;
        $errors = [];
        $line_no = 0;
        $mode = ($_POST['mode'] ?? 'append') === 'replace' ? 'replace' : 'append';
        $user = (string) ($_SESSION['user']['mail'] ?? ($_SESSION['user']['username'] ?? 'sistema'));

        $pdo->beginTransaction();
        if ($mode === 'replace') {
            $pdo->exec("DELETE FROM `$bom_table`");
        }
        $stmt = null;
        foreach (item_master_read_file($file['tmp_name'], $ext) as $row) {
            $line_no++;
            if ($mapping === null) {
                if (!array_filter($row, static fn($value) => trim((string) $value) !== '')) {
                    continue;
                }
                $mapping = [];
                foreach ($row as $idx => $heading) {
                    $key = item_master_normalize((string) $heading);
                    if (isset($header_map[$key])) {
                        $mapping[$header_map[$key]] = $idx;
                    }
                }
                if (count($mapping) !== count($bom_columns)) {
                    $found = [];
                    foreach ($mapping as $column_index => $_) {
                        $found[] = item_master_normalize($bom_columns[$column_index]);
                    }
                    $missing_labels = array_values(array_filter($bom_columns, static fn($label) => !in_array(item_master_normalize($label), $found, true)));
                    throw new RuntimeException('Faltan encabezados requeridos: ' . implode(', ', $missing_labels));
                }
                $insert_columns = array_merge($db_names, ['imported_by']);
                $stmt = $pdo->prepare(
                    "INSERT INTO `$bom_table` (`" . implode('`, `', $insert_columns) . '`) VALUES ('
                    . implode(', ', array_fill(0, count($insert_columns), '?')) . ')'
                );
                continue;
            }

            if (!array_filter($row, static fn($value) => trim((string) $value) !== '')) {
                $skipped++;
                continue;
            }
            $values = [];
            foreach ($db_names as $column_index => $db_name) {
                $value = trim((string) ($row[$mapping[$column_index]] ?? ''));
                if (in_array($db_name, ['base_quantity', 'quantity'], true)) {
                    $value = item_master_to_decimal($value);
                } elseif ($value === '') {
                    $value = null;
                }
                $values[] = $value;
            }
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
        $summary = ['processed' => $inserted, 'skipped' => $skipped, 'errors' => $errors];
        $alerts[] = ['success', sprintf('Carga BOM completada: %d registros agregados, %d omitidos.', $inserted, $skipped)];
        $row_count = (int) $pdo->query("SELECT COUNT(*) FROM `$bom_table`")->fetchColumn();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $alerts[] = ['danger', htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8')];
    }
}

$active_page = 'setup';
$page_subtitle = 'Carga de BOM';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cargar BOM — <?= htmlspecialchars(APP_NAME) ?></title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<?php require __DIR__ . '/partials/header.php'; ?>
<div class="container" style="max-width:1000px;">
    <h1 class="page-title">Carga de BOM (Bill of Material)</h1>
    <?php foreach ($alerts as [$type, $message]): ?>
        <div class="alert alert-<?= $type ?>"><?= $message ?></div>
    <?php endforeach; ?>
    <?php if ($db_offline): ?>
        <div class="alert alert-warning">Sin conexión a la base de datos; no es posible cargar el BOM.</div>
    <?php endif; ?>

    <div class="card">
        <div class="card-header"><h2>Importar archivo BOM</h2></div>
        <div class="card-body">
            <div class="alert alert-info">Formato: obtener del correo de <b>Master Data</b>, archivo <b>BOM multilevel</b>.</div>
            <p>La primera fila debe incluir estos 16 encabezados. Se aceptan en cualquier orden; deben coincidir por nombre.</p>
            <p class="im-mono"><?= htmlspecialchars(implode(' · ', $bom_columns)) ?></p>
            <p>Registros actuales: <b><?= number_format($row_count) ?></b></p>
            <a class="btn btn-info" href="bom.php?template=csv">Descargar plantilla CSV</a>
            <form method="post" enctype="multipart/form-data" style="margin-top:18px;">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(admin_csrf_token()) ?>">
                <div class="form-row">
                    <div class="form-group">
                        <label for="bom_file">Archivo (.xlsx, .csv, .txt)</label>
                        <input type="file" id="bom_file" name="bom_file" class="form-control" accept=".xlsx,.csv,.txt" required <?= $db_offline ? 'disabled' : '' ?>>
                    </div>
                    <div class="form-group">
                        <label for="mode">Modo de carga</label>
                        <select id="mode" name="mode" class="form-control" <?= $db_offline ? 'disabled' : '' ?>>
                            <option value="append">Agregar registros</option>
                            <option value="replace">Reemplazar todo el BOM</option>
                        </select>
                    </div>
                </div>
                <button type="submit" class="btn btn-primary" <?= $db_offline ? 'disabled' : '' ?>>Cargar BOM</button>
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
</div>
</body>
</html>