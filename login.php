<?php
/**
 * login.php — Inicio de sesión contra Active Directory (LDAP) On-Premises.
 *
 * Flujo:
 *   1. Conecta al servidor AD con ldap_connect().
 *   2. Ajusta opciones (protocolo v3, sin referrals).
 *   3. Autentica (BIND) con formato usuario@dominio.local.
 *   4. Si el bind es correcto, busca displayName y mail y los guarda en sesión.
 *   5. Si falla, muestra "Usuario o contraseña corporativa incorrectos".
 */

require_once __DIR__ . '/config.php'; // Trae $ldap_host, $ldap_port, $ldap_domain

session_start();

$error = '';

// ── Cerrar sesión (destruye la sesión y regresa al formulario) ──
if (isset($_GET['logout'])) {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
    header('Location: login.php');
    exit;
}

// ── Modo demo (sin AD) — solo si está habilitado en config ──
// Usa el correo del admin sembrado para que el rol se resuelva correctamente
// mientras no hay acceso a LDAP (ver db.php → init_admin_schema).
if (DEMO_LOGIN && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['demo'])) {
    session_regenerate_id(true);
    $_SESSION['user'] = [
        'username'    => 'mrodriguez17',
        'displayName' => 'Mrodriguez17',
        'mail'        => 'mrodriguez17@littelfuse.com',
        'logged_at'   => date('Y-m-d H:i:s'),
        'demo'        => true,
    ];
    header('Location: login.php');
    exit;
}

// ── Procesar el envío del formulario ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username === '' || $password === '') {
        $error = 'Por favor ingresa tu usuario de red y tu contraseña.';
    } elseif (!function_exists('ldap_connect')) {
        // La extensión LDAP no está habilitada en el servidor.
        $error = 'El módulo LDAP de PHP no está instalado en el servidor. Instala "php-ldap" y reinicia el servicio.';
    } else {
        // 1. Conexión con el servidor de Active Directory.
        $ldap = @ldap_connect($ldap_host, $ldap_port);

        if ($ldap === false) {
            $error = 'No fue posible conectar con el servidor de Active Directory.';
        } else {
            // 2. Opciones requeridas para AD.
            ldap_set_option($ldap, LDAP_OPT_PROTOCOL_VERSION, 3);
            ldap_set_option($ldap, LDAP_OPT_REFERRALS, 0);

            // 3. Autenticación (BIND) con el formato usuario@dominio.
            $upn = $username . '@' . $ldap_domain;

            if (@ldap_bind($ldap, $upn, $password)) {
                // 4. Bind correcto → buscar datos del usuario (displayName y mail).
                //    El Base DN se deriva del dominio: miempresa.local → dc=miempresa,dc=local
                $base_dn = 'dc=' . implode(',dc=', explode('.', $ldap_domain));

                // Se escapa la entrada para evitar inyección de filtros LDAP.
                $filter  = '(sAMAccountName=' . ldap_escape($username, '', LDAP_ESCAPE_FILTER) . ')';
                $attrs   = ['displayname', 'mail', 'samaccountname'];

                $display = $username;
                $mail    = '';

                $search = @ldap_search($ldap, $base_dn, $filter, $attrs);
                if ($search !== false) {
                    $entries = ldap_get_entries($ldap, $search);
                    if (!empty($entries['count']) && isset($entries[0])) {
                        $display = $entries[0]['displayname'][0] ?? $username;
                        $mail    = $entries[0]['mail'][0]        ?? '';
                    }
                }

                // Guardar la información del usuario en la sesión.
                session_regenerate_id(true); // Previene fijación de sesión.
                $_SESSION['user'] = [
                    'username'    => $username,
                    'displayName' => $display,
                    'mail'        => $mail,
                    'logged_at'   => date('Y-m-d H:i:s'),
                ];

                @ldap_unbind($ldap);
                header('Location: login.php'); // Evita reenvío del POST al recargar.
                exit;
            } else {
                // 5. Bind fallido → credenciales inválidas.
                $error = 'Usuario o contraseña corporativa incorrectos.';
                @ldap_unbind($ldap);
            }
        }
    }
}

$user = $_SESSION['user'] ?? null;
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Iniciar sesión — <?= htmlspecialchars(APP_NAME) ?></title>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .login-wrap {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
        }
        .login-card {
            width: 100%;
            max-width: 400px;
            background: #fff;
            border: 1px solid var(--border);
            border-radius: var(--radius);
            box-shadow: 0 4px 20px rgba(27,94,32,.18);
            overflow: hidden;
        }
        .login-brand {
            text-align: center;
            padding: 28px 24px 12px;
        }
        .login-brand img { height: 46px; width: auto; }
        .login-brand h1 {
            font-size: 16px;
            margin-top: 12px;
            color: var(--primary-dark);
        }
        .login-body { padding: 12px 28px 28px; }
        .login-body .btn { width: 100%; justify-content: center; }
    </style>
</head>
<body>
<div class="login-wrap">
    <div class="login-card">
        <div class="login-brand">
            <img src="assets/images/Littelfuse.png" alt="Littelfuse">
            <h1><?= htmlspecialchars(APP_NAME) ?></h1>
        </div>
        <div class="login-body">

            <?php if ($user): ?>
                <!-- Usuario autenticado: mensaje de bienvenida -->
                <div class="alert alert-success">
                    ✔ ¡Bienvenido, <strong><?= htmlspecialchars($user['displayName']) ?></strong>!
                </div>
                <p style="font-size:13px;color:var(--muted);margin-bottom:4px;">
                    Usuario: <strong><?= htmlspecialchars($user['username']) ?></strong>
                </p>
                <?php if (!empty($user['mail'])): ?>
                    <p style="font-size:13px;color:var(--muted);">
                        Correo: <strong><?= htmlspecialchars($user['mail']) ?></strong>
                    </p>
                <?php endif; ?>

                <div style="display:flex;gap:10px;margin-top:18px;">
                    <a href="index.php" class="btn btn-primary" style="flex:1;justify-content:center;">Ir a la aplicación</a>
                    <a href="login.php?logout=1" class="btn btn-secondary" style="flex:1;justify-content:center;">Cerrar sesión</a>
                </div>

            <?php else: ?>
                <!-- Formulario de inicio de sesión -->
                <?php if ($error !== ''): ?>
                    <div class="alert alert-danger">✖ <?= htmlspecialchars($error) ?></div>
                <?php endif; ?>

                <form method="post" action="login.php" novalidate>
                    <div class="form-group">
                        <label for="username">Usuario de red / Windows</label>
                        <input type="text" id="username" name="username" class="form-control"
                               autocomplete="username" required autofocus
                               placeholder="ej. jperez"
                               value="<?= htmlspecialchars($_POST['username'] ?? '') ?>">
                    </div>
                    <div class="form-group">
                        <label for="password">Contraseña</label>
                        <input type="password" id="password" name="password" class="form-control"
                               autocomplete="current-password" required
                               placeholder="Contraseña corporativa">
                    </div>
                    <button type="submit" class="btn btn-primary">Iniciar sesión</button>
                    <?php if (DEMO_LOGIN): ?>
                        <button type="submit" name="demo" value="1" class="btn btn-secondary" formnovalidate style="margin-top:8px;">
                            Entrar en modo demo (sin AD)
                        </button>
                        <p style="font-size:11px;color:var(--muted);margin-top:8px;text-align:center;">
                            Modo demo activo — solo para desarrollo.
                        </p>
                    <?php endif; ?>
                </form>
            <?php endif; ?>

        </div>
    </div>
</div>
</body>
</html>
