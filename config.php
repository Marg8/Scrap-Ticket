<?php
// ╔══════════════════════════════════════════════════════════════════════════╗
// ║ ⛔ ENTORNO MYSQL TEMPORAL — SE ELIMINARÁ cuando se indique                 ║
// ║    "borrar el entorno mysql temporal". Fuente: archivo .env.temp.          ║
// ║    Si USE_TEMP_DB=1, sus credenciales tienen PRIORIDAD sobre .env.local.   ║
// ╚══════════════════════════════════════════════════════════════════════════╝
$__temp_db_active = (function (): bool {
    $path = __DIR__ . '/.env.temp';
    if (!is_readable($path)) {
        return false;
    }
    $vars = [];
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $vars[trim($key)] = trim($value);
    }
    if (($vars['USE_TEMP_DB'] ?? '') !== '1') {
        return false;
    }
    // Si el archivo aún tiene los valores de ejemplo (placeholders) sin llenar,
    // ignorar el override para no romper la conexión principal/respaldo real.
    $host = $vars['DB_HOST'] ?? '';
    $pass = $vars['DB_PASS'] ?? '';
    if ($host === '' || str_contains($host, 'sqlXXX') || $pass === '' || str_contains($pass, 'PEGA_AQUI') || str_contains($pass, 'TU_PASSWORD_TEMPORAL')) {
        return false;
    }
    foreach ($vars as $key => $value) {
        if ($key === 'USE_TEMP_DB') {
            continue;
        }
        putenv($key . '=' . $value); // Forzar precedencia: el entorno temporal gana.
    }
    return true;
})();
define('TEMP_DB_ACTIVE', $__temp_db_active); // ⛔ TEMPORAL: eliminar junto con .env.temp.
unset($__temp_db_active);

// Load local env overrides (.env.local preferred, else .env) into getenv().
(function (): void {
    foreach (['/.env.local', '/.env'] as $file) {
        $path = __DIR__ . $file;
        if (!is_readable($path)) {
            continue;
        }
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            if (getenv($key) === false) {
                putenv($key . '=' . trim($value));
            }
        }
        break;
    }
})();

// Database configuration (env-driven, shared PFEP server / "materials" DB)
define('DB_HOST',    getenv('DB_HOST')    ?: 'localhost');
define('DB_PORT',    getenv('DB_PORT')    ?: '3306');
define('DB_NAME',    getenv('DB_NAME')    ?: 'materials');
define('DB_USER',    getenv('DB_USER')    ?: 'root');
define('DB_PASS',    getenv('DB_PASS') !== false ? getenv('DB_PASS') : 'L3aNnM43@ja!');
define('DB_SOCKET',  getenv('DB_SOCKET')  ?: '');
define('DB_CHARSET', 'utf8mb4');

// ─────────────────────────────────────────────────────────────
// Conexión de respaldo (desarrollo)
// Se usa automáticamente SOLO si la conexión principal (XAMPP) es rechazada,
// para poder seguir trabajando fuera del servidor. Configúrala en .env.local.
// Si DB_FALLBACK_HOST queda vacío, el respaldo se desactiva.
// ─────────────────────────────────────────────────────────────
define('DB_FALLBACK_HOST',   getenv('DB_FALLBACK_HOST')   ?: '');
define('DB_FALLBACK_PORT',   getenv('DB_FALLBACK_PORT')   ?: '3306');
define('DB_FALLBACK_NAME',   getenv('DB_FALLBACK_NAME')   ?: DB_NAME);
define('DB_FALLBACK_USER',   getenv('DB_FALLBACK_USER')   ?: 'root');
define('DB_FALLBACK_PASS',   getenv('DB_FALLBACK_PASS') !== false ? getenv('DB_FALLBACK_PASS') : '');
define('DB_FALLBACK_SOCKET', getenv('DB_FALLBACK_SOCKET') ?: '');

// ─────────────────────────────────────────────────────────────
// Active Directory / LDAP  (autenticación de red On-Premises)
// ─────────────────────────────────────────────────────────────
$ldap_host   = getenv('LDAP_HOST') ?: 'IP_O_NOMBRE_DEL_SERVIDOR_AD'; // ej. 192.168.1.10 o ad.miempresa.local
$ldap_port   = (int) (getenv('LDAP_PORT') ?: 389);                  // 389 = LDAP estándar, 636 = LDAPS
$ldap_domain = getenv('LDAP_DOMAIN') ?: 'miempresa.local';          // Dominio de la empresa

// Cuenta de servicio opcional para buscar usuarios por correo (panel admin).
// Si se deja vacía, el nombre se deriva del correo y el admin puede editarlo.
define('LDAP_BIND_USER', getenv('LDAP_BIND_USER') ?: '');
define('LDAP_BIND_PASS', getenv('LDAP_BIND_PASS') !== false ? getenv('LDAP_BIND_PASS') : '');

// Dominio de correo corporativo usado para mapear usuarios vía LDAP.
define('CORP_EMAIL_DOMAIN', getenv('CORP_EMAIL_DOMAIN') ?: 'littelfuse.com');

// ⛔ ENTORNO MYSQL TEMPORAL: correo autorizado para usar el switch de
// toggle_temp_db.php (no depende de la BD, así funciona aunque esté caída).
// Eliminar junto con .env.temp al indicar "borrar el entorno mysql temporal".
define('DEV_TOGGLE_EMAIL', getenv('DEV_TOGGLE_EMAIL') ?: 'mrodriguez17@littelfuse.com');

// Login de prueba sin AD (solo para desarrollo). Activar con DEMO_LOGIN=1 en .env.local
define('DEMO_LOGIN', getenv('DEMO_LOGIN') === '1');

// Application settings
define('APP_NAME', 'Scrap Ticket System');
define('APP_VERSION', '1.0.0');

// Ticket status constants
define('STATUS_PENDING',            'pending');
define('STATUS_PARTIALLY_APPROVED', 'partially_approved');
define('STATUS_APPROVED',           'approved');
define('STATUS_REJECTED',           'rejected');

// Approval action constants
define('ACTION_PENDING',  'pending');
define('ACTION_APPROVED', 'approved');
define('ACTION_REJECTED', 'rejected');
