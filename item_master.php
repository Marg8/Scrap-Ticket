<?php
/**
 * item_master.php — Carga de la tabla Item Master desde Excel (.xlsx) o CSV/TXT.
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/admin_lib.php';
require_once __DIR__ . '/item_master_lib.php';

$pdo        = get_db_or_null();
$db_offline = $pdo === null;

if (!$db_offline) {
    admin_require_admin($pdo);
}

// Plantilla CSV con los encabezados exactos esperados.
if (isset($_GET['template'])) {
    $labels = array_map(static fn($c) => $c[0], item_master_columns());
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="item_master_template.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, $labels, ',', '"', '');
    fclose($out);
    exit;
}

const ITEM_MASTER_MAX_BYTES = 100 * 1024 * 1024;

/** "8M" / "512K" / "1G" del php.ini → bytes. */
function item_master_ini_bytes(string $key): int {
    $raw = trim((string) ini_get($key));
    if ($raw === '') {
        return 0;
    }
    $unit  = strtolower(substr($raw, -1));
    $value = (int) $raw;
    return match ($unit) {
        'g'     => $value * 1024 * 1024 * 1024,
        'm'     => $value * 1024 * 1024,
        'k'     => $value * 1024,
        default => $value,
    };
}

function item_master_human_bytes(int $bytes): string {
    if ($bytes <= 0) {
        return 'sin límite';
    }
    $units = ['B', 'KB', 'MB', 'GB'];
    $i = (int) floor(log($bytes, 1024));
    $i = min($i, count($units) - 1);
    return round($bytes / (1024 ** $i), 1) . ' ' . $units[$i];
}

$ini_upload = item_master_ini_bytes('upload_max_filesize');
$ini_post   = item_master_ini_bytes('post_max_size');
$ini_path   = php_ini_loaded_file() ?: '(no se detectó php.ini)';
// El tope real es el menor entre nuestro límite y los del servidor.
$effective_limit = min(array_filter([ITEM_MASTER_MAX_BYTES, $ini_upload, $ini_post]));

$alerts  = [];
$summary = null;

$table_exists = !$db_offline && item_master_table_exists($pdo);
$row_count    = $table_exists ? item_master_count($pdo) : 0;

if (!$db_offline && $_SERVER['REQUEST_METHOD'] === 'POST') {
    // Si el POST excede post_max_size, PHP entrega $_POST y $_FILES vacíos.
    if (empty($_POST) && empty($_FILES) && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        $alerts[] = ['danger', sprintf(
            'El envío (%s) superó <code>post_max_size</code> = %s y PHP descartó el archivo. '
            . 'Sube <code>post_max_size</code> y <code>upload_max_filesize</code> en <code>%s</code> y reinicia Apache.',
            item_master_human_bytes((int) $_SERVER['CONTENT_LENGTH']),
            htmlspecialchars((string) ini_get('post_max_size')),
            htmlspecialchars($ini_path)
        )];
    } elseif (!admin_check_csrf()) {
        $alerts[] = ['danger', 'Token de seguridad inválido. Recarga la página e intenta de nuevo.'];
    } else {
        $action = $_POST['action'] ?? '';

        try {
            if ($action === 'create_table') {
                item_master_ensure_table($pdo);
                $alerts[] = ['success', 'Tabla <code>item_master</code> verificada/creada correctamente.'];
            } elseif ($action === 'import') {
                $file = $_FILES['archivo'] ?? null;
                if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                    throw new RuntimeException('Selecciona un archivo para cargar.');
                }
                if ($file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE) {
                    throw new RuntimeException(sprintf(
                        'El archivo excede el límite de subida de PHP (upload_max_filesize = %s, post_max_size = %s). '
                        . 'Sube ambos valores en %s y reinicia el servidor web, '
                        . 'o copia el archivo a la carpeta imports/ y cárgalo desde ahí (sin límite de tamaño).',
                        ini_get('upload_max_filesize'), ini_get('post_max_size'), $ini_path
                    ));
                }
                if ($file['error'] !== UPLOAD_ERR_OK) {
                    throw new RuntimeException('Error al subir el archivo (código ' . (int) $file['error'] . ').');
                }
                if ($file['size'] > ITEM_MASTER_MAX_BYTES) {
                    throw new RuntimeException('El archivo supera el límite de ' . item_master_human_bytes(ITEM_MASTER_MAX_BYTES) . '.');
                }
                if (!is_uploaded_file($file['tmp_name'])) {
                    throw new RuntimeException('Archivo no válido.');
                }

                $ext = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
                if (!in_array($ext, item_master_allowed_extensions(), true)) {
                    throw new RuntimeException('Formato no soportado (.' . htmlspecialchars($ext) . '). Usa .xlsx, .csv o .txt. Los .xls antiguos deben guardarse como .xlsx.');
                }

                item_master_ensure_table($pdo);

                $mode = ($_POST['mode'] ?? 'upsert') === 'replace' ? 'replace' : 'upsert';
                $user = $_SESSION['user']['mail'] ?? ($_SESSION['user']['username'] ?? 'sistema');

                $rows    = item_master_read_file($file['tmp_name'], $ext);
                set_time_limit(0);
                $summary = item_master_import($pdo, $rows, $mode, (string) $user);

                $alerts[] = ['success', sprintf(
                    'Carga terminada: <b>%d</b> registros procesados, <b>%d</b> omitidos.',
                    $summary['processed'], $summary['skipped']
                )];
            } elseif ($action === 'import_server') {
                $path = item_master_server_file_path((string) ($_POST['server_file'] ?? ''));
                $ext  = strtolower(pathinfo($path, PATHINFO_EXTENSION));

                item_master_ensure_table($pdo);

                $mode = ($_POST['mode'] ?? 'upsert') === 'replace' ? 'replace' : 'upsert';
                $user = $_SESSION['user']['mail'] ?? ($_SESSION['user']['username'] ?? 'sistema');

                set_time_limit(0);
                $summary = item_master_import($pdo, item_master_read_file($path, $ext), $mode, (string) $user);

                $alerts[] = ['success', sprintf(
                    'Carga desde imports/ terminada: <b>%d</b> registros procesados, <b>%d</b> omitidos.',
                    $summary['processed'], $summary['skipped']
                )];
            }
        } catch (Throwable $e) {
            $alerts[] = ['danger', htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8')];
        }

        $table_exists = item_master_table_exists($pdo);
        $row_count    = $table_exists ? item_master_count($pdo) : 0;
    }
}

$preview = [];
if ($table_exists && $row_count > 0) {
    // Algunas columnas pueden faltar en producción por falta de privilegio ALTER;
    // se seleccionan solo las que realmente existen para no tumbar la página.
    $wanted   = ['material', 'material_description', 'bun', 'type', 'unit_cost', 'bu', 'owner', 'imported_at'];
    $existing = item_master_existing_columns($pdo);
    $cols     = array_values(array_filter($wanted, static fn($c) => isset($existing[$c])));
    if ($cols) {
        $preview = $pdo->query('
            SELECT `' . implode('`, `', $cols) . '`
            FROM `' . ITEM_MASTER_TABLE . '`
            ORDER BY ' . (isset($existing['imported_at']) ? 'imported_at DESC, ' : '') . 'id DESC
            LIMIT 25
        ')->fetchAll();
    }
}

$active_page   = 'setup';
$page_subtitle = 'Item Master — carga masiva desde Excel';
$server_files  = item_master_server_files();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Item Master — <?= htmlspecialchars(APP_NAME) ?></title>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .im-status { display: flex; gap: 18px; flex-wrap: wrap; margin-bottom: 16px; }
        .im-chip {
            border: 1px solid #d7dde3; border-radius: 6px; padding: 10px 14px;
            background: #f8fafc; font-size: 13px;
        }
        .im-chip b { display: block; font-size: 18px; margin-top: 2px; }
        .im-ok   { color: #155724; }
        .im-bad  { color: #721c24; }
        .im-cols { columns: 4; font-size: 12px; color: #444; margin: 0; padding-left: 18px; }
        .im-cols li { break-inside: avoid; }
        .im-mono { font-family: Consolas, monospace; font-size: 12px; }
    </style>
</head>
<body>
<?php include __DIR__ . '/partials/header.php'; ?>

<div class="container">
    <?php foreach ($alerts as [$type, $msg]): ?>
        <div class="alert alert-<?= $type ?>"><?= $msg ?></div>
    <?php endforeach; ?>

    <?php if ($db_offline): ?>
        <div class="alert alert-warning">Sin conexión a la base de datos: no es posible crear la tabla ni cargar archivos.</div>
    <?php endif; ?>

    <div class="card">
        <div class="card-header"><h2>Estado de la tabla <span class="im-mono">item_master</span></h2></div>
        <div class="card-body">
            <div class="im-status">
                <div class="im-chip">
                    Tabla en la base de datos
                    <b class="<?= $table_exists ? 'im-ok' : 'im-bad' ?>">
                        <?= $table_exists ? '✔ Existe' : '✘ No existe' ?>
                    </b>
                </div>
                <div class="im-chip">
                    Registros cargados
                    <b><?= number_format($row_count) ?></b>
                </div>
                <div class="im-chip">
                    Columnas definidas
                    <b><?= count(item_master_columns()) ?></b>
                </div>
            </div>

            <form method="post" style="display:inline">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(admin_csrf_token()) ?>">
                <input type="hidden" name="action" value="create_table">
                <button type="submit" class="btn btn-secondary" <?= $db_offline ? 'disabled' : '' ?>>
                    Verificar / crear tabla
                </button>
            </form>
            <a class="btn btn-info" href="item_master.php?template=csv">Descargar plantilla CSV</a>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><h2>Cargar archivo</h2></div>
        <div class="card-body">
            <div class="alert alert-info">
                <b>Formato:</b> obtener del correo de <b>Master Data</b>.
            </div>
            <div class="alert alert-info">
                <b>¿Qué formato usar?</b> Lo más recomendable es <b>.xlsx</b> (se lee directo, sin instalar nada)
                o <b>.csv</b> / <b>.txt</b> delimitado por tabulador. El formato antiguo <b>.xls</b> no es compatible:
                en Excel usa <i>Guardar como → Libro de Excel (*.xlsx)</i>.
                La primera fila debe contener los encabezados; el orden de las columnas no importa,
                se identifican por su nombre.
            </div>

            <div class="alert alert-warning">
                <b>Tamaño máximo real: <?= htmlspecialchars(item_master_human_bytes($effective_limit)) ?></b>
                &nbsp;— <span class="im-mono">upload_max_filesize = <?= htmlspecialchars((string) ini_get('upload_max_filesize')) ?></span>,
                <span class="im-mono">post_max_size = <?= htmlspecialchars((string) ini_get('post_max_size')) ?></span>,
                <span class="im-mono">memory_limit = <?= htmlspecialchars((string) ini_get('memory_limit')) ?></span>.
                <br>Para ampliarlo edita <span class="im-mono"><?= htmlspecialchars($ini_path) ?></span> y reinicia el servidor web.
                Si el archivo es aún más grande, usa la carga desde <span class="im-mono">imports/</span> (abajo), que no tiene límite.
            </div>

            <form method="post" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(admin_csrf_token()) ?>">
                <input type="hidden" name="MAX_FILE_SIZE" value="<?= (int) $effective_limit ?>">
                <input type="hidden" name="action" value="import">

                <div class="form-row">
                    <div class="form-group">
                        <label for="archivo">Archivo (.xlsx, .csv, .txt)</label>
                        <input type="file" id="archivo" name="archivo" class="form-control"
                               accept=".xlsx,.csv,.txt" required <?= $db_offline ? 'disabled' : '' ?>>
                        <div class="form-hint">Se lee la primera hoja del libro.</div>
                    </div>
                    <div class="form-group">
                        <label for="mode">Modo de carga</label>
                        <select id="mode" name="mode" class="form-control" <?= $db_offline ? 'disabled' : '' ?>>
                            <option value="upsert">Actualizar/agregar por Material (recomendado)</option>
                            <option value="replace">Reemplazar todo el contenido de la tabla</option>
                        </select>
                        <div class="form-hint">"Reemplazar" borra los registros actuales antes de cargar.</div>
                    </div>
                </div>

                <button type="submit" class="btn btn-primary" <?= $db_offline ? 'disabled' : '' ?>>Cargar a Item Master</button>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><h2>Archivos grandes: cargar desde la carpeta del servidor</h2></div>
        <div class="card-body">
            <div class="alert alert-info">
                Copia el archivo en
                <span class="im-mono"><?= htmlspecialchars(item_master_import_dir()) ?></span>
                y apárece en esta lista. Este método <b>no pasa por el navegador</b>, así que no le aplican
                <span class="im-mono">upload_max_filesize</span> ni <span class="im-mono">post_max_size</span>:
                sirve para archivos de cualquier tamaño.
            </div>

            <?php if (!$server_files): ?>
                <p class="form-hint">No hay archivos .xlsx, .csv ni .txt en la carpeta <span class="im-mono">imports/</span>.</p>
            <?php else: ?>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(admin_csrf_token()) ?>">
                <input type="hidden" name="action" value="import_server">
                <div class="form-row">
                    <div class="form-group">
                        <label for="server_file">Archivo en el servidor</label>
                        <select id="server_file" name="server_file" class="form-control" <?= $db_offline ? 'disabled' : '' ?>>
                            <?php foreach ($server_files as $f): ?>
                                <option value="<?= htmlspecialchars($f['name']) ?>">
                                    <?= htmlspecialchars($f['name']) ?> (<?= htmlspecialchars(item_master_human_bytes((int) $f['size'])) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="mode_server">Modo de carga</label>
                        <select id="mode_server" name="mode" class="form-control" <?= $db_offline ? 'disabled' : '' ?>>
                            <option value="upsert">Actualizar/agregar por Material (recomendado)</option>
                            <option value="replace">Reemplazar todo el contenido de la tabla</option>
                        </select>
                    </div>
                </div>
                <button type="submit" class="btn btn-success" <?= $db_offline ? 'disabled' : '' ?>>Cargar desde el servidor</button>
            </form>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($summary !== null): ?>
    <div class="card">
        <div class="card-header"><h2>Resultado de la última carga</h2></div>
        <div class="card-body">
            <p>Procesados: <b><?= (int) $summary['processed'] ?></b> &nbsp;|&nbsp;
               Omitidos: <b><?= (int) $summary['skipped'] ?></b></p>

            <?php if ($summary['missing']): ?>
                <div class="alert alert-warning">
                    Columnas esperadas que no venían en el archivo (quedaron vacías):
                    <span class="im-mono"><?= htmlspecialchars(implode(', ', $summary['missing'])) ?></span>
                </div>
            <?php endif; ?>

            <?php if ($summary['extra']): ?>
                <div class="alert alert-info">
                    Columnas del archivo que se ignoraron:
                    <span class="im-mono"><?= htmlspecialchars(implode(', ', $summary['extra'])) ?></span>
                </div>
            <?php endif; ?>

            <?php if ($summary['errors']): ?>
                <div class="alert alert-danger">
                    <b>Filas con error (primeras 10):</b>
                    <ul class="im-mono">
                        <?php foreach ($summary['errors'] as $err): ?>
                            <li><?= htmlspecialchars($err) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($preview): ?>
    <div class="card">
        <div class="card-header"><h2>Últimos registros cargados</h2></div>
        <div class="card-body table-wrapper">
            <table class="table">
                <thead>
                    <tr>
                        <th>Material</th><th>Descripción</th><th>BUn</th><th>Type</th>
                        <th>Unit Cost</th><th>BU</th><th>Owner</th><th>Cargado</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($preview as $r): ?>
                    <tr>
                        <td class="im-mono"><?= htmlspecialchars((string) ($r['material'] ?? '')) ?></td>
                        <td><?= htmlspecialchars((string) ($r['material_description'] ?? '')) ?></td>
                        <td><?= htmlspecialchars((string) ($r['bun'] ?? '')) ?></td>
                        <td><?= htmlspecialchars((string) ($r['type'] ?? '')) ?></td>
                        <td><?= ($r['unit_cost'] ?? null) !== null ? htmlspecialchars((string) $r['unit_cost']) : '' ?></td>
                        <td><?= htmlspecialchars((string) ($r['bu'] ?? '')) ?></td>
                        <td><?= htmlspecialchars((string) ($r['owner'] ?? '')) ?></td>
                        <td><?= htmlspecialchars((string) ($r['imported_at'] ?? '')) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <div class="card">
        <div class="card-header"><h3>Columnas esperadas en el archivo</h3></div>
        <div class="card-body">
            <ol class="im-cols">
                <?php foreach (item_master_columns() as [$label, $col, $type]): ?>
                    <li><?= htmlspecialchars($label) ?> <span class="im-mono">(<?= htmlspecialchars($col) ?>)</span></li>
                <?php endforeach; ?>
            </ol>
        </div>
    </div>
</div>
</body>
</html>
