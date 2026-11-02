"""Baut die GBA-Webseite: setzt Kopf- und Fußzeile in jede Seite aus src/pages ein.

Aufruf:  python3 build.py
Ergebnis: Ordner site/ (fertige Webseite mit PHP-Backend) und build/preview/ (statische Vorschau für Claude).
"""
from pathlib import Path
import shutil

ROOT = Path(__file__).parent
SRC, OUT = ROOT / "src", ROOT / "site"

# Datei -> (Seitentitel, Menüpunkt, Kopfband: (Rubrik, Überschrift, Einleitung) oder None für die Startseite)
PAGES = {
    "index.html": ("GBA Boxverband", None, None),
    "aktuelles.html": ("Aktuelles · GBA", "aktuelles", ("Aktuelles", "Mitteilungen des Vorstands",
        "Beschlüsse und Regeln, die für Lizenznehmer und Promoter gelten. Die neueste Mitteilung steht oben.")),
    "lizenzen.html": ("Lizenzen · GBA", "lizenzen", ("Lizenzen", "Ihre Lizenz bei der GBA",
        "Die GBA lizenziert Berufsboxer und Berufsboxerinnen, Ring- und Punktrichter sowie Promoter, Manager und Matchmaker.")),
    "dokumente.html": ("Dokumente · GBA", "dokumente", ("Dokumente", "Formulare und Anträge",
        "Alle Formulare als PDF. Ausfüllen, unterschreiben und an das Büro in Berlin schicken.")),
    "sperrliste.html": ("Sperrliste · GBA", "sperrliste", ("Sperrliste", "Schutzsperren",
        "Nach einer Niederlage durch KO oder TKO darf ein Boxer für eine feste Frist nicht antreten. So schützt die GBA die Gesundheit ihrer Lizenznehmer.")),
    "verband.html": ("Verband · GBA", "verband", ("Verband", "Die German Boxing Association",
        "Eingetragener Verein mit Sitz in Berlin, seit 2004 Verband für Profiboxen und Kick-/Thaiboxen.")),
    "impressum.html": ("Impressum · GBA", None, ("Rechtliches", "Impressum", "Angaben nach § 5 DDG.")),
    "datenschutz.html": ("Datenschutz · GBA", None, ("Rechtliches", "Datenschutzerklärung",
        "Wie die GBA mit personenbezogenen Daten auf dieser Webseite umgeht.")),
}

NAV = [("aktuelles", "Aktuelles"), ("lizenzen", "Lizenzen"), ("dokumente", "Dokumente"),
       ("sperrliste", "Sperrliste"), ("verband", "Verband")]

HEAD = """<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="claude:viewport-fit-sides" content="cover">
<title>{title}</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Barlow+Condensed:wght@500;600;700&family=Barlow:wght@400;500;600&display=swap">
<link rel="stylesheet" href="styles.css">"""

HEADER = """<div class="flag" aria-hidden="true"><i style="background:#111"></i><i style="background:#d00"></i><i style="background:#ffce00"></i></div>
<header>
  <div class="wrap bar">
    <a class="logo" href="./" aria-label="GBA Startseite">
      <b>GBA<span>.</span></b>
      <small>German Boxing<br>Association e.V.</small>
    </a>
    <button class="menu-btn" id="menu-btn" aria-expanded="false" aria-controls="nav">Menü</button>
    <nav id="nav" aria-label="Hauptnavigation">
      <ul>
{items}
        <li><a class="cta" href="verband.html#kontakt">Kontakt</a></li>
      </ul>
    </nav>
  </div>
</header>"""

PAGE_HERO = """<div class="page-hero">
  <div class="wrap">
    <div class="crumbs"><a href="./">Start</a> / {eyebrow}</div>
    <div class="eyebrow">{eyebrow}</div>
    <h1>{h1}</h1>
    <p>{intro}</p>
  </div>
</div>"""

FOOTER = """<footer id="kontakt-fuss">
  <div class="wrap foot">
    <div>
      <div class="big">GBA<span>.</span></div>
      <p style="margin-top:12px">German Boxing Association e.V.<br>Profiboxverband seit 2004</p>
    </div>
    <div>
      <h3>Büro</h3>
      <p>Münsterwalder Straße 8<br>12621 Berlin</p>
      <p style="margin-top:10px">Tel. +49 30 56701574<br>Fax +49 30 56701575<br><a href="mailto:buero@gbaboxing.de">buero@gbaboxing.de</a></p>
    </div>
    <div>
      <h3>Schnellzugriff</h3>
      <p><a href="lizenzen.html">Lizenz beantragen</a><br><a href="dokumente.html">Formulare</a><br><a href="sperrliste.html">Sperrliste</a><br><a href="aktuelles.html">Mitteilungen</a></p>
    </div>
    <div>
      <h3>Register</h3>
      <p>Vereinsregister VR 23561 B<br>Amtsgericht Charlottenburg<br>Steuernummer 27/616/6423</p>
    </div>
  </div>
  <div class="wrap legal">
    <span>© 2026 German Boxing Association e.V.</span>
    <span><a href="impressum.html">Impressum</a> · <a href="datenschutz.html">Datenschutz</a></span>
  </div>
</footer>
<script src="site.js"></script>"""


def nav_items(active):
    rows = []
    for key, label in NAV:
        cur = ' aria-current="page"' if key == active else ""
        rows.append(f'        <li><a href="{key}.html"{cur}>{label}</a></li>')
    return "\n".join(rows)


def render(name, standalone, source=None):
    title, active, hero = PAGES[name]
    body = (SRC / "pages" / (source or name)).read_text(encoding="utf-8")
    head = HEAD.format(title=title)
    parts = [HEADER.format(items=nav_items(active))]
    if hero:
        parts.append(PAGE_HERO.format(eyebrow=hero[0], h1=hero[1], intro=hero[2]))
        body = f'<main class="page">\n{body}\n</main>'
    parts += [body, FOOTER]
    content = "\n".join(parts)
    if standalone:
        return f'<!doctype html>\n<html lang="de">\n<head>\n{head}\n</head>\n<body>\n{content}\n</body>\n</html>\n'
    return f"{head}\n{content}\n"


def copy_assets(target):
    target.mkdir(parents=True, exist_ok=True)
    for f in ("styles.css", "site.js"):
        shutil.copy(SRC / f, target / f)
    for d in ("img", "docs"):  # Fotos und PDFs
        shutil.copytree(SRC / d, target / d, dirs_exist_ok=True)


def build_site():
    """Echte Webseite mit PHP-Backend. Seiten mit einer .php-Quelle werden zu .php."""
    copy_assets(OUT)
    shutil.copytree(SRC / "php", OUT, dirs_exist_ok=True)  # lib/, admin/, uploads/, data/
    for name in PAGES:
        php = name.replace(".html", ".php")
        if (SRC / "pages" / php).exists():
            html = render(name, standalone=True, source=php)
            (OUT / name).unlink(missing_ok=True)
            out = php
        else:
            html = render(name, standalone=True)
            out = name
        (OUT / out).write_text(html.replace("aktuelles.html", "aktuelles.php"), encoding="utf-8")


def build_preview():
    """Statische Vorschau für den Claude-Link (dort läuft kein PHP)."""
    prev = ROOT / "build" / "preview"
    copy_assets(prev)
    (prev / "artifact-index.html").unlink(missing_ok=True)
    # Entwurf mit echten Vereinsdaten: nicht in Suchmaschinen aufnehmen.
    noindex = lambda html: html.replace("<title>", '<meta name="robots" content="noindex, nofollow">\n<title>', 1)
    for name in PAGES:
        (prev / name).write_text(noindex(render(name, standalone=True)), encoding="utf-8")
    # Die Claude-Vorschau setzt das HTML-Gerüst der Startseite selbst, daher ohne <html>/<head>.
    (ROOT / "build" / "artifact-index.html").write_text(noindex(render("index.html", standalone=False)), encoding="utf-8")


def main():
    build_site()
    build_preview()
    print("Fertig: site/ (mit PHP-Backend) und build/preview/ (statische Vorschau)")


if __name__ == "__main__":
    main()
