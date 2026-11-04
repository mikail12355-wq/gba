<?php
/**
 * Richtet den Admin-Bereich der GitHub-Pages-Fassung ein.
 *
 * Fragt nach einem GitHub-Zugangsschlüssel (Fine-grained Token mit Schreibrecht auf
 * "Contents" für dieses Repo) und einem Admin-Passwort. Der Schlüssel wird mit dem
 * Passwort verschlüsselt (PBKDF2 + AES-256-GCM) und in src/static-admin/zugang.json
 * gespeichert. Nur wer das Passwort kennt, kann ihn auf der Admin-Seite entschlüsseln.
 *
 * Aufruf im Projektordner:  php admin-einrichten.php
 * Danach committen und pushen.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit("Nur im Terminal ausführen.\n");
}

const ITERATIONEN = 600000;

function frage(string $text, bool $versteckt = false): string
{
    echo $text;
    if ($versteckt) {
        shell_exec('stty -echo');
    }
    $antwort = trim((string)fgets(STDIN));
    if ($versteckt) {
        shell_exec('stty echo');
        echo "\n";
    }
    return $antwort;
}

function stopp(string $text): never
{
    fwrite(STDERR, "Abgebrochen: $text\n");
    exit(1);
}

// Repo aus dem Git-Remote lesen, z. B. https://github.com/name/gba.git -> name/gba
$remote = trim((string)shell_exec('git -C ' . escapeshellarg(__DIR__) . ' remote get-url origin 2>/dev/null'));
if (!preg_match('~github\.com[:/]([^/]+/[^/]+?)(?:\.git)?$~', $remote, $m)) {
    stopp('Kein GitHub-Remote gefunden. Ist das Projekt mit GitHub verbunden?');
}
$repo = $m[1];
echo "Repo: $repo\n\n";

$token = frage('GitHub-Zugangsschlüssel (Eingabe unsichtbar): ', true);
if ($token === '') {
    stopp('Kein Schlüssel eingegeben.');
}

// Schlüssel prüfen: braucht Schreibrecht auf das Repo
$antwort = @file_get_contents("https://api.github.com/repos/$repo", false, stream_context_create(['http' => [
    'header' => "Authorization: Bearer $token\r\nAccept: application/vnd.github+json\r\nUser-Agent: gba-admin-einrichten\r\n",
    'ignore_errors' => true,
]]));
$kopf = function_exists('http_get_last_response_headers') ? (http_get_last_response_headers() ?? []) : ($http_response_header ?? []);
$status = isset($kopf[0]) && preg_match('~\s(\d{3})\s~', $kopf[0], $s) ? (int)$s[1] : 0;
if ($status !== 200) {
    stopp("GitHub lehnt den Schlüssel ab (Status $status). Hat er Zugriff auf $repo?");
}
$info = json_decode((string)$antwort, true);
if (empty($info['permissions']['push'])) {
    stopp('Der Schlüssel darf nicht in das Repo schreiben. Bitte „Contents: Read and write“ erlauben.');
}
echo "Schlüssel geprüft: Schreibrecht auf $repo vorhanden.\n\n";

$pw = frage('Admin-Passwort (mind. 12 Zeichen): ', true);
if (mb_strlen($pw) < 12) {
    stopp('Das Passwort muss mindestens 12 Zeichen haben.');
}
if (frage('Passwort wiederholen: ', true) !== $pw) {
    stopp('Die Passwörter stimmen nicht überein.');
}

$salt = random_bytes(16);
$iv = random_bytes(12);
$schluessel = hash_pbkdf2('sha256', $pw, $salt, ITERATIONEN, 32, true);
$tag = '';
$geheim = openssl_encrypt($token, 'aes-256-gcm', $schluessel, OPENSSL_RAW_DATA, $iv, $tag, '', 16);
if ($geheim === false) {
    stopp('Verschlüsselung fehlgeschlagen.');
}

$zugang = [
    'hinweis' => 'Verschlüsselter GitHub-Schlüssel für den Admin-Bereich. Neu erzeugen mit: php admin-einrichten.php',
    'repo'    => $repo,
    'branch'  => trim((string)shell_exec('git -C ' . escapeshellarg(__DIR__) . ' branch --show-current')) ?: 'main',
    'iter'    => ITERATIONEN,
    'salt'    => base64_encode($salt),
    'iv'      => base64_encode($iv),
    'daten'   => base64_encode($geheim . $tag),
];
$ziel = __DIR__ . '/src/static-admin/zugang.json';
file_put_contents($ziel, json_encode($zugang, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");

echo "Gespeichert in src/static-admin/zugang.json.\n";
echo "Jetzt veröffentlichen:  git add src/static-admin/zugang.json && git commit -m \"Admin-Zugang eingerichtet\" && git push\n";
