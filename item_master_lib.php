<?php
/**
 * item_master_lib.php — Tabla "Item Master": esquema, verificación y carga
 * desde archivos de Excel (.xlsx) o texto delimitado (.csv / .txt).
 *
 * El lector de .xlsx es nativo (ZipArchive + XMLReader): no requiere Composer
 * ni PhpSpreadsheet.
 */

const ITEM_MASTER_TABLE = 'item_master';

/**
 * Definición ordenada de columnas del archivo de origen.
 * [ etiqueta en el Excel, columna MySQL, tipo MySQL ]
 * Las etiquetas repetidas en el reporte de SAP (MS, Net weight, …) se guardan
 * con sufijo _2 respetando el orden de aparición.
 */
function item_master_columns(): array {
    static $cols = [
        ['Material',            'material',            'VARCHAR(40)'],
        ['Material description','material_description','VARCHAR(255)'],
        ['BUn',                 'bun',                 'VARCHAR(10)'],
        ['MS',                  'ms',                  'VARCHAR(10)'],
        ['Product hierarchy',   'product_hierarchy',   'VARCHAR(60)'],
        ['Clt',                 'clt',                 'VARCHAR(10)'],
        ['Type',                'type',                'VARCHAR(20)'],
        ['Pl',                  'pl',                  'VARCHAR(10)'],
        ['ProcType',            'proc_type',           'VARCHAR(10)'],
        ['SPT',                 'spt',                 'VARCHAR(10)'],
        ['MS',                  'ms_2',                'VARCHAR(10)'],
        ['Matl group',          'matl_group',          'VARCHAR(30)'],
        ['LGrp',                'lgrp',                'VARCHAR(10)'],
        ['TGroup',              'tgroup',              'VARCHAR(20)'],
        ['Ctrlr',               'ctrlr',               'VARCHAR(20)'],
        ['Safety stock',        'safety_stock',        'DECIMAL(18,3)'],
        ['CC',                  'cc',                  'VARCHAR(10)'],
        ['SLoc',                'sloc',                'VARCHAR(10)'],
        ['ESLoc',               'esloc',               'VARCHAR(10)'],
        ['Typ',                 'typ',                 'VARCHAR(10)'],
        ['PDT',                 'pdt',                 'VARCHAR(20)'],
        ['Min.lot size',        'min_lot_size',        'DECIMAL(18,3)'],
        ['Min.lot size',        'min_lot_size_2',      'VARCHAR(20)'],
        ['CommCode/ImpCodNo',   'comm_code',           'VARCHAR(40)'],
        ['Name',                'name',                'VARCHAR(150)'],
        ['Unit Cost',           'unit_cost',           'DECIMAL(18,5)'],
        ['Net weight',          'net_weight',          'DECIMAL(18,5)'],
        ['Net weight',          'net_weight_2',        'VARCHAR(20)'],
        ['Gross weight',        'gross_weight',        'DECIMAL(18,5)'],
        ['Gross weight',        'gross_weight_2',      'VARCHAR(20)'],
        ['Ind. std descr.',     'ind_std_descr',       'VARCHAR(150)'],
        ['ABC',                 'abc',                 'VARCHAR(10)'],
        ['Orig SC',             'orig_sc',             'VARCHAR(20)'],
        ['LT',                  'lt',                  'VARCHAR(20)'],
        ['Rounding val.',       'rounding_val',        'DECIMAL(18,3)'],
        ['Rounding val.',       'rounding_val_2',      'VARCHAR(20)'],
        ['MRP LS',              'mrp_ls',              'VARCHAR(10)'],
        ['OUn',                 'oun',                 'VARCHAR(10)'],
        ['IPT',                 'ipt',                 'VARCHAR(20)'],
        ['Planning time fence', 'planning_time_fence', 'VARCHAR(20)'],
        ['PGr',                 'pgr',                 'VARCHAR(10)'],
        ['SMKey',               'sm_key',              'VARCHAR(10)'],
        ['Reorder point',       'reorder_point',       'DECIMAL(18,3)'],
        ['Reorder point',       'reorder_point_2',     'VARCHAR(20)'],
        ['Av',                  'av',                  'VARCHAR(10)'],
        ['TRLT',                'trlt',                'VARCHAR(20)'],
        ['PV key',              'pv_key',              'VARCHAR(20)'],
        ['WUn',                 'wun',                 'VARCHAR(10)'],
        ['B',                   'b',                   'VARCHAR(10)'],
        ['Old material no.',    'old_material_no',     'VARCHAR(60)'],
        ['Crcy',                'crcy',                'VARCHAR(10)'],
        ['Ext. Material Grp',   'ext_material_grp',    'VARCHAR(30)'],
        ['Created on',          'created_on',          'DATE'],
        ['Language',            'language',            'VARCHAR(10)'],
        ['RShLi',               'rshli',               'VARCHAR(20)'],
        ['SLife',               'slife',               'VARCHAR(20)'],
        ['Vend name',           'vend_name',           'VARCHAR(150)'],
        ['Profit ctr',          'profit_ctr',          'VARCHAR(30)'],
        ['MPN',                 'mpn',                 'VARCHAR(60)'],
        ['Pro. RM',             'pro_rm',              'VARCHAR(60)'],
        ['V',                   'v',                   'VARCHAR(10)'],
        ['I/C',                 'i_c',                 'VARCHAR(10)'],
        ['OA',                  'oa',                  'VARCHAR(10)'],
        ['Resch',               'resch',               'VARCHAR(20)'],
        ['MPP',                 'mpp',                 'VARCHAR(20)'],
        ['Bulk',                'bulk',                'VARCHAR(20)'],
        ['BU',                  'bu',                  'VARCHAR(30)'],
        ['OWNER',               'owner',               'VARCHAR(100)'],
        ['Inspection setup',    'inspection_setup',    'VARCHAR(60)'],
        ['NEWNOMEN',            'newnomen',            'VARCHAR(100)'],
    ];
    return $cols;
}

/** Normaliza un encabezado para poder comparar "Min.lot size" ≈ "min lot size". */
function item_master_normalize(string $label): string {
    $label = str_replace(["\xC2\xA0"], ' ', $label);
    $label = strtolower(trim($label));
    return preg_replace('/[^a-z0-9]+/', '', $label) ?? '';
}

/** ¿Existe ya la tabla item_master en la base de datos activa? */
function item_master_table_exists(PDO $pdo): bool {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
    ");
    $stmt->execute([ITEM_MASTER_TABLE]);
    return (int) $stmt->fetchColumn() > 0;
}

/** Columnas que realmente existen en la tabla (en minúsculas), por si falta alguna por falta de privilegio ALTER. */
function item_master_existing_columns(PDO $pdo): array {
    $stmt = $pdo->prepare("
        SELECT COLUMN_NAME FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
    ");
    $stmt->execute([ITEM_MASTER_TABLE]);
    $cols = [];
    foreach ($stmt->fetchAll() as $row) {
        $cols[strtolower($row['COLUMN_NAME'])] = true;
    }
    return $cols;
}

/** Crea la tabla si no existe y agrega columnas faltantes en instalaciones previas. */
function item_master_ensure_table(PDO $pdo): void {
    $defs = [];
    foreach (item_master_columns() as [$label, $col, $type]) {
        $defs[] = "`$col` $type NULL";
    }

    $pdo->exec('
        CREATE TABLE IF NOT EXISTS `' . ITEM_MASTER_TABLE . '` (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            ' . implode(",\n            ", $defs) . ',
            imported_by VARCHAR(150) NULL,
            imported_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_item_master_material (material),
            KEY idx_item_master_bu (bu),
            KEY idx_item_master_type (type)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ');

    $existing = [];
    $stmt = $pdo->prepare("
        SELECT COLUMN_NAME FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
    ");
    $stmt->execute([ITEM_MASTER_TABLE]);
    foreach ($stmt->fetchAll() as $row) {
        $existing[strtolower($row['COLUMN_NAME'])] = true;
    }

    // Agrega todas las columnas faltantes en un solo ALTER (un único viaje de
    // red) en vez de uno por columna, que en la BD nube resultaba muy lento.
    $missing = [];
    foreach (item_master_columns() as [$label, $col, $type]) {
        if (!isset($existing[strtolower($col)])) {
            $missing[] = "ADD COLUMN `$col` $type NULL";
        }
    }
    if ($missing) {
        try {
            $pdo->exec('ALTER TABLE `' . ITEM_MASTER_TABLE . '` ' . implode(', ', $missing));
        } catch (PDOException $e) {
            // Sin privilegio ALTER: seguir con el esquema actual en vez de tumbar la app.
            schema_warning('item_master_ensure_table: no se pudieron agregar columnas faltantes: ' . $e->getMessage());
        }
    }
}

/** Número de registros cargados. */
function item_master_count(PDO $pdo): int {
    return (int) $pdo->query('SELECT COUNT(*) FROM `' . ITEM_MASTER_TABLE . '`')->fetchColumn();
}

/* ───────────────────────── Lectura de archivos ───────────────────────── */

/** Extensiones aceptadas por el importador. */
function item_master_allowed_extensions(): array {
    return ['xlsx', 'csv', 'txt'];
}

/**
 * Recorre las filas del archivo sin cargarlo completo en memoria.
 * Lanza RuntimeException con un mensaje mostrable al usuario si falla.
 */
function item_master_read_file(string $path, string $ext): Generator {
    $ext = strtolower($ext);
    if ($ext === 'xlsx') {
        return item_master_read_xlsx($path);
    }
    if ($ext === 'csv' || $ext === 'txt') {
        return item_master_read_delimited($path);
    }
    throw new RuntimeException('Formato no soportado: .' . $ext);
}

/** Lee CSV / TXT detectando el separador (coma, punto y coma o tabulador). */
function item_master_read_delimited(string $path): Generator {
    $handle = fopen($path, 'rb');
    if ($handle === false) {
        throw new RuntimeException('No se pudo abrir el archivo cargado.');
    }

    $firstLine = fgets($handle);
    if ($firstLine === false) {
        fclose($handle);
        throw new RuntimeException('El archivo está vacío.');
    }

    $counts = [
        "\t" => substr_count($firstLine, "\t"),
        ';'  => substr_count($firstLine, ';'),
        ','  => substr_count($firstLine, ','),
        '|'  => substr_count($firstLine, '|'),
    ];
    arsort($counts);
    $delimiter = array_key_first($counts);
    if ($counts[$delimiter] === 0) {
        $delimiter = ',';
    }

    rewind($handle);
    try {
        $first = true;
        while (($data = fgetcsv($handle, 0, $delimiter, '"', '')) !== false) {
            if ($data === [null]) {
                continue;
            }
            $row = array_map(static fn($v) => item_master_clean_text((string) ($v ?? '')), $data);
            if ($first) {
                $row[0] = preg_replace('/^\xEF\xBB\xBF/', '', $row[0] ?? '');
                $first  = false;
            }
            yield $row;
        }
    } finally {
        fclose($handle);
    }
}

/** Convierte el texto a UTF-8 válido y recorta espacios. */
function item_master_clean_text(string $value): string {
    if (!mb_check_encoding($value, 'UTF-8')) {
        $value = mb_convert_encoding($value, 'UTF-8', 'Windows-1252');
    }
    return trim($value);
}

/** Lector nativo de .xlsx (primera hoja) usando ZipArchive + XMLReader. */
function item_master_read_xlsx(string $path): Generator {
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('La extensión PHP "zip" no está habilitada; guarda el archivo como .csv o activa extension=zip en php.ini.');
    }

    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        throw new RuntimeException('El archivo .xlsx no se pudo abrir (¿está dañado o es .xls antiguo?).');
    }

    $sheetPath = item_master_xlsx_first_sheet($zip);
    $shared    = item_master_xlsx_shared_strings($zip->getFromName('xl/sharedStrings.xml'));
    $stream    = $zip->getStream($sheetPath);
    if ($stream === false) {
        $zip->close();
        throw new RuntimeException('No se encontró la primera hoja dentro del archivo .xlsx.');
    }

    // Se descomprime a un archivo temporal para que XMLReader lo lea por partes.
    $tmp = tmpfile();
    if ($tmp === false) {
        fclose($stream);
        $zip->close();
        throw new RuntimeException('No se pudo crear el archivo temporal para leer la hoja.');
    }
    stream_copy_to_stream($stream, $tmp);
    fclose($stream);
    $zip->close();

    $tmpPath = stream_get_meta_data($tmp)['uri'];
    $reader  = new XMLReader();
    if (!$reader->open($tmpPath, 'UTF-8', LIBXML_NONET)) {
        throw new RuntimeException('No se pudo leer el contenido de la hoja de cálculo.');
    }

    try {
        // expand() hacia un DOMDocument conserva los namespaces heredados de la raíz
        // (p. ej. x14ac que Excel agrega a <row>), evitando filas leídas como vacías.
        $dom = new DOMDocument();
        while ($reader->read()) {
            if ($reader->nodeType !== XMLReader::ELEMENT || $reader->localName !== 'row') {
                continue;
            }
            $node = $reader->expand($dom);
            if (!$node instanceof DOMElement) {
                continue;
            }
            yield item_master_xlsx_row_values($node, $shared);
        }
    } finally {
        $reader->close();
        fclose($tmp);
    }
}

/** Ubica la ruta interna de la primera hoja declarada en el workbook. */
function item_master_xlsx_first_sheet(ZipArchive $zip): string {
    $workbook = $zip->getFromName('xl/workbook.xml');
    $rels     = $zip->getFromName('xl/_rels/workbook.xml.rels');
    if ($workbook !== false && $rels !== false) {
        $wb = @simplexml_load_string($workbook);
        $rl = @simplexml_load_string($rels);
        if ($wb !== false && $rl !== false) {
            $rid = null;
            foreach ($wb->sheets->sheet as $sheet) {
                $attrs = $sheet->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships');
                $rid   = (string) ($attrs['id'] ?? '');
                break;
            }
            if ($rid !== null && $rid !== '') {
                foreach ($rl->Relationship as $rel) {
                    if ((string) $rel['Id'] === $rid) {
                        $target = ltrim((string) $rel['Target'], '/');
                        return str_starts_with($target, 'xl/') ? $target : 'xl/' . $target;
                    }
                }
            }
        }
    }
    return 'xl/worksheets/sheet1.xml';
}

/** Tabla de cadenas compartidas del .xlsx. */
function item_master_xlsx_shared_strings($xml): array {
    if ($xml === false || $xml === null || $xml === '') {
        return [];
    }
    $reader = new XMLReader();
    if (!$reader->XML($xml, 'UTF-8', LIBXML_NOENT | LIBXML_NONET)) {
        return [];
    }

    $strings = [];
    while ($reader->read()) {
        if ($reader->nodeType === XMLReader::ELEMENT && $reader->localName === 'si') {
            $node = @simplexml_load_string($reader->readOuterXML());
            $text = '';
            if ($node !== false) {
                foreach ($node->xpath('.//*[local-name()="t"]') ?: [] as $t) {
                    $text .= (string) $t;
                }
            }
            $strings[] = $text;
        }
    }
    $reader->close();
    return $strings;
}

/** Extrae los valores de una fila <row>, respetando columnas vacías. */
function item_master_xlsx_row_values(DOMElement $row, array $shared): array {
    $values = [];
    $maxIdx = -1;
    foreach ($row->childNodes as $cell) {
        if (!$cell instanceof DOMElement || $cell->localName !== 'c') {
            continue;
        }
        $ref  = $cell->getAttribute('r');
        $type = $cell->getAttribute('t');
        $idx  = $ref !== '' ? item_master_col_index($ref) : $maxIdx + 1;

        if ($type === 'inlineStr') {
            $text = '';
            foreach ($cell->getElementsByTagName('*') as $t) {
                if ($t->localName === 't') {
                    $text .= $t->textContent;
                }
            }
        } elseif ($type === 's') {
            $pos  = 0;
            foreach ($cell->childNodes as $child) {
                if ($child instanceof DOMElement && $child->localName === 'v') {
                    $pos = (int) $child->textContent;
                    break;
                }
            }
            $text = $shared[$pos] ?? '';
        } else {
            $text = '';
            foreach ($cell->childNodes as $child) {
                if ($child instanceof DOMElement && $child->localName === 'v') {
                    $text = $child->textContent;
                    break;
                }
            }
        }

        $values[$idx] = item_master_clean_text($text);
        $maxIdx = max($maxIdx, $idx);
    }

    $out = [];
    for ($i = 0; $i <= $maxIdx; $i++) {
        $out[$i] = $values[$i] ?? '';
    }
    return $out;
}

/** "BC12" → índice 0-based de columna (54). */
function item_master_col_index(string $ref): int {
    $letters = preg_replace('/[^A-Za-z]/', '', $ref) ?? '';
    $index = 0;
    foreach (str_split(strtoupper($letters)) as $ch) {
        $index = $index * 26 + (ord($ch) - 64);
    }
    return max(0, $index - 1);
}

/* ───────────────────────── Importación ───────────────────────── */

/** Convierte texto a número o null. */
function item_master_to_decimal(string $value): ?float {
    $value = trim(str_replace([',', ' ', "\xC2\xA0", '$'], '', $value));
    if ($value === '' || !is_numeric($value)) {
        return null;
    }
    return (float) $value;
}

/** Acepta serial de Excel, dd.mm.yyyy, mm/dd/yyyy y yyyy-mm-dd. */
function item_master_to_date(string $value): ?string {
    $value = trim($value);
    if ($value === '') {
        return null;
    }
    if (ctype_digit($value) && (int) $value > 0 && (int) $value < 60000) {
        $base = new DateTimeImmutable('1899-12-30');
        return $base->modify('+' . (int) $value . ' days')->format('Y-m-d');
    }
    foreach (['d.m.Y', 'd/m/Y', 'm/d/Y', 'Y-m-d', 'd-m-Y', 'Y/m/d'] as $fmt) {
        $dt = DateTimeImmutable::createFromFormat($fmt, $value);
        if ($dt !== false && $dt->format($fmt) === $value) {
            return $dt->format('Y-m-d');
        }
    }
    $ts = strtotime($value);
    return $ts !== false ? date('Y-m-d', $ts) : null;
}

/**
 * Empareja los encabezados del archivo con las columnas esperadas.
 * Devuelve ['map' => [colIndex => dbColumn], 'missing' => [...], 'extra' => [...]].
 */
function item_master_map_headers(array $headerRow): array {
    $pending = [];
    foreach (item_master_columns() as [$label, $col, $type]) {
        $pending[item_master_normalize($label)][] = $col;
    }

    $map   = [];
    $extra = [];
    foreach ($headerRow as $idx => $label) {
        $label = trim((string) $label);
        if ($label === '') {
            continue;
        }
        $key = item_master_normalize($label);
        if (!empty($pending[$key])) {
            $map[$idx] = array_shift($pending[$key]);
        } else {
            $extra[] = $label;
        }
    }

    $missing = [];
    foreach ($pending as $cols) {
        foreach ($cols as $col) {
            $missing[] = $col;
        }
    }

    return ['map' => $map, 'missing' => $missing, 'extra' => $extra];
}

/**
 * Inserta/actualiza los registros leyendo el archivo fila por fila.
 * $mode: 'upsert' (actualiza por Material) o 'replace' (vacía la tabla antes).
 */
function item_master_import(PDO $pdo, iterable $rows, string $mode, string $user): array {
    $types = [];
    foreach (item_master_columns() as [$label, $col, $type]) {
        $types[$col] = $type;
    }

    $headerRow = null;
    $mapping   = null;
    $map       = [];
    $stmt      = null;

    $inserted = 0;
    $skipped  = 0;
    $errors   = [];
    $lineNo   = 0;
    $started  = false;

    try {
        foreach ($rows as $row) {
            $lineNo++;

            if ($headerRow === null) {
                if (!array_filter($row, static fn($v) => trim((string) $v) !== '')) {
                    continue;
                }
                $headerRow = $row;
                $mapping   = item_master_map_headers($headerRow);
                $map       = $mapping['map'];
                if (!in_array('material', $map, true)) {
                    throw new RuntimeException('El archivo no tiene la columna "Material", que es obligatoria.');
                }

                $insertCols   = array_merge(array_values($map), ['imported_by']);
                $placeholders = implode(', ', array_fill(0, count($insertCols), '?'));
                $updates      = [];
                foreach ($insertCols as $col) {
                    if ($col !== 'material') {
                        $updates[] = "`$col` = VALUES(`$col`)";
                    }
                }

                $stmt = $pdo->prepare(
                    'INSERT INTO `' . ITEM_MASTER_TABLE . '` (`' . implode('`, `', $insertCols) . '`)
                     VALUES (' . $placeholders . ')
                     ON DUPLICATE KEY UPDATE ' . implode(', ', $updates)
                );

                $pdo->beginTransaction();
                $started = true;
                if ($mode === 'replace') {
                    $pdo->exec('DELETE FROM `' . ITEM_MASTER_TABLE . '`');
                }
                continue;
            }

            $values   = [];
            $material = '';

            foreach ($map as $idx => $col) {
                $raw  = item_master_clean_text((string) ($row[$idx] ?? ''));
                $type = $types[$col] ?? 'VARCHAR(50)';

                if (str_starts_with($type, 'DECIMAL')) {
                    $values[] = item_master_to_decimal($raw);
                } elseif ($type === 'DATE') {
                    $values[] = item_master_to_date($raw);
                } else {
                    if (preg_match('/VARCHAR\((\d+)\)/', $type, $m)) {
                        $raw = mb_substr($raw, 0, (int) $m[1]);
                    }
                    $values[] = $raw === '' ? null : $raw;
                }

                if ($col === 'material') {
                    $material = $raw;
                }
            }

            if (trim($material) === '') {
                $skipped++;
                continue;
            }

            $values[] = $user;
            try {
                $stmt->execute($values);
                $inserted++;
            } catch (PDOException $e) {
                $skipped++;
                if (count($errors) < 10) {
                    $errors[] = 'Fila ' . $lineNo . ': ' . $e->getMessage();
                }
            }
        }

        if ($headerRow === null) {
            throw new RuntimeException('No se encontró la fila de encabezados.');
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($started && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    return [
        'processed' => $inserted,
        'skipped'   => $skipped,
        'missing'   => $mapping['missing'] ?? [],
        'extra'     => $mapping['extra'] ?? [],
        'errors'    => $errors,
        'header'    => $headerRow,
    ];
}

/** Carpeta del servidor para archivos grandes (sin pasar por el límite de subida). */
function item_master_import_dir(): string {
    return __DIR__ . DIRECTORY_SEPARATOR . 'imports';
}

/** Archivos disponibles en la carpeta imports/, ordenados por fecha. */
function item_master_server_files(): array {
    $dir = item_master_import_dir();
    if (!is_dir($dir)) {
        return [];
    }

    $files = [];
    foreach (scandir($dir) ?: [] as $name) {
        $full = $dir . DIRECTORY_SEPARATOR . $name;
        if (!is_file($full)) {
            continue;
        }
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!in_array($ext, item_master_allowed_extensions(), true)) {
            continue;
        }
        $files[] = ['name' => $name, 'size' => filesize($full) ?: 0, 'mtime' => filemtime($full) ?: 0];
    }

    usort($files, static fn($a, $b) => $b['mtime'] <=> $a['mtime']);
    return $files;
}

/** Resuelve un nombre de archivo dentro de imports/ evitando salto de directorio. */
function item_master_server_file_path(string $name): string {
    $name = basename(trim($name));
    if ($name === '' || $name[0] === '.') {
        throw new RuntimeException('Nombre de archivo no válido.');
    }
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if (!in_array($ext, item_master_allowed_extensions(), true)) {
        throw new RuntimeException('Formato no soportado: .' . $ext);
    }

    $dir  = item_master_import_dir();
    $full = realpath($dir . DIRECTORY_SEPARATOR . $name);
    $base = realpath($dir);
    if ($full === false || $base === false || !str_starts_with($full, $base . DIRECTORY_SEPARATOR) || !is_file($full)) {
        throw new RuntimeException('El archivo no existe en la carpeta imports/.');
    }
    return $full;
}
