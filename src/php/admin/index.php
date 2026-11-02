<?php
/**
 * Admin-Bereich: Mitteilungen des Vorstands anlegen, bearbeiten und löschen.
 */
declare(strict_types=1);

require __DIR__ . '/../lib/gba.php';

session_name('gba_admin');
session_set_cookie_params([
    'httponly' => true,
    'samesite' => 'Strict',
    'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
]);
session_start();
header('X-Frame-Options: DENY');
header('X-Robots-Tag: noindex, nofollow');

const SPERR_VERSUCHE = 5;
const SPERR_SEKUNDEN = 15 * 60;

// ---------- Hilfen ----------

function eingeloggt(): bool
{
    return !empty($_SESSION['admin']);
}

function csrf(): string
{
    return $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
}

function csrf_pruefen(): void
{
    if (!hash_equals(csrf(), (string)($_POST['csrf'] ?? ''))) {
        http_response_code(400);
        exit('Die Sitzung ist abgelaufen. Bitte die Seite neu laden.');
    }
}

function weiter(string $ziel, string $meldung = ''): never
{
    if ($meldung !== '') {
        $_SESSION['meldung'] = $meldung;
    }
    header('Location: ' . $ziel, true, 303);
    exit;
}

function sperrdatei(): string
{
    return DATA_DIR . '/login-versuche.json';
}

function versuche(): array
{
    $daten = is_file(sperrdatei()) ? json_decode((string)file_get_contents(sperrdatei()), true) : [];
    return is_array($daten) ? $daten : [];
}

function gesperrt(string $ip): bool
{
    $v = versuche()[$ip] ?? null;
    return $v && $v['n'] >= SPERR_VERSUCHE && time() - $v['t'] < SPERR_SEKUNDEN;
}

function fehlversuch(string $ip, bool $erfolg): void
{
    if (!is_dir(DATA_DIR)) {
        mkdir(DATA_DIR, 0750, true);
    }
    $alle = array_filter(versuche(), fn($v) => time() - $v['t'] < SPERR_SEKUNDEN);
    if ($erfolg) {
        unset($alle[$ip]);
    } else {
        $alle[$ip] = ['n' => ($alle[$ip]['n'] ?? 0) + 1, 't' => time()];
    }
    file_put_contents(sperrdatei(), json_encode($alle), LOCK_EX);
}

// ---------- Aktionen (POST) ----------

$fehler = '';
$formular = null; // Werte, falls das Formular nach einem Fehler erneut gezeigt wird

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $aktion = $_POST['aktion'] ?? '';
    csrf_pruefen();

    if ($aktion === 'login') {
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unbekannt';
        if (gesperrt($ip)) {
            $fehler = 'Zu viele Fehlversuche. Bitte in 15 Minuten erneut versuchen.';
        } elseif (ADMIN_HASH !== ''
            && hash_equals(ADMIN_USER, (string)($_POST['benutzer'] ?? ''))
            & password_verify((string)($_POST['passwort'] ?? ''), ADMIN_HASH)) {
            fehlversuch($ip, true);
            session_regenerate_id(true);
            $_SESSION['admin'] = true;
            weiter('./');
        } else {
            fehlversuch($ip, false);
            usleep(500000);
            $fehler = 'Benutzername oder Passwort stimmt nicht.';
        }
    } elseif (!eingeloggt()) {
        weiter('./');
    } elseif ($aktion === 'logout') {
        $_SESSION = [];
        session_destroy();
        weiter('./');
    } elseif ($aktion === 'loeschen') {
        $b = beitrag((int)($_POST['id'] ?? 0));
        if ($b) {
            db()->prepare('DELETE FROM beitraege WHERE id = ?')->execute([$b['id']]);
            upload_loeschen($b['pdf']);
            upload_loeschen($b['bild']);
            weiter('./', 'Beitrag „' . $b['titel'] . '“ gelöscht.');
        }
        weiter('./');
    } elseif ($aktion === 'speichern') {
        $id = (int)($_POST['id'] ?? 0);
        $alt = $id ? beitrag($id) : null;
        $w = [
            'id'           => $id,
            'datum'        => trim((string)($_POST['datum'] ?? '')),
            'titel'        => trim((string)($_POST['titel'] ?? '')),
            'rubrik'       => trim((string)($_POST['rubrik'] ?? '')),
            'etikett'      => (string)($_POST['etikett'] ?? ''),
            'text'         => trim((string)($_POST['text'] ?? '')),
            'unterschrift' => trim((string)($_POST['unterschrift'] ?? '')),
            'pdf'          => $alt['pdf'] ?? '',
            'pdf_name'     => trim((string)($_POST['pdf_name'] ?? '')),
            'bild'         => $alt['bild'] ?? '',
            'bild_alt'     => trim((string)($_POST['bild_alt'] ?? '')),
        ];
        try {
            if ($id && !$alt) {
                throw new RuntimeException('Diesen Beitrag gibt es nicht mehr.');
            }
            if (!DateTime::createFromFormat('!Y-m-d', $w['datum'])) {
                throw new RuntimeException('Bitte ein gültiges Datum angeben.');
            }
            if ($w['titel'] === '' || mb_strlen($w['titel']) > 200) {
                throw new RuntimeException('Bitte einen Titel mit höchstens 200 Zeichen angeben.');
            }
            if ($w['text'] === '') {
                throw new RuntimeException('Bitte einen Text eingeben.');
            }
            if (!array_key_exists($w['etikett'], ETIKETTEN)) {
                $w['etikett'] = '';
            }
            $neuesPdf  = upload_speichern($_FILES['pdf'] ?? [], 'pdf');
            $neuesBild = upload_speichern($_FILES['bild'] ?? [], 'bild');
            if ($neuesBild !== '' && $w['bild_alt'] === '') {
                upload_loeschen($neuesPdf);
                upload_loeschen($neuesBild);
                throw new RuntimeException('Bitte kurz beschreiben, was auf dem Foto zu sehen ist.');
            }
            if ($neuesPdf !== '' || !empty($_POST['pdf_entfernen'])) {
                upload_loeschen($w['pdf']);
                $w['pdf'] = $neuesPdf;
            }
            if ($neuesBild !== '' || !empty($_POST['bild_entfernen'])) {
                upload_loeschen($w['bild']);
                $w['bild'] = $neuesBild;
            }
            if ($w['pdf'] === '') {
                $w['pdf_name'] = '';
            }
            if ($w['bild'] === '') {
                $w['bild_alt'] = '';
            }
            $felder = [$w['datum'], $w['titel'], $w['rubrik'], $w['etikett'], $w['text'], $w['unterschrift'],
                       $w['pdf'], $w['pdf_name'], $w['bild'], $w['bild_alt'], date('c')];
            if ($id) {
                db()->prepare('UPDATE beitraege SET datum=?, titel=?, rubrik=?, etikett=?, text=?, unterschrift=?,
                               pdf=?, pdf_name=?, bild=?, bild_alt=?, geaendert=? WHERE id=?')
                    ->execute([...$felder, $id]);
                weiter('./', 'Änderungen gespeichert.');
            }
            db()->prepare('INSERT INTO beitraege (datum, titel, rubrik, etikett, text, unterschrift, pdf, pdf_name,
                           bild, bild_alt, geaendert, erstellt) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')
                ->execute([...$felder, date('c')]);
            weiter('./', 'Beitrag veröffentlicht.');
        } catch (RuntimeException $e) {
            $fehler = $e->getMessage();
            $formular = $w;
        }
    }
}

// ---------- Ansicht ----------

$seite = eingeloggt() ? ($formular ? 'formular' : ($_GET['seite'] ?? 'liste')) : 'login';
if ($seite === 'formular' && !$formular) {
    $id = (int)($_GET['id'] ?? 0);
    $formular = $id ? beitrag($id) : [
        'id' => 0, 'datum' => date('Y-m-d'), 'titel' => '', 'rubrik' => '', 'etikett' => 'neu', 'text' => '',
        'unterschrift' => 'Der GBA-Vorstand', 'pdf' => '', 'pdf_name' => '', 'bild' => '', 'bild_alt' => '',
    ];
    if (!$formular) {
        weiter('./', 'Diesen Beitrag gibt es nicht mehr.');
    }
}
$meldung = $_SESSION['meldung'] ?? '';
unset($_SESSION['meldung']);
?>
<!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Mitteilungen verwalten · GBA</title>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Barlow+Condensed:wght@500;600;700&family=Barlow:wght@400;500;600&display=swap">
<link rel="stylesheet" href="../styles.css">
<style>
.admin{padding-block:40px 80px}
.admin h1{font-size:clamp(34px,5vw,48px);margin-bottom:24px}
.box{background:var(--surface);border:1px solid var(--line);padding:28px}
.login{max-width:420px;margin:40px auto}
.felder{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:20px}
.feld{display:flex;flex-direction:column;gap:6px;min-width:0}
.feld.breit{grid-column:1/-1}
.feld label{font-size:13px;letter-spacing:.1em;text-transform:uppercase;color:var(--muted);font-weight:600}
.feld small{color:var(--muted);font-size:14px}
.feld input[type=text],.feld input[type=date],.feld input[type=password],.feld select,.feld textarea{width:100%;font:inherit;padding:11px 12px;border:1.5px solid var(--line);background:var(--paper);color:var(--ink);border-radius:2px}
.feld textarea{min-height:260px;line-height:1.6;resize:vertical}
.feld input:focus,.feld select:focus,.feld textarea:focus{border-color:var(--gold-ink);outline:none}
.datei{border:1.5px dashed var(--line);padding:14px;display:flex;flex-direction:column;gap:8px}
.check{display:flex;gap:8px;align-items:center;font-size:15px}
.aktionen{display:flex;flex-wrap:wrap;gap:12px;align-items:center;margin-top:24px}
.btn-line{border-color:var(--line);color:var(--ink);background:transparent;cursor:pointer}
.btn-line:hover{border-color:var(--gold-ink);color:var(--gold-ink)}
button.btn{cursor:pointer}
.btn-rot{background:transparent;border-color:var(--red);color:var(--red);cursor:pointer}
.meldung{background:var(--tint);padding:14px 18px;margin-bottom:24px;border-left:4px solid var(--gold-ink)}
.fehler{border:1.5px solid var(--red);color:var(--red);padding:14px 18px;margin-bottom:24px}
.zeilen{list-style:none;margin:0;padding:0;background:var(--surface);border:1px solid var(--line)}
.zeilen li{display:grid;grid-template-columns:110px minmax(0,1fr) auto;gap:18px;align-items:center;padding:16px 20px;border-top:1px solid var(--line)}
.zeilen li:first-child{border-top:0}
.zeilen time{font-variant-numeric:tabular-nums;color:var(--muted)}
.zeilen b{display:block}
.zeilen .tag{margin-top:6px}
.zeilen form{display:inline}
.zeilen .knoepfe{display:flex;gap:8px}
.zeilen .knoepfe .btn{padding:8px 14px;font-size:15px}
.kopf{display:flex;justify-content:space-between;align-items:center;gap:16px;flex-wrap:wrap;margin-bottom:24px}
.kopf h1{margin:0}
.vorschau{max-width:180px;display:block;margin-top:4px}
@media (max-width:700px){.felder{grid-template-columns:1fr}.zeilen li{grid-template-columns:1fr;gap:8px}}
</style>
</head>
<body>
<div class="flag" aria-hidden="true"><i style="background:#111"></i><i style="background:#d00"></i><i style="background:#ffce00"></i></div>
<header>
  <div class="wrap bar">
    <a class="logo" href="../"><b>GBA<span>.</span></b><small>Admin-Bereich<br>Mitteilungen</small></a>
    <?php if (eingeloggt()): ?>
      <form method="post"><input type="hidden" name="csrf" value="<?= h(csrf()) ?>"><input type="hidden" name="aktion" value="logout"><button class="btn btn-ghost" type="submit">Abmelden</button></form>
    <?php endif; ?>
  </div>
</header>

<main class="wrap admin">
<?php if ($meldung): ?><div class="meldung" role="status"><?= h($meldung) ?></div><?php endif; ?>

<?php if ($seite === 'login'): ?>
  <div class="box login">
    <h1>Anmelden</h1>
    <?php if (ADMIN_HASH === ''): ?>
      <div class="fehler">Es ist noch kein Zugang eingerichtet. Im Projektordner <code>php passwort-setzen.php</code> ausführen und die Datei <code>lib/zugang.php</code> hochladen.</div>
    <?php else: ?>
      <?php if ($fehler): ?><div class="fehler" role="alert"><?= h($fehler) ?></div><?php endif; ?>
      <form method="post">
        <input type="hidden" name="csrf" value="<?= h(csrf()) ?>">
        <input type="hidden" name="aktion" value="login">
        <div class="felder" style="grid-template-columns:1fr">
          <div class="feld"><label for="benutzer">Benutzername</label><input type="text" id="benutzer" name="benutzer" autocomplete="username" required autofocus></div>
          <div class="feld"><label for="passwort">Passwort</label><input type="password" id="passwort" name="passwort" autocomplete="current-password" required></div>
        </div>
        <div class="aktionen"><button class="btn btn-gold" type="submit">Anmelden</button></div>
      </form>
    <?php endif; ?>
  </div>

<?php elseif ($seite === 'formular'): $f = $formular; ?>
  <div class="kopf">
    <h1><?= $f['id'] ? 'Beitrag bearbeiten' : 'Neuer Beitrag' ?></h1>
    <a class="btn btn-line" href="./">Zur Übersicht</a>
  </div>
  <?php if ($fehler): ?><div class="fehler" role="alert"><?= h($fehler) ?></div><?php endif; ?>
  <form class="box" method="post" enctype="multipart/form-data">
    <input type="hidden" name="csrf" value="<?= h(csrf()) ?>">
    <input type="hidden" name="aktion" value="speichern">
    <input type="hidden" name="id" value="<?= (int)$f['id'] ?>">
    <div class="felder">
      <div class="feld breit"><label for="titel">Titel</label><input type="text" id="titel" name="titel" value="<?= h($f['titel']) ?>" maxlength="200" required></div>
      <div class="feld"><label for="datum">Datum</label><input type="date" id="datum" name="datum" value="<?= h($f['datum']) ?>" required></div>
      <div class="feld"><label for="etikett">Etikett</label>
        <select id="etikett" name="etikett">
          <?php foreach (ETIKETTEN as $k => $name): ?>
            <option value="<?= h($k) ?>"<?= $f['etikett'] === $k ? ' selected' : '' ?>><?= h($name) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="feld breit"><label for="rubrik">Rubrik <small>(optional)</small></label><input type="text" id="rubrik" name="rubrik" value="<?= h($f['rubrik']) ?>" placeholder="boxrec.com · Kostenumlage"></div>
      <div class="feld breit"><label for="text">Text</label>
        <textarea id="text" name="text" required><?= h($f['text']) ?></textarea>
        <small>Leerzeile = neuer Absatz. Zeilen mit „- “ am Anfang werden zur Aufzählung. **Wort** wird fett.</small>
      </div>
      <div class="feld breit"><label for="unterschrift">Unterschrift <small>(optional)</small></label><input type="text" id="unterschrift" name="unterschrift" value="<?= h($f['unterschrift']) ?>"></div>

      <div class="feld"><label for="pdf">PDF-Anhang <small>(optional, max. 10 MB)</small></label>
        <div class="datei">
          <?php if ($f['pdf']): ?>
            <a href="../uploads/<?= h($f['pdf']) ?>">Aktueller Anhang öffnen</a>
            <label class="check"><input type="checkbox" name="pdf_entfernen" value="1"> Anhang entfernen</label>
          <?php endif; ?>
          <input type="file" id="pdf" name="pdf" accept="application/pdf">
          <input type="text" name="pdf_name" value="<?= h($f['pdf_name']) ?>" placeholder="Linktext, z. B. Beschluss als PDF" aria-label="Linktext für den Anhang">
        </div>
      </div>
      <div class="feld"><label for="bild">Foto <small>(optional, JPG/PNG/WebP, max. 8 MB)</small></label>
        <div class="datei">
          <?php if ($f['bild']): ?>
            <img class="vorschau" src="../uploads/<?= h($f['bild']) ?>" alt="">
            <label class="check"><input type="checkbox" name="bild_entfernen" value="1"> Foto entfernen</label>
          <?php endif; ?>
          <input type="file" id="bild" name="bild" accept="image/jpeg,image/png,image/webp">
          <input type="text" name="bild_alt" value="<?= h($f['bild_alt']) ?>" placeholder="Was ist zu sehen? (für Screenreader)" aria-label="Bildbeschreibung">
        </div>
      </div>
    </div>
    <div class="aktionen">
      <button class="btn btn-gold" type="submit"><?= $f['id'] ? 'Änderungen speichern' : 'Veröffentlichen' ?></button>
      <a class="btn btn-line" href="./">Abbrechen</a>
    </div>
  </form>

<?php else: $liste = beitraege(); ?>
  <div class="kopf">
    <h1>Mitteilungen</h1>
    <div style="display:flex;gap:12px;flex-wrap:wrap">
      <a class="btn btn-line" href="../aktuelles.php">Seite ansehen</a>
      <a class="btn btn-gold" href="?seite=formular">Neuer Beitrag</a>
    </div>
  </div>
  <?php if (!$liste): ?>
    <div class="box"><h3>Noch keine Mitteilungen</h3><p>Mit „Neuer Beitrag“ veröffentlichen Sie die erste Mitteilung.</p></div>
  <?php else: ?>
    <ul class="zeilen">
      <?php foreach ($liste as $b): ?>
        <li>
          <time datetime="<?= h($b['datum']) ?>"><?= datum_de($b['datum']) ?></time>
          <div><b><?= h($b['titel']) ?></b><?= etikett_html($b['etikett']) ?></div>
          <div class="knoepfe">
            <a class="btn btn-line" href="?seite=formular&amp;id=<?= (int)$b['id'] ?>">Bearbeiten</a>
            <form method="post" onsubmit="return confirm('Diesen Beitrag endgültig löschen?')">
              <input type="hidden" name="csrf" value="<?= h(csrf()) ?>">
              <input type="hidden" name="aktion" value="loeschen">
              <input type="hidden" name="id" value="<?= (int)$b['id'] ?>">
              <button class="btn btn-rot" type="submit">Löschen</button>
            </form>
          </div>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
<?php endif; ?>
</main>
</body>
</html>
