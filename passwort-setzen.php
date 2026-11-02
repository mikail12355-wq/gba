<?php
/**
 * Legt Benutzername und Passwort für den Admin-Bereich fest.
 * Aufruf im Projektordner:  php passwort-setzen.php
 * Danach "python3 build.py" ausführen und site/lib/zugang.php hochladen.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit("Nur im Terminal ausführen.\n");
}

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

$benutzer = frage('Benutzername: ');
if ($benutzer === '') {
    exit("Abgebrochen: Benutzername fehlt.\n");
}
$pw = frage('Passwort (mind. 12 Zeichen): ', true);
if (mb_strlen($pw) < 12) {
    exit("Abgebrochen: Das Passwort muss mindestens 12 Zeichen haben.\n");
}
if (frage('Passwort wiederholen: ', true) !== $pw) {
    exit("Abgebrochen: Die Passwörter stimmen nicht überein.\n");
}

$inhalt = "<?php\n// Zugang zum Admin-Bereich. Nicht von Hand bearbeiten:\n// im Projektordner \"php passwort-setzen.php\" ausführen.\n"
    . 'const ADMIN_USER = ' . var_export($benutzer, true) . ";\n"
    . 'const ADMIN_HASH = ' . var_export(password_hash($pw, PASSWORD_DEFAULT), true) . ";\n";

foreach ([__DIR__ . '/src/php/lib/zugang.php', __DIR__ . '/site/lib/zugang.php'] as $ziel) {
    if (is_dir(dirname($ziel))) {
        file_put_contents($ziel, $inhalt);
    }
}
echo "Zugang gespeichert. Jetzt site/lib/zugang.php auf den Server hochladen.\n";
