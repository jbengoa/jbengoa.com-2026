<?php
declare(strict_types=1);

/**
 * RSVP → líneas JSON en .data/rsvp.jsonl (carpeta oculta; nginx en jbengoa.com niega `/.`).
 * Al guardar, intenta activar el invitado coincidente en invites.jsonl (nombre o teléfono).
 * Ver respuestas en privado: https://jbengoa.com/40/lista.php (clave en .data/list_view_secret).
 */

require_once __DIR__ . '/guests-lib.php';

header('Content-Type: application/json; charset=UTF-8');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => 'Method not allowed'], JSON_UNESCAPED_UNICODE);
    exit;
}

$referer = (string)($_SERVER['HTTP_REFERER'] ?? '');
if ($referer !== '' && stripos($referer, 'jbengoa.com') === false) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'Forbidden'], JSON_UNESCAPED_UNICODE);
    exit;
}

$raw = file_get_contents('php://input');
$payload = json_decode($raw, true);
if (!is_array($payload)) {
    $payload = $_POST;
}

// Honeypot: si está relleno, fingir éxito (anti-bots simple)
$hp = trim((string)($payload['website'] ?? ''));
if ($hp !== '') {
    echo json_encode(['ok' => true, 'message' => 'ok'], JSON_UNESCAPED_UNICODE);
    exit;
}

$name = trim((string)($payload['name'] ?? ''));
if ($name === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => 'Nombre requerido'], JSON_UNESCAPED_UNICODE);
    exit;
}

$email = trim((string)($payload['email'] ?? ''));
$phone = trim((string)($payload['phone'] ?? ''));
$status = trim((string)($payload['status'] ?? 'voy'));
$guests = trim((string)($payload['guests'] ?? '1'));
$message = trim((string)($payload['message'] ?? ''));
$source = trim((string)($payload['source'] ?? 'jbengoa.com/40'));

$allowed = ['voy', 'talvez', 'no'];
if (!in_array($status, $allowed, true)) {
    $status = 'voy';
}

$clamp = static function (string $s, int $max): string {
    if (function_exists('mb_substr')) {
        return mb_substr($s, 0, $max);
    }
    return $max > 0 ? substr($s, 0, $max) : '';
};

$record = [
    'ts' => gmdate('c'),
    'ip' => (string)($_SERVER['REMOTE_ADDR'] ?? ''),
    'name' => $clamp($name, 120),
    'email' => $clamp($email, 120),
    'phone' => $clamp($phone, 40),
    'status' => $status,
    'guests' => $clamp($guests, 10),
    'message' => $clamp($message, 500),
    'source' => $clamp($source, 80),
];

$dataDir = __DIR__ . '/.data';
if (!is_dir($dataDir)) {
    if (!mkdir($dataDir, 0700, true) && !is_dir($dataDir)) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'message' => 'No se pudo crear almacenamiento'], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

$file = $dataDir . '/rsvp.jsonl';
$line = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";

if (file_put_contents($file, $line, FILE_APPEND | LOCK_EX) === false) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'No se pudo guardar'], JSON_UNESCAPED_UNICODE);
    exit;
}

$matched = guests_confirm_from_rsvp($record);
if (!$matched) {
    guests_add_web_rsvp_invite($record);
}

echo json_encode(['ok' => true, 'message' => 'ok', 'matched_invite' => $matched], JSON_UNESCAPED_UNICODE);
