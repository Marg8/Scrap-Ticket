<?php
/**
 * ldap_lookup.php — Búsqueda de usuarios de Active Directory por correo.
 *
 * Requiere una cuenta de servicio (LDAP_BIND_USER / LDAP_BIND_PASS) para poder
 * consultar el directorio sin la contraseña del usuario final. Si no está
 * configurada o LDAP no está disponible, degrada de forma segura: deriva un
 * nombre legible a partir del correo para que el administrador lo ajuste.
 */
require_once __DIR__ . '/config.php';

/**
 * Busca un usuario por su correo corporativo.
 *
 * @return array{nombre_completo:string, username:string, mail:string, from_ldap:bool}
 */
function ldap_lookup_by_email(string $email): array {
    global $ldap_host, $ldap_port, $ldap_domain;

    $email = trim($email);
    $fallback = [
        'nombre_completo' => ldap_name_from_email($email),
        'username'        => explode('@', $email)[0] ?? '',
        'mail'            => $email,
        'from_ldap'       => false,
    ];

    if ($email === '' || !function_exists('ldap_connect') || LDAP_BIND_USER === '') {
        return $fallback;
    }

    $ldap = @ldap_connect($ldap_host, $ldap_port);
    if ($ldap === false) {
        return $fallback;
    }
    ldap_set_option($ldap, LDAP_OPT_PROTOCOL_VERSION, 3);
    ldap_set_option($ldap, LDAP_OPT_REFERRALS, 0);

    if (!@ldap_bind($ldap, LDAP_BIND_USER, LDAP_BIND_PASS)) {
        @ldap_unbind($ldap);
        return $fallback;
    }

    $base_dn = 'dc=' . implode(',dc=', explode('.', $ldap_domain));
    $filter  = '(mail=' . ldap_escape($email, '', LDAP_ESCAPE_FILTER) . ')';
    $search  = @ldap_search($ldap, $base_dn, $filter, ['displayname', 'mail', 'samaccountname']);

    $result = $fallback;
    if ($search !== false) {
        $entries = ldap_get_entries($ldap, $search);
        if (!empty($entries['count']) && isset($entries[0])) {
            $result = [
                'nombre_completo' => $entries[0]['displayname'][0]    ?? $fallback['nombre_completo'],
                'username'        => $entries[0]['samaccountname'][0]  ?? $fallback['username'],
                'mail'            => $entries[0]['mail'][0]             ?? $email,
                'from_ldap'       => true,
            ];
        }
    }

    @ldap_unbind($ldap);
    return $result;
}

/** Deriva un nombre legible desde el correo: juan.perez@x.com → "Juan Perez". */
function ldap_name_from_email(string $email): string {
    $local = explode('@', $email)[0] ?? '';
    $local = str_replace(['.', '_', '-'], ' ', $local);
    $local = trim(preg_replace('/\s+/', ' ', $local));
    return $local === '' ? $email : ucwords($local);
}
