<?php
declare(strict_types=1);

/**
 * Lista privada invitados 40: WhatsApp + confirmación web.
 * URL: https://jbengoa.com/40/lista.php
 */

require_once __DIR__ . '/guests-lib.php';

header('X-Robots-Tag: noindex, nofollow');

$dataDir = guests_data_dir();
$secretFile = $dataDir . '/list_view_secret';

session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/40/',
    'secure' => true,
    'httponly' => true,
    'samesite' => 'Strict',
]);
session_name('rsvp40lista');
session_start();

$idleMax = 1800;
if (isset($_SESSION['rsvp40_auth'], $_SESSION['rsvp40_at']) && $_SESSION['rsvp40_auth'] === true) {
    if (time() - (int) $_SESSION['rsvp40_at'] > $idleMax) {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        session_destroy();
        session_start();
    } else {
        $_SESSION['rsvp40_at'] = time();
    }
}

function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

function read_secret(string $secretFile): ?string
{
    if (!is_readable($secretFile)) {
        return null;
    }
    $raw = file_get_contents($secretFile);
    if ($raw === false) {
        return null;
    }
    $t = trim($raw);
    return $t !== '' ? $t : null;
}

function csrf_token(): string
{
    if (empty($_SESSION['rsvp40_csrf'])) {
        $_SESSION['rsvp40_csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['rsvp40_csrf'];
}

function verify_csrf(string $t): bool
{
    return isset($_SESSION['rsvp40_csrf']) && hash_equals($_SESSION['rsvp40_csrf'], $t);
}

$secret = read_secret($secretFile);
$authed = isset($_SESSION['rsvp40_auth']) && $_SESSION['rsvp40_auth'] === true;

if ($authed && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['logout'])) {
    if (verify_csrf((string) ($_POST['csrf'] ?? ''))) {
        $_SESSION = [];
        session_destroy();
        header('Location: lista.php', true, 303);
        exit;
    }
}

if (!$authed && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['clave'])) {
    if (!verify_csrf((string) ($_POST['csrf'] ?? ''))) {
        $error = 'Sesión expirada. Recarga la página.';
    } elseif ($secret === null) {
        $error = 'Acceso no configurado en el servidor (falta .data/list_view_secret).';
    } else {
        usleep(random_int(100000, 350000));
        $clave = (string) $_POST['clave'];
        if (hash_equals($secret, $clave)) {
            session_regenerate_id(true);
            $_SESSION['rsvp40_auth'] = true;
            $_SESSION['rsvp40_at'] = time();
            $_SESSION['rsvp40_csrf'] = bin2hex(random_bytes(16));
            header('Location: lista.php', true, 303);
            exit;
        }
        $error = 'Clave incorrecta.';
    }
}

header('Content-Type: text/html; charset=UTF-8');

if (!$authed) {
    $csrf = csrf_token();
    $msgSetup = $secret === null
        ? '<p class="warn">Aún no existe la clave en el servidor. Conéctate por SSH y crea el archivo <code>40/.data/list_view_secret</code> con una sola línea (tu clave).</p>'
        : '';
    echo '<!DOCTYPE html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<meta name="robots" content="noindex,nofollow"><title>Lista · privado</title>';
    echo '<style>body{font-family:system-ui,sans-serif;background:#0a0a0c;color:#e8dcc8;max-width:22rem;margin:3rem auto;padding:1.25rem;}';
    echo 'h1{font-size:1rem;letter-spacing:.15em;text-transform:uppercase;color:#c9a227;}';
    echo 'label{display:block;font-size:.75rem;margin-bottom:.35rem;color:#c9a227;}';
    echo 'input[type=password]{width:100%;box-sizing:border-box;padding:.65rem;border:1px solid rgba(201,162,39,.4);border-radius:8px;background:#050508;color:#f5f0e6;}';
    echo 'button{margin-top:1rem;width:100%;padding:.75rem;border:none;border-radius:8px;background:linear-gradient(180deg,#e8d48b,#c9a227);color:#0a0804;font-weight:600;cursor:pointer;}';
    echo '.err{color:#f0a8a8;font-size:.9rem;margin-top:.75rem;} .warn{color:#e8c48b;font-size:.85rem;line-height:1.4;} code{font-size:.8em;word-break:break-all;}</style></head><body>';
    echo '<h1>Acceso</h1>', $msgSetup;
    if ($secret !== null) {
        echo '<form method="post" autocomplete="current-password">';
        echo '<input type="hidden" name="csrf" value="', h($csrf), '">';
        echo '<label for="clave">Clave</label><input id="clave" name="clave" type="password" required autofocus>';
        echo '<button type="submit">Entrar</button></form>';
    }
    if (!empty($error)) {
        echo '<p class="err">', h($error), '</p>';
    }
    echo '</body></html>';
    exit;
}

$filter = (string) ($_GET['f'] ?? 'all');
if (!in_array($filter, ['all', 'confirmed', 'pending'], true)) {
    $filter = 'all';
}

$allInvites = guests_load_merged();
$invites = guests_for_display($allInvites);
usort($invites, static function (array $a, array $b): int {
    $ca = !empty($a['confirmed']);
    $cb = !empty($b['confirmed']);
    if ($ca !== $cb) {
        return $ca ? -1 : 1;
    }
    return strcasecmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
});

$filtered = array_values(array_filter($invites, static function (array $inv) use ($filter): bool {
    $ok = !empty($inv['confirmed']);
    if ($filter === 'confirmed') {
        return $ok;
    }
    if ($filter === 'pending') {
        return !$ok;
    }
    return true;
}));

$stats = guests_stats($allInvites);
$csrf = csrf_token();

echo '<!DOCTYPE html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
echo '<meta name="robots" content="noindex,nofollow"><title>Invitados · 40</title>';
echo '<style>';
echo 'body{font-family:Georgia,serif;background:#000;color:#e8dcc8;margin:0;padding:1rem 1.25rem 2rem;}';
echo 'h1{font-family:system-ui,sans-serif;font-size:1rem;letter-spacing:.12em;text-transform:uppercase;color:#c9a227;margin:0 0 .75rem;}';
echo '.stats{display:flex;flex-wrap:wrap;gap:.65rem;margin-bottom:1rem;}';
echo '.stat{padding:.55rem .85rem;border-radius:10px;border:1px solid rgba(201,162,39,.35);font-family:system-ui,sans-serif;font-size:.82rem;}';
echo '.stat strong{display:block;font-size:1.35rem;font-variant-numeric:tabular-nums;color:#e8d48b;}';
echo '.stat--pending{opacity:.45;border-color:rgba(120,120,120,.35);}';
echo '.stat--pending strong{color:#6a6458;}';
echo '.filters{display:flex;gap:.5rem;flex-wrap:wrap;margin-bottom:1rem;font-family:system-ui,sans-serif;font-size:.78rem;}';
echo '.filters a{color:#c9a227;text-decoration:none;padding:.35rem .65rem;border:1px solid rgba(201,162,39,.3);border-radius:6px;}';
echo '.filters a.active{background:rgba(201,162,39,.15);color:#fff;}';
echo 'table{width:100%;border-collapse:collapse;font-size:.88rem;}';
echo 'th{text-align:left;font-size:.68rem;letter-spacing:.08em;text-transform:uppercase;color:#c9a227;border-bottom:1px solid rgba(201,162,39,.35);padding:.5rem .4rem;}';
echo 'td{border-bottom:1px solid #1a1a1f;padding:.55rem .4rem;vertical-align:top;}';
echo 'tr:hover td{background:rgba(201,162,39,.06);}';
echo 'tr.row--pending{opacity:.42;}';
echo 'tr.row--pending td{color:#7a7268;}';
echo '.badge{display:inline-block;font-size:.65rem;letter-spacing:.06em;text-transform:uppercase;padding:.15rem .4rem;border-radius:4px;font-family:system-ui,sans-serif;}';
echo '.badge--ok{background:rgba(100,160,110,.2);color:#9dcda3;}';
echo '.badge--wait{background:rgba(100,100,100,.15);color:#8a8278;}';
echo '.num{text-align:right;font-variant-numeric:tabular-nums;}';
echo 'form.logout{margin-top:1.5rem;} form.logout button{font-size:.8rem;padding:.4rem .75rem;border:1px solid rgba(201,162,39,.5);background:transparent;color:#c9a227;border-radius:6px;cursor:pointer;}';
echo '.hint{font-size:.8rem;color:#9a8f80;margin-bottom:1rem;line-height:1.45;}';
echo '.actions{display:flex;flex-wrap:wrap;gap:.5rem;margin-bottom:1rem;align-items:center;font-family:system-ui,sans-serif;}';
echo '.actions a,.actions button.export{padding:.45rem .85rem;border:1px solid rgba(201,162,39,.45);border-radius:6px;background:rgba(201,162,39,.12);color:#e8d48b;text-decoration:none;font-size:.78rem;cursor:pointer;}';
echo '.actions a:hover,.actions button.export:hover{background:rgba(201,162,39,.22);color:#fff;}';
echo '</style></head><body>';
echo '<h1>Invitados · cumple 40</h1>';
echo '<p class="hint">Invitados por WhatsApp (<code>jbengoa.com/40</code>). Se marcan confirmados al registrarse en la web (nombre o teléfono coincidente).</p>';

echo '<div class="stats">';
echo '<div class="stat"><span>Confirmados</span><strong>', (int) $stats['confirmed'], '</strong><span>~', (int) $stats['confirmed_guests'], ' personas</span></div>';
echo '<div class="stat stat--pending"><span>Pendientes</span><strong>', (int) $stats['pending'], '</strong><span>sin registro web</span></div>';
echo '<div class="stat"><span>Total invitados</span><strong>', (int) $stats['total'], '</strong></div>';
echo '</div>';

echo '<div class="actions">';
echo '<a href="lista-export.php">Exportar PDF (O\'dile)</a>';
echo '</div>';

echo '<nav class="filters" aria-label="Filtros">';
foreach (['all' => 'Todos', 'confirmed' => 'Confirmados', 'pending' => 'Pendientes'] as $key => $label) {
    $active = $filter === $key ? ' class="active"' : '';
    echo '<a href="?f=', h($key), '"', $active, '>', h($label), '</a>';
}
echo '</nav>';

if ($filtered === []) {
    echo '<p>No hay registros en este filtro.</p>';
} else {
    echo '<table><thead><tr><th>Estado</th><th>Invitado</th><th>#</th><th>Contacto</th><th>Registro web</th><th>Nota</th></tr></thead><tbody>';
    foreach ($filtered as $inv) {
        $confirmed = !empty($inv['confirmed']);
        $rowClass = $confirmed ? '' : ' class="row--pending"';
        $badge = $confirmed
            ? '<span class="badge badge--ok">Confirmado</span>'
            : '<span class="badge badge--wait">Pendiente</span>';

        $name = h((string) ($inv['name'] ?? ''));
        $rsvp = is_array($inv['rsvp'] ?? null) ? $inv['rsvp'] : null;
        $guests = $rsvp ? h((string) ($rsvp['guests'] ?? '1')) : '—';
        $regTs = $rsvp ? h((string) ($rsvp['ts'] ?? '')) : '—';

        $contact = [];
        if ($rsvp && !empty($rsvp['email'])) {
            $contact[] = '<a href="mailto:' . h((string) $rsvp['email']) . '">' . h((string) $rsvp['email']) . '</a>';
        }
        $ph = $rsvp && !empty($rsvp['phone']) ? (string) $rsvp['phone'] : (string) ($inv['phone'] ?? '');
        if ($ph !== '') {
            $contact[] = h($ph);
        }
        $contactHtml = $contact === [] ? '—' : implode('<br>', $contact);

        $msg = $rsvp && trim((string) ($rsvp['message'] ?? '')) !== '' ? h((string) $rsvp['message']) : '';
        $partners = guests_couple_partner_names($allInvites, $inv);
        if ($partners !== []) {
            $partnerNote = '+ ' . implode(', ', $partners) . ' (pareja)';
            $msg = $msg !== '' ? $msg . ' · ' . $partnerNote : $partnerNote;
        }
        if ($msg === '') {
            $msg = '—';
        }

        echo '<tr', $rowClass, '><td>', $badge, '</td><td>', $name;
        if (($inv['chat_type'] ?? '') === 'group') {
            echo ' <span class="badge badge--wait">grupo</span>';
        }
        echo '</td><td class="num">', $guests, '</td><td>', $contactHtml, '</td><td class="num">', $regTs, '</td><td>', $msg, '</td></tr>';
    }
    echo '</tbody></table>';
}

echo '<form class="logout" method="post"><input type="hidden" name="csrf" value="', h($csrf), '"><button type="submit" name="logout" value="1">Cerrar sesión</button></form>';
echo '</body></html>';
