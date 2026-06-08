<?php
declare(strict_types=1);

/**
 * Invitados 40: lista maestra (invites.jsonl) + confirmaciones web (rsvp.jsonl).
 */

function guests_data_dir(): string
{
    return __DIR__ . '/.data';
}

function guests_invites_path(): string
{
    return guests_data_dir() . '/invites.jsonl';
}

function guests_rsvp_path(): string
{
    return guests_data_dir() . '/rsvp.jsonl';
}

function guests_norm_name(string $s): string
{
    $s = mb_strtolower(trim($s), 'UTF-8');
    $map = [
        'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u',
        'ñ' => 'n', 'ü' => 'u',
    ];
    $s = strtr($s, $map);
    $s = preg_replace('/[^a-z0-9\s]/', '', $s) ?? '';
    $s = preg_replace('/\s+/', ' ', $s) ?? '';
    return trim($s);
}

/** @return list<string> */
function guests_phone_keys(string $s): array
{
    $d = preg_replace('/\D/', '', $s) ?? '';
    if ($d === '') {
        return [];
    }
    $keys = [$d];
    if (strlen($d) === 11 && str_starts_with($d, '1')) {
        $keys[] = substr($d, 1);
    }
    if (strlen($d) === 10) {
        $keys[] = '1' . $d;
    }
    return array_values(array_unique($keys));
}

function guests_phone_from_jid(string $jid): string
{
    if (preg_match('/^(\d+)@s\.whatsapp\.net$/', $jid, $m)) {
        return $m[1];
    }
    return '';
}

function guests_phone_from_label(string $label): string
{
    return preg_replace('/\D/', '', $label) ?? '';
}

/** @return list<array<string, mixed>> */
function guests_read_jsonl(string $path): array
{
    if (!is_readable($path)) {
        return [];
    }
    $rows = [];
    $fh = fopen($path, 'rb');
    if ($fh === false) {
        return [];
    }
    while (($line = fgets($fh)) !== false) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }
        $j = json_decode($line, true);
        if (is_array($j)) {
            $rows[] = $j;
        }
    }
    fclose($fh);
    return $rows;
}

/** @param list<array<string, mixed>> $rows */
function guests_write_jsonl(string $path, array $rows): bool
{
    $dir = dirname($path);
    if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
        return false;
    }
    $tmp = $path . '.tmp.' . bin2hex(random_bytes(4));
    $buf = '';
    foreach ($rows as $row) {
        $buf .= json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    }
    if (file_put_contents($tmp, $buf, LOCK_EX) === false) {
        return false;
    }
    return rename($tmp, $path);
}

/** @param array<string, mixed> $chat */
function guests_invite_from_wa_chat(array $chat): array
{
    $jid = (string) ($chat['jid'] ?? '');
    $name = trim((string) ($chat['chat_name'] ?? ''));
    $phone = guests_phone_from_jid($jid);
    if ($phone === '') {
        $phone = guests_phone_from_label($name);
    }
    $invitedAt = (string) ($chat['last_invite_at'] ?? '');
    if ($invitedAt !== '' && !str_contains($invitedAt, 'T')) {
        $invitedAt = gmdate('c', strtotime($invitedAt . ' UTC') ?: time());
    }

    return [
        'id' => 'wa:' . $jid,
        'source' => 'whatsapp',
        'name' => $name,
        'name_key' => guests_norm_name($name),
        'jid' => $jid,
        'chat_type' => (string) ($chat['chat_type'] ?? 'dm'),
        'invited_at' => $invitedAt,
        'phone' => $phone,
        'confirmed' => false,
        'confirmed_at' => null,
        'rsvp' => null,
        'reminder_sent_at' => null,
    ];
}

/** @param array<string, mixed> $rsvp */
function guests_rsvp_snapshot(array $rsvp): array
{
    return [
        'ts' => (string) ($rsvp['ts'] ?? ''),
        'name' => (string) ($rsvp['name'] ?? ''),
        'email' => (string) ($rsvp['email'] ?? ''),
        'phone' => (string) ($rsvp['phone'] ?? ''),
        'guests' => (string) ($rsvp['guests'] ?? '1'),
        'message' => (string) ($rsvp['message'] ?? ''),
        'status' => (string) ($rsvp['status'] ?? 'voy'),
    ];
}

/** @return list<string> */
function guests_name_words(string $s): array
{
    $parts = explode(' ', guests_norm_name($s));
    return array_values(array_filter($parts, static fn(string $w) => $w !== ''));
}

function guests_names_overlap(string $a, string $b): bool
{
    $wa = guests_name_words($a);
    $wb = guests_name_words($b);
    if ($wa === [] || $wb === []) {
        return false;
    }
    foreach ($wa as $w) {
        if (strlen($w) > 2 && in_array($w, $wb, true)) {
            return true;
        }
    }
    return false;
}

function guests_fuzzy_name_match(string $waName, string $rsvpName): bool
{
    $wa = guests_name_words($waName);
    $rb = guests_name_words($rsvpName);
    if ($wa === [] || $rb === []) {
        return false;
    }

    $nWa = guests_norm_name($waName);
    $nRsvp = guests_norm_name($rsvpName);
    if ($nWa === $nRsvp) {
        return true;
    }

    $waLast = $wa[count($wa) - 1];
    $rbLast = $rb[count($rb) - 1];
    $sharedSurname = ($waLast === $rbLast && strlen($waLast) > 2);

    if ($sharedSurname) {
        $waFirst = $wa[0];
        $rbFirst = $rb[0];
        if (str_starts_with($waFirst, $rbFirst) || str_starts_with($rbFirst, $waFirst)) {
            return true;
        }
    }

    if (str_starts_with($nRsvp, $nWa) || str_starts_with($nWa, $nRsvp)) {
        if (min(strlen($nWa), strlen($nRsvp)) >= 4) {
            return true;
        }
    }

    return false;
}

/**
 * @param list<array<string, mixed>> $invites
 * @return array<string, mixed>|null
 */
function guests_find_invite_for_rsvp(array $invites, array $rsvp): ?array
{
    $rsvpPhoneKeys = guests_phone_keys((string) ($rsvp['phone'] ?? ''));
    $rsvpNameKey = guests_norm_name((string) ($rsvp['name'] ?? ''));
    $rsvpName = (string) ($rsvp['name'] ?? '');

    if ($rsvpPhoneKeys !== []) {
        foreach ($invites as $inv) {
            $invKeys = guests_phone_keys((string) ($inv['phone'] ?? ''));
            if ($invKeys === []) {
                continue;
            }
            $phoneMatch = false;
            foreach ($rsvpPhoneKeys as $k) {
                if (in_array($k, $invKeys, true)) {
                    $phoneMatch = true;
                    break;
                }
            }
            if ($phoneMatch && guests_names_overlap((string) ($inv['name'] ?? ''), $rsvpName)) {
                return $inv;
            }
        }
    }

    if ($rsvpNameKey === '') {
        return null;
    }

    $candidates = [];
    foreach ($invites as $inv) {
        if (guests_norm_name((string) ($inv['name'] ?? '')) === $rsvpNameKey) {
            $candidates[] = $inv;
        }
    }
    if (count($candidates) === 1) {
        return $candidates[0];
    }

    $fuzzyCandidates = [];
    foreach ($invites as $inv) {
        if (!empty($inv['confirmed'])) {
            continue;
        }
        if (guests_fuzzy_name_match((string) ($inv['name'] ?? ''), $rsvpName)) {
            $fuzzyCandidates[] = $inv;
        }
    }
    if (count($fuzzyCandidates) === 1) {
        return $fuzzyCandidates[0];
    }

    return null;
}

/**
 * @param list<array<string, mixed>> $invites
 * @param list<array<string, mixed>> $rsvps
 * @return list<array<string, mixed>>
 */
function guests_apply_rsvps_to_invites(array $invites, array $rsvps): array
{
    $latestRsvpByName = [];
    foreach ($rsvps as $rsvp) {
        if (!isset($rsvp['name'])) {
            continue;
        }
        $nk = guests_norm_name((string) $rsvp['name']);
        if ($nk === '') {
            continue;
        }
        if (!isset($latestRsvpByName[$nk]) || strcmp((string) ($rsvp['ts'] ?? ''), (string) ($latestRsvpByName[$nk]['ts'] ?? '')) > 0) {
            $latestRsvpByName[$nk] = $rsvp;
        }
    }

    $usedInviteIds = [];

    foreach ($latestRsvpByName as $rsvp) {
        $match = guests_find_invite_for_rsvp($invites, $rsvp);
        if ($match === null) {
            continue;
        }
        $usedInviteIds[(string) $match['id']] = true;
        foreach ($invites as &$inv) {
            if ($inv['id'] !== $match['id']) {
                continue;
            }
            $inv['confirmed'] = true;
            $inv['confirmed_at'] = (string) ($rsvp['ts'] ?? gmdate('c'));
            $inv['rsvp'] = guests_rsvp_snapshot($rsvp);
            if (($inv['phone'] ?? '') === '' && trim((string) ($rsvp['phone'] ?? '')) !== '') {
                $keys = guests_phone_keys((string) $rsvp['phone']);
                $inv['phone'] = $keys[0] ?? '';
            }
            break;
        }
        unset($inv);
    }

    return $invites;
}

/**
 * @param array<string, mixed> $rsvp
 * @return bool
 */
function guests_confirm_from_rsvp(array $rsvp): bool
{
    $path = guests_invites_path();
    $invites = guests_read_jsonl($path);
    if ($invites === []) {
        return false;
    }

    $match = guests_find_invite_for_rsvp($invites, $rsvp);
    if ($match === null) {
        return false;
    }

    foreach ($invites as &$inv) {
        if ($inv['id'] !== $match['id']) {
            continue;
        }
        $inv['confirmed'] = true;
        $inv['confirmed_at'] = (string) ($rsvp['ts'] ?? gmdate('c'));
        $inv['rsvp'] = guests_rsvp_snapshot($rsvp);
        if (($inv['phone'] ?? '') === '' && trim((string) ($rsvp['phone'] ?? '')) !== '') {
            $keys = guests_phone_keys((string) $rsvp['phone']);
            $inv['phone'] = $keys[0] ?? '';
        }
        break;
    }
    unset($inv);

    $invites = guests_apply_couple_confirmations($invites);

    return guests_write_jsonl($path, $invites);
}

/**
 * @param array<string, mixed> $rsvp
 */
function guests_add_web_rsvp_invite(array $rsvp): bool
{
    $name = trim((string) ($rsvp['name'] ?? ''));
    if ($name === '') {
        return false;
    }
    $keys = guests_phone_keys((string) ($rsvp['phone'] ?? ''));
    $id = 'web:' . hash('sha256', guests_norm_name($name) . '|' . ($keys[0] ?? ''));

    $invites = guests_read_jsonl(guests_invites_path());
    foreach ($invites as &$inv) {
        if (($inv['id'] ?? '') !== $id) {
            continue;
        }
        $inv['confirmed'] = true;
        $inv['confirmed_at'] = (string) ($rsvp['ts'] ?? gmdate('c'));
        $inv['rsvp'] = guests_rsvp_snapshot($rsvp);
        unset($inv);
        return guests_write_jsonl(guests_invites_path(), $invites);
    }
    unset($inv);

    $invites[] = [
        'id' => $id,
        'source' => 'web',
        'name' => $name,
        'name_key' => guests_norm_name($name),
        'jid' => '',
        'chat_type' => 'dm',
        'invited_at' => (string) ($rsvp['ts'] ?? gmdate('c')),
        'phone' => $keys[0] ?? '',
        'confirmed' => true,
        'confirmed_at' => (string) ($rsvp['ts'] ?? gmdate('c')),
        'rsvp' => guests_rsvp_snapshot($rsvp),
        'reminder_sent_at' => null,
    ];

    return guests_write_jsonl(guests_invites_path(), $invites);
}

/**
 * @param list<array<string, mixed>> $invites
 * @param list<array<string, mixed>> $rsvps
 * @return list<array<string, mixed>>
 */
/** RSVPs absorbidos por parejas (no generan entrada huérfana). */
define('GUESTS_COUPLE_RSVP_NAMES', [
    'julio morillo',
    'carlos cueto',
    'stephanie de bengoa',
]);

function guests_append_orphan_rsvps(array $invites, array $rsvps): array
{
    $matchedRsvpNames = [];
    foreach (GUESTS_COUPLE_RSVP_NAMES as $cn) {
        $matchedRsvpNames[$cn] = true;
    }
    foreach ($invites as $inv) {
        if (empty($inv['confirmed']) || !is_array($inv['rsvp'] ?? null)) {
            continue;
        }
        $nk = guests_norm_name((string) ($inv['rsvp']['name'] ?? $inv['name'] ?? ''));
        if ($nk !== '') {
            $matchedRsvpNames[$nk] = true;
        }
    }

    $latest = [];
    foreach ($rsvps as $rsvp) {
        if (!isset($rsvp['name'])) {
            continue;
        }
        $nk = guests_norm_name((string) $rsvp['name']);
        if ($nk === '' || isset($matchedRsvpNames[$nk])) {
            continue;
        }
        if (!isset($latest[$nk]) || strcmp((string) ($rsvp['ts'] ?? ''), (string) ($latest[$nk]['ts'] ?? '')) > 0) {
            $latest[$nk] = $rsvp;
        }
    }

    foreach ($latest as $rsvp) {
        $keys = guests_phone_keys((string) ($rsvp['phone'] ?? ''));
        $invites[] = [
            'id' => 'web:' . hash('sha256', guests_norm_name((string) $rsvp['name']) . '|' . ($keys[0] ?? '')),
            'source' => 'web',
            'name' => (string) $rsvp['name'],
            'name_key' => guests_norm_name((string) $rsvp['name']),
            'jid' => '',
            'chat_type' => 'dm',
            'invited_at' => (string) ($rsvp['ts'] ?? ''),
            'phone' => $keys[0] ?? '',
            'confirmed' => true,
            'confirmed_at' => (string) ($rsvp['ts'] ?? ''),
            'rsvp' => guests_rsvp_snapshot($rsvp),
            'reminder_sent_at' => null,
        ];
    }

    return $invites;
}

/**
 * @return list<array<string, mixed>>
 */
function guests_load_merged(): array
{
    $invites = guests_read_jsonl(guests_invites_path());
    $rsvps = guests_read_jsonl(guests_rsvp_path());
    if ($invites === []) {
        return [];
    }
    $merged = guests_apply_rsvps_to_invites($invites, $rsvps);
    return guests_append_orphan_rsvps($merged, $rsvps);
}

/** @param array<string, mixed> $rsvp */
function guests_rsvp_key(array $rsvp): string
{
    return guests_norm_name((string) ($rsvp['name'] ?? '')) . '|' . (string) ($rsvp['ts'] ?? '');
}

/** @param array<string, mixed> $inv */
function guests_is_same_person_rsvp(array $inv): bool
{
    if (empty($inv['confirmed']) || !is_array($inv['rsvp'] ?? null)) {
        return false;
    }
    $invName = (string) ($inv['name'] ?? '');
    $rsvpName = (string) ($inv['rsvp']['name'] ?? '');
    if ($rsvpName === '') {
        return true;
    }
    if (guests_norm_name($invName) === guests_norm_name($rsvpName)) {
        return true;
    }

    return guests_fuzzy_name_match($invName, $rsvpName);
}

/** Parejas conocidas (name_key de cada miembro). Solo estas se agrupan en lista. */
define('GUESTS_KNOWN_COUPLE_PAIRS', [
    ['aida', 'carlos cueto'],
    ['erika fiallo', 'ricardo fiallo'],
    ['aida mendez', 'emmanuel ramos'],
    ['joseph cohen', 'maria alejandra gomez'],
    ['richard gonzalez', 'maria hache gidonni'],
    ['servando buonpensiere', 'patricia franch'],
    ['gustavo montalvo', 'daiany montalvo'],
    ['julio morillo', 'claudia leslie'],
    ['federico gonzalez', 'aimee diaz'],
    ['jose holguin', 'licelotte silfa'],
    ['andres cordero', 'paola perez'],
    ['wilfrido isidor', 'stephanie feliz'],
    ['ignacio bengoa aranguiz', 'stephanie vicioso'],
    ['patricia bengoa', 'francisco gratereaux'],
    ['laura bengoa', 'manuel martinez'],
    ['emmanuel jover', 'pamela gonzalez'],
    ['eduardo tejada', 'nathalie bermudez'],
    ['alan mejia', 'vivian purcell'],
    ['gabriel montoiro', 'danilda vargas'],
    ['emilio montoiro', 'maria garcia panadero'],
    ['mariano gonzalez', 'cynthia arias'],
    ['omar tejeda', 'odessa rodriguez'],
    ['joel gonzalez', 'natalia lama'],
    ['jonathan abreu', 'raiza jimenez'],
    ['angelo baiunco', 'laura ricardo'],
]);

function guests_name_matches_couple_key(string $name, string $key): bool
{
    if ($key === '') {
        return false;
    }
    $nName = guests_norm_name($name);
    $nKey = guests_norm_name($key);
    if ($nName === $nKey) {
        return true;
    }

    $keyWords = guests_name_words($key);
    if (count($keyWords) < 2) {
        return false;
    }

    $nameWords = guests_name_words($name);
    foreach ($keyWords as $w) {
        if (strlen($w) <= 2) {
            continue;
        }
        if (!in_array($w, $nameWords, true)) {
            return false;
        }
    }

    return true;
}

/** Coincidencia parcial (p. ej. RSVP "Ignacio Bengoa" vs contacto "Ignacio Bengoa Aranguiz"). */
function guests_name_matches_couple_key_loose(string $name, string $key): bool
{
    if (guests_name_matches_couple_key($name, $key)) {
        return true;
    }

    $nameWords = guests_name_words($name);
    $keyWords = guests_name_words($key);
    if ($nameWords === [] || $keyWords === []) {
        return false;
    }

    $matched = 0;
    foreach ($nameWords as $w) {
        if (strlen($w) <= 2) {
            continue;
        }
        if (in_array($w, $keyWords, true)) {
            $matched++;
        }
    }

    return $matched >= min(2, count($nameWords));
}

function guests_names_are_known_couple(string $nameA, string $nameB): bool
{
    if ($nameA === '' || $nameB === '') {
        return false;
    }
    foreach (GUESTS_KNOWN_COUPLE_PAIRS as $pair) {
        [$x, $y] = $pair;
        $aX = guests_name_matches_couple_key_loose($nameA, $x);
        $aY = guests_name_matches_couple_key_loose($nameA, $y);
        $bX = guests_name_matches_couple_key_loose($nameB, $x);
        $bY = guests_name_matches_couple_key_loose($nameB, $y);
        if (($aX && $bY) || ($aY && $bX)) {
            return true;
        }
    }

    return false;
}

/** Confirmado solo porque su pareja registró el RSVP (pareja conocida). */
function guests_is_couple_secondary(array $inv): bool
{
    if (empty($inv['confirmed']) || !is_array($inv['rsvp'] ?? null)) {
        return false;
    }
    if (guests_is_same_person_rsvp($inv)) {
        return false;
    }

    return guests_names_are_known_couple(
        (string) ($inv['name'] ?? ''),
        (string) ($inv['rsvp']['name'] ?? '')
    );
}

/**
 * @param list<array<string, mixed>> $invites
 * @return list<array<string, mixed>>
 */
function guests_apply_couple_confirmations(array $invites): array
{
    $now = gmdate('c');
    foreach (GUESTS_KNOWN_COUPLE_PAIRS as $pair) {
        [$keyA, $keyB] = $pair;
        $idxA = $idxB = null;
        foreach ($invites as $i => $inv) {
            if (guests_name_matches_couple_key((string) ($inv['name'] ?? ''), $keyA)) {
                $idxA = $i;
            }
            if (guests_name_matches_couple_key((string) ($inv['name'] ?? ''), $keyB)) {
                $idxB = $i;
            }
        }
        if ($idxA === null || $idxB === null) {
            continue;
        }
        $a = $invites[$idxA];
        $b = $invites[$idxB];
        if (!empty($a['confirmed']) && empty($b['confirmed']) && is_array($a['rsvp'] ?? null)) {
            $invites[$idxB]['confirmed'] = true;
            $invites[$idxB]['confirmed_at'] = $a['confirmed_at'] ?? $now;
            $invites[$idxB]['rsvp'] = $a['rsvp'];
        } elseif (!empty($b['confirmed']) && empty($a['confirmed']) && is_array($b['rsvp'] ?? null)) {
            $invites[$idxA]['confirmed'] = true;
            $invites[$idxA]['confirmed_at'] = $b['confirmed_at'] ?? $now;
            $invites[$idxA]['rsvp'] = $b['rsvp'];
        }
    }

    return $invites;
}

/**
 * @param list<array<string, mixed>> $invites
 * @return list<array<string, mixed>>
 */
function guests_for_display(array $invites): array
{
    return array_values(array_filter(
        $invites,
        static fn(array $inv): bool => !guests_is_couple_secondary($inv)
    ));
}

/**
 * @param list<array<string, mixed>> $invites
 * @return list<string>
 */
function guests_couple_partner_names(array $invites, array $primary): array
{
    if (!is_array($primary['rsvp'] ?? null)) {
        return [];
    }
    $key = guests_rsvp_key($primary['rsvp']);
    $names = [];
    foreach ($invites as $inv) {
        if (!guests_is_couple_secondary($inv) || !is_array($inv['rsvp'] ?? null)) {
            continue;
        }
        if (guests_rsvp_key($inv['rsvp']) !== $key) {
            continue;
        }
        $name = trim((string) ($inv['name'] ?? ''));
        if ($name !== '') {
            $names[] = $name;
        }
    }

    return $names;
}

/**
 * @param list<array<string, mixed>> $invites
 * @return array{confirmed: int, confirmed_guests: int, pending: int, total: int}
 */
function guests_stats(array $invites): array
{
    $confirmed = 0;
    $confirmedGuests = 0;
    $pending = 0;
    $seenRsvp = [];

    foreach ($invites as $inv) {
        if (!empty($inv['confirmed'])) {
            if (!guests_is_couple_secondary($inv)) {
                $confirmed++;
            }
            if (is_array($inv['rsvp'] ?? null)) {
                $key = guests_rsvp_key($inv['rsvp']);
                if (!isset($seenRsvp[$key])) {
                    $seenRsvp[$key] = true;
                    $g = isset($inv['rsvp']['guests']) ? (int) $inv['rsvp']['guests'] : 1;
                    $confirmedGuests += max(1, $g);
                }
            }
        } else {
            $pending++;
        }
    }

    return [
        'total' => count(guests_for_display($invites)),
        'confirmed' => $confirmed,
        'confirmed_guests' => $confirmedGuests,
        'pending' => $pending,
    ];
}

/**
 * Filas del reporte de reserva (solo confirmados, sin comentarios).
 *
 * @param list<array<string, mixed>> $allInvites
 * @return array{
 *   rows: list<array{primary: string, companions: string, guests: int}>,
 *   reservations: int,
 *   total_guests: int
 * }
 */
function guests_reservation_report(array $allInvites): array
{
    $display = guests_for_display($allInvites);
    $rows = [];

    foreach ($display as $inv) {
        if (empty($inv['confirmed'])) {
            continue;
        }
        $guests = 1;
        if (is_array($inv['rsvp'] ?? null)) {
            $guests = max(1, (int) ($inv['rsvp']['guests'] ?? 1));
        }
        $partners = guests_couple_partner_names($allInvites, $inv);
        $rows[] = [
            'primary' => trim((string) ($inv['name'] ?? '')),
            'companions' => $partners !== [] ? implode(', ', $partners) : '—',
            'guests' => $guests,
        ];
    }

    usort($rows, static fn(array $a, array $b): int => strcasecmp($a['primary'], $b['primary']));

    $totalGuests = array_sum(array_column($rows, 'guests'));

    return [
        'rows' => $rows,
        'reservations' => count($rows),
        'total_guests' => $totalGuests,
    ];
}
