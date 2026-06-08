<?php
declare(strict_types=1);

/**
 * Exporta PDF de reservas confirmadas para O'dile.
 * URL: https://jbengoa.com/40/lista-export.php
 */

require_once __DIR__ . '/guests-lib.php';
require_once __DIR__ . '/lib/reservation-pdf.php';

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
        session_destroy();
        header('Location: lista.php', true, 303);
        exit;
    }
    $_SESSION['rsvp40_at'] = time();
}

if (!isset($_SESSION['rsvp40_auth']) || $_SESSION['rsvp40_auth'] !== true) {
    header('Location: lista.php', true, 303);
    exit;
}

$allInvites = guests_load_merged();
$report = guests_reservation_report($allInvites);

$eventDate = 'Jueves 21 de mayo de 2026 · 8:00 PM';
$venue = "O'dile / O'dette";
$generated = (new DateTimeImmutable('now', new DateTimeZone('America/Santo_Domingo')))
    ->format('d/m/Y H:i');

$pdf = new ReservationPdf();
$pdf->addTitle('Reserva de invitados · Cumple 40');
$pdf->addText($venue);
$pdf->addText($eventDate);
$pdf->addText('Generado: ' . $generated);
$pdf->addText(sprintf(
    'Confirmaciones: %d reservas · %d personas',
    $report['reservations'],
    $report['total_guests']
));
$pdf->addText('');

$widths = [28.0, 170.0, 210.0, 56.0];
$pdf->addTableRow(['#', 'Invitado principal', 'Acompanantes', 'Pers.'], $widths, true);

$n = 1;
foreach ($report['rows'] as $row) {
    $pdf->addTableRow([
        (string) $n,
        $row['primary'],
        $row['companions'],
        (string) $row['guests'],
    ], $widths);
    $n++;
}

$pdf->addText('');
$pdf->addTableRow(['', 'TOTAL', '', (string) $report['total_guests']], $widths, true, 10);

$stamp = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d');
header('X-Robots-Tag: noindex, nofollow');
$pdf->output('reserva-odile-40-' . $stamp . '.pdf');
