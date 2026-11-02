<?php
/**
 * Gemeinsame Funktionen für Mitteilungen: Datenbank, Textformat, Uploads, Ausgabe.
 */
declare(strict_types=1);

// Zugang liegt nicht im Git-Repo; ohne Datei ist der Admin-Bereich gesperrt.
if (is_file(__DIR__ . '/zugang.php')) {
    require __DIR__ . '/zugang.php';
}
if (!defined('ADMIN_USER')) {
    define('ADMIN_USER', '');
    define('ADMIN_HASH', '');
}

const GBA_ROOT   = __DIR__ . '/..';
const DATA_DIR   = GBA_ROOT . '/data';
const UPLOAD_DIR = GBA_ROOT . '/uploads';

/** Etiketten, die ein Beitrag tragen kann. "neu" wird rot hervorgehoben. */
const ETIKETTEN = [
    ''          => '— kein Etikett —',
    'neu'       => 'Neu',
    'gilt'      => 'Gilt weiterhin',
    'beschluss' => 'Vorstandsbeschluss',
    'info'      => 'Information',
];

const MAX_PDF_BYTES  = 10 * 1024 * 1024;
const MAX_BILD_BYTES = 8 * 1024 * 1024;
const BILD_TYPEN = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

function h(?string $s): string
{
    return htmlspecialchars($s ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo) {
        return $pdo;
    }
    if (!is_dir(DATA_DIR)) {
        mkdir(DATA_DIR, 0750, true);
    }
    $pdo = new PDO('sqlite:' . DATA_DIR . '/gba.sqlite', null, null, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS beitraege (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        datum TEXT NOT NULL,
        titel TEXT NOT NULL,
        rubrik TEXT NOT NULL DEFAULT '',
        etikett TEXT NOT NULL DEFAULT '',
        text TEXT NOT NULL,
        unterschrift TEXT NOT NULL DEFAULT '',
        pdf TEXT NOT NULL DEFAULT '',
        pdf_name TEXT NOT NULL DEFAULT '',
        bild TEXT NOT NULL DEFAULT '',
        bild_alt TEXT NOT NULL DEFAULT '',
        erstellt TEXT NOT NULL,
        geaendert TEXT NOT NULL
        )
        SQL);
    if ((int)$pdo->query('SELECT COUNT(*) FROM beitraege')->fetchColumn() === 0
        && !file_exists(DATA_DIR . '/.startdaten')) {
        startdaten($pdo);
        touch(DATA_DIR . '/.startdaten');
    }
    return $pdo;
}

/** Übernimmt einmalig die Mitteilungen der alten Webseite. */
function startdaten(PDO $pdo): void
{
    $beitraege = [
        ['2022-05-06', 'GBA-Tageslizenz für BDB-Lizenznehmer', 'Lizenzen · BDB', 'beschluss',
         "Der BDB verlangt seit Längerem von GBA-Lizenznehmern auf BDB-Veranstaltungen eine zusätzliche Tageslizenz. Der GBA-Vorstand hat dagegen ohne Erfolg protestiert.\n\nAb sofort erhebt die GBA ebenfalls eine Tageslizenz in Höhe von **40,00 €** für BDB-Lizenznehmer, die eine gültige Lizenzkarte, aber keine Startgenehmigung mitbringen.",
         'Der GBA-Vorstand · Jörg Milcke'],
        ['2022-06-14', 'Umlage für die Eintragung bei boxrec.com', 'boxrec.com · Kostenumlage', 'gilt',
         "Seit dem 27.03.2022 erhebt boxrec.com für jeden von der GBA bestätigten Kampf eine Verwaltungsgebühr von 10 USD zuzüglich Steuern und Gebühren. Die GBA geht dafür in Vorleistung.\n\nBeim Wiegen erhält der Promoter vom GBA-Delegierten einen Umlagebeleg und zahlt **12,00 € pro eingetragenem Kampf** in bar. Die Regelung gilt mit sofortiger Wirkung.",
         'Der GBA-Vorstand · Jörg Milcke'],
        ['2026-03-10', 'Jeder Kampf wird auf YouTube aufgezeichnet', 'boxrec.com · GBA-Veranstaltungen', 'neu',
         "Damit die Eintragungen in der Datenbank boxrec.com gesichert sind, gelten ab sofort zusätzliche Kriterien für alle GBA-Veranstaltungen:\n\n- Jeder Kampf wird vollständig aufgezeichnet und auf YouTube hochgeladen.\n- Das Video darf öffentlich oder nicht öffentlich sein.\n- Der YouTube-Link wird über die GBA an boxrec.com weitergeleitet.",
         'Der GBA-Vorstand'],
    ];
    $jetzt = date('c');
    $st = $pdo->prepare('INSERT INTO beitraege (datum, titel, rubrik, etikett, text, unterschrift, erstellt, geaendert)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
    foreach ($beitraege as $b) {
        $st->execute([...$b, $jetzt, $jetzt]);
    }
}

/** @return array<int, array<string, mixed>> neueste zuerst */
function beitraege(?int $limit = null): array
{
    $sql = 'SELECT * FROM beitraege ORDER BY datum DESC, id DESC' . ($limit ? ' LIMIT ' . (int)$limit : '');
    return db()->query($sql)->fetchAll();
}

function beitrag(int $id): ?array
{
    $st = db()->prepare('SELECT * FROM beitraege WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

function datum_de(string $iso): string
{
    $d = DateTime::createFromFormat('Y-m-d', $iso);
    return $d ? $d->format('d.m.Y') : h($iso);
}

/**
 * Einfaches Textformat: Leerzeile = neuer Absatz, Zeilen mit "- " = Liste, **fett**.
 * Alles wird zuerst maskiert, es kann also kein HTML eingeschleust werden.
 */
function text_html(string $text): string
{
    $text = str_replace("\r\n", "\n", trim($text));
    $out = '';
    foreach (preg_split("/\n\s*\n/", $text) as $block) {
        $zeilen = array_values(array_filter(array_map('trim', explode("\n", $block)), 'strlen'));
        if (!$zeilen) {
            continue;
        }
        $istListe = count(array_filter($zeilen, fn($z) => str_starts_with($z, '- '))) === count($zeilen);
        if ($istListe) {
            $out .= '<ul>' . implode('', array_map(fn($z) => '<li>' . inline_html(substr($z, 2)) . '</li>', $zeilen)) . '</ul>';
        } else {
            $out .= '<p>' . implode('<br>', array_map('inline_html', $zeilen)) . '</p>';
        }
    }
    return $out;
}

function inline_html(string $s): string
{
    return preg_replace('/\*\*(.+?)\*\*/u', '<strong>$1</strong>', h($s));
}

/** Erster Absatz als Kurztext für die Startseite. */
function kurztext(string $text, int $max = 220): string
{
    $erster = trim(preg_split("/\n\s*\n/", str_replace("\r\n", "\n", trim($text)))[0] ?? '');
    $erster = preg_replace('/^- /m', '', str_replace('**', '', $erster));
    $erster = preg_replace('/\s+/', ' ', $erster);
    return mb_strlen($erster) > $max ? rtrim(mb_substr($erster, 0, $max)) . ' …' : $erster;
}

function etikett_html(string $key, string $extraKlasse = ''): string
{
    if ($key === '' || !isset(ETIKETTEN[$key])) {
        return '';
    }
    $klasse = trim('tag ' . ($key === 'neu' ? '' : 'old ') . $extraKlasse);
    return '<span class="' . $klasse . '">' . h(ETIKETTEN[$key]) . '</span>';
}

/** Ein Beitrag in voller Länge, für die Seite "Aktuelles". */
function beitrag_html(array $b): string
{
    $o  = '<article class="entry" id="beitrag-' . (int)$b['id'] . '">';
    $o .= '<div class="when"><time datetime="' . h($b['datum']) . '">' . datum_de($b['datum']) . '</time>' . etikett_html($b['etikett']) . '</div>';
    $o .= '<div>';
    if ($b['rubrik'] !== '') {
        $o .= '<div class="eyebrow">' . h($b['rubrik']) . '</div>';
    }
    $o .= '<h2>' . h($b['titel']) . '</h2>';
    if ($b['bild'] !== '') {
        $o .= '<figure class="photo entry-photo"><img src="uploads/' . h($b['bild']) . '" alt="' . h($b['bild_alt']) . '" loading="lazy"></figure>';
    }
    $o .= text_html($b['text']);
    if ($b['pdf'] !== '') {
        $o .= '<a class="attachment" href="uploads/' . h($b['pdf']) . '" type="application/pdf"><span class="pdf">PDF</span> ' . h($b['pdf_name'] ?: 'Anhang öffnen') . '</a>';
    }
    if ($b['unterschrift'] !== '') {
        $o .= '<p class="sig">' . h($b['unterschrift']) . '</p>';
    }
    return $o . '</div></article>';
}

/** Speichert einen Upload nach Typprüfung unter zufälligem Namen. Gibt den Dateinamen zurück. */
function upload_speichern(array $datei, string $art): string
{
    if (($datei['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return '';
    }
    if ($datei['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($datei['tmp_name'])) {
        throw new RuntimeException('Die Datei konnte nicht hochgeladen werden. Ist sie vielleicht zu groß?');
    }
    $typ = (new finfo(FILEINFO_MIME_TYPE))->file($datei['tmp_name']);
    if ($art === 'pdf') {
        if ($typ !== 'application/pdf') {
            throw new RuntimeException('Der Anhang muss eine PDF-Datei sein.');
        }
        if ($datei['size'] > MAX_PDF_BYTES) {
            throw new RuntimeException('Die PDF-Datei ist größer als 10 MB.');
        }
        $endung = 'pdf';
    } else {
        if (!isset(BILD_TYPEN[$typ])) {
            throw new RuntimeException('Das Foto muss ein JPG, PNG oder WebP sein.');
        }
        if ($datei['size'] > MAX_BILD_BYTES) {
            throw new RuntimeException('Das Foto ist größer als 8 MB.');
        }
        $endung = BILD_TYPEN[$typ];
    }
    if (!is_dir(UPLOAD_DIR)) {
        mkdir(UPLOAD_DIR, 0755, true);
    }
    $name = date('Y-m') . '-' . bin2hex(random_bytes(8)) . '.' . $endung;
    if (!move_uploaded_file($datei['tmp_name'], UPLOAD_DIR . '/' . $name)) {
        throw new RuntimeException('Die Datei konnte nicht gespeichert werden.');
    }
    return $name;
}

function upload_loeschen(string $name): void
{
    $pfad = UPLOAD_DIR . '/' . basename($name);
    if ($name !== '' && is_file($pfad)) {
        unlink($pfad);
    }
}
