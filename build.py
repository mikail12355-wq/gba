"""Baut die GBA-Webseite: setzt Kopf- und Fußzeile in jede Seite aus src/pages ein.

Aufruf:  python3 build.py
Ergebnis: Ordner site/ (fertige Webseite mit PHP-Backend) und build/preview/ (statische Vorschau für Claude).
"""
from pathlib import Path
import html as _html
import json
import re
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


# ---------- Mitteilungen aus src/data/beitraege.json (statische Fassung) ----------
# Gleiches Textformat wie im PHP-Backend: Leerzeile = Absatz, "- " = Liste, **fett**.

ETIKETTEN = {"neu": "Neu", "gilt": "Gilt weiterhin", "beschluss": "Vorstandsbeschluss", "info": "Information"}
ALERT_ICON = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 4l9 16H3z"/><path d="M12 10v4.5M12 17.2v.3"/></svg>'


def esc(s):
    return _html.escape(s or "", quote=True)


def inline_html(s):
    return re.sub(r"\*\*(.+?)\*\*", r"<strong>\1</strong>", esc(s))


def text_html(text):
    out = ""
    for block in re.split(r"\n\s*\n", (text or "").replace("\r\n", "\n").strip()):
        lines = [l.strip() for l in block.split("\n") if l.strip()]
        if not lines:
            continue
        if all(l.startswith("- ") for l in lines):
            out += "<ul>" + "".join(f"<li>{inline_html(l[2:])}</li>" for l in lines) + "</ul>"
        else:
            out += "<p>" + "<br>".join(inline_html(l) for l in lines) + "</p>"
    return out


def kurztext(text, maxlen=220):
    first = re.split(r"\n\s*\n", (text or "").replace("\r\n", "\n").strip())[0]
    first = re.sub(r"\s+", " ", re.sub(r"^- ", "", first.replace("**", ""), flags=re.M)).strip()
    return first if len(first) <= maxlen else first[:maxlen].rstrip() + " …"


def datum_de(iso):
    y, m, d = iso.split("-")
    return f"{d}.{m}.{y}"


def etikett_html(key):
    if key not in ETIKETTEN:
        return ""
    return f'<span class="tag{"" if key == "neu" else " old"}">{esc(ETIKETTEN[key])}</span>'


def beitraege():
    data = json.loads((SRC / "data" / "beitraege.json").read_text(encoding="utf-8"))
    return sorted(data["beitraege"], key=lambda b: (b["datum"], b["id"]), reverse=True)


def beitrag_html(b):
    o = f'<article class="entry" id="beitrag-{int(b["id"])}">'
    o += f'<div class="when"><time datetime="{esc(b["datum"])}">{datum_de(b["datum"])}</time>{etikett_html(b.get("etikett", ""))}</div><div>'
    if b.get("rubrik"):
        o += f'<div class="eyebrow">{esc(b["rubrik"])}</div>'
    o += f'<h2>{esc(b["titel"])}</h2>'
    if b.get("bild"):
        o += f'<figure class="photo entry-photo"><img src="uploads/{esc(b["bild"])}" alt="{esc(b.get("bild_alt"))}" loading="lazy"></figure>'
    o += text_html(b["text"])
    if b.get("pdf"):
        o += (f'<a class="attachment" href="uploads/{esc(b["pdf"])}" type="application/pdf"><span class="pdf">PDF</span> '
              f'{esc(b.get("pdf_name") or "Anhang öffnen")}</a>')
    if b.get("unterschrift"):
        o += f'<p class="sig">{esc(b["unterschrift"])}</p>'
    return o + "</div></article>"


def fill_posts(body):
    posts = beitraege()
    if "<!--BEITRAEGE_LISTE-->" in body:
        liste = "\n".join(beitrag_html(b) for b in posts) or '<p class="note">Zurzeit gibt es keine Mitteilungen.</p>'
        body = body.replace("<!--BEITRAEGE_LISTE-->", liste)
    if "<!--BEITRAEGE_START-->" in body:
        cards = "".join(
            f'<article class="post">{etikett_html(b.get("etikett", ""))}'
            f'<time datetime="{esc(b["datum"])}">{datum_de(b["datum"])}</time>'
            f'<h3>{esc(b["titel"])}</h3><p>{esc(kurztext(b["text"]))}</p>'
            f'<a class="sig" href="aktuelles.html#beitrag-{int(b["id"])}">Weiterlesen →</a></article>'
            for b in posts[:2])
        body = body.replace("<!--BEITRAEGE_START-->", cards)
    if "<!--BEITRAG_SCHNELLZUGRIFF-->" in body:
        card = ""
        if posts:
            n = posts[0]
            neu = n.get("etikett") == "neu"
            card = (f'<a class="task{" alert" if neu else ""}" href="aktuelles.html#beitrag-{int(n["id"])}">{ALERT_ICON}'
                    f'<b>{"Neu" if neu else "Aktuell"} · {datum_de(n["datum"])}</b><span>{esc(n["titel"])}</span></a>')
        body = body.replace("<!--BEITRAG_SCHNELLZUGRIFF-->", card)
    return body


def nav_items(active):
    rows = []
    for key, label in NAV:
        cur = ' aria-current="page"' if key == active else ""
        rows.append(f'        <li><a href="{key}.html"{cur}>{label}</a></li>')
    return "\n".join(rows)


def render(name, standalone, source=None):
    title, active, hero = PAGES[name]
    body = fill_posts((SRC / "pages" / (source or name)).read_text(encoding="utf-8"))
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
    # Admin-Seite ohne Server (speichert über GitHub) und hochgeladene Dateien
    shutil.copytree(SRC / "static-admin", prev / "admin", dirs_exist_ok=True)
    shutil.copytree(SRC / "uploads", prev / "uploads", dirs_exist_ok=True)
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
