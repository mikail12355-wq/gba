/*
 * Admin-Bereich ohne Server: Beiträge werden direkt im GitHub-Repo gespeichert.
 * Der GitHub-Zugangsschlüssel liegt verschlüsselt in zugang.json und wird nur mit
 * dem Admin-Passwort im Browser entschlüsselt. Nach dem Speichern baut GitHub Pages
 * die Webseite neu (ca. 1–2 Minuten).
 */
(function () {
  "use strict";

  const DATEI_BEITRAEGE = "src/data/beitraege.json";
  const ORDNER_UPLOADS = "src/uploads/";
  const MAX_PDF = 10 * 1024 * 1024;
  const MAX_BILD = 8 * 1024 * 1024;
  const ETIKETTEN = { neu: "Neu", gilt: "Gilt weiterhin", beschluss: "Vorstandsbeschluss", info: "Information" };
  const SPEICHER_KEY = "gba-admin-sitzung";

  let zugang = null;   // Inhalt von zugang.json
  let token = null;    // entschlüsselter GitHub-Schlüssel (nur im Speicher und in sessionStorage)
  let daten = null;    // { beitraege: [...] }
  let bearbeiteId = 0; // 0 = neuer Beitrag

  const $ = (id) => document.getElementById(id);

  // ---------- Anzeige ----------

  function zeige(ansicht) {
    for (const n of ["login", "liste", "formular"]) $("ansicht-" + n).hidden = n !== ansicht;
    $("abmelden").hidden = ansicht === "login";
    window.scrollTo(0, 0);
  }

  function meldung(text) {
    const m = $("meldung");
    m.textContent = text;
    m.hidden = !text;
  }

  function fehler(id, text) {
    const f = $(id);
    f.textContent = text || "";
    f.hidden = !text;
  }

  function datumDe(iso) {
    const [y, m, d] = iso.split("-");
    return `${d}.${m}.${y}`;
  }

  // ---------- Hilfen: Base64 ----------

  function b64ZuBytes(b64) {
    const bin = atob(b64.replace(/\s/g, ""));
    const out = new Uint8Array(bin.length);
    for (let i = 0; i < bin.length; i++) out[i] = bin.charCodeAt(i);
    return out;
  }

  function bytesZuB64(bytes) {
    let bin = "";
    for (let i = 0; i < bytes.length; i += 0x8000) {
      bin += String.fromCharCode.apply(null, bytes.subarray(i, i + 0x8000));
    }
    return btoa(bin);
  }

  // ---------- Anmeldung ----------

  async function zugangLaden() {
    if (zugang) return zugang;
    const r = await fetch("zugang.json", { cache: "no-store" });
    if (!r.ok) throw new Error("Der Admin-Zugang ist noch nicht eingerichtet (zugang.json fehlt).");
    zugang = await r.json();
    return zugang;
  }

  async function entschluesseln(passwort) {
    const z = await zugangLaden();
    const material = await crypto.subtle.importKey("raw", new TextEncoder().encode(passwort), "PBKDF2", false, ["deriveKey"]);
    const schluessel = await crypto.subtle.deriveKey(
      { name: "PBKDF2", salt: b64ZuBytes(z.salt), iterations: z.iter, hash: "SHA-256" },
      material, { name: "AES-GCM", length: 256 }, false, ["decrypt"]);
    try {
      const klar = await crypto.subtle.decrypt({ name: "AES-GCM", iv: b64ZuBytes(z.iv) }, schluessel, b64ZuBytes(z.daten));
      return new TextDecoder().decode(klar);
    } catch (e) {
      throw new Error("Das Passwort stimmt nicht.");
    }
  }

  function sitzungSpeichern(wert) {
    try {
      if (wert) sessionStorage.setItem(SPEICHER_KEY, wert);
      else sessionStorage.removeItem(SPEICHER_KEY);
    } catch (e) { /* ohne sessionStorage: nach dem Neuladen erneut anmelden */ }
  }

  function sitzungLesen() {
    try { return sessionStorage.getItem(SPEICHER_KEY); } catch (e) { return null; }
  }

  // ---------- GitHub ----------

  async function gh(pfad, optionen = {}) {
    const r = await fetch((zugang.api || "https://api.github.com") + pfad, {
      method: optionen.method || "GET",
      headers: {
        Authorization: "Bearer " + token,
        Accept: "application/vnd.github+json",
        "X-GitHub-Api-Version": "2022-11-28",
        ...(optionen.body ? { "Content-Type": "application/json" } : {}),
      },
      body: optionen.body ? JSON.stringify(optionen.body) : undefined,
      cache: "no-store",
    });
    if (r.status === 401) {
      abmelden();
      throw new Error("Der GitHub-Zugangsschlüssel ist ungültig oder abgelaufen. Bitte den Zugang neu einrichten.");
    }
    if (r.status === 403 || r.status === 404) {
      throw new Error("Keine Berechtigung für das Repo. Der Schlüssel braucht Lese- und Schreibrechte auf „Contents“.");
    }
    if (!r.ok) {
      const t = await r.text();
      throw new Error("GitHub antwortet mit Fehler " + r.status + ". " + t.slice(0, 200));
    }
    return r.status === 204 ? null : r.json();
  }

  const repo = () => `/repos/${zugang.repo}`;

  async function beitraegeLaden() {
    const datei = await gh(`${repo()}/contents/${DATEI_BEITRAEGE}?ref=${encodeURIComponent(zugang.branch)}`);
    daten = JSON.parse(new TextDecoder().decode(b64ZuBytes(datei.content)));
    daten.beitraege = daten.beitraege || [];
  }

  /** Schreibt alle Änderungen als einen Commit. dateien: [{ pfad, b64 }] oder { pfad, loeschen: true } */
  async function commit(nachricht, dateien) {
    const ref = await gh(`${repo()}/git/ref/heads/${zugang.branch}`);
    const basis = await gh(`${repo()}/git/commits/${ref.object.sha}`);
    const baum = [];
    for (const d of dateien) {
      if (d.loeschen) {
        baum.push({ path: d.pfad, mode: "100644", type: "blob", sha: null });
      } else {
        const blob = await gh(`${repo()}/git/blobs`, { method: "POST", body: { content: d.b64, encoding: "base64" } });
        baum.push({ path: d.pfad, mode: "100644", type: "blob", sha: blob.sha });
      }
    }
    const neuerBaum = await gh(`${repo()}/git/trees`, { method: "POST", body: { base_tree: basis.tree.sha, tree: baum } });
    const neu = await gh(`${repo()}/git/commits`, {
      method: "POST", body: { message: nachricht, tree: neuerBaum.sha, parents: [ref.object.sha] },
    });
    await gh(`${repo()}/git/refs/heads/${zugang.branch}`, { method: "PATCH", body: { sha: neu.sha, force: false } });
  }

  function jsonDatei() {
    const sortiert = { beitraege: [...daten.beitraege].sort((a, b) => a.id - b.id) };
    return { pfad: DATEI_BEITRAEGE, b64: bytesZuB64(new TextEncoder().encode(JSON.stringify(sortiert, null, 2) + "\n")) };
  }

  // ---------- Übersicht ----------

  function listeZeigen() {
    const ul = $("liste");
    ul.textContent = "";
    const sortiert = [...daten.beitraege].sort((a, b) => (b.datum + String(b.id).padStart(6, "0")).localeCompare(a.datum + String(a.id).padStart(6, "0")));
    for (const b of sortiert) {
      const li = document.createElement("li");
      const zeit = document.createElement("time");
      zeit.dateTime = b.datum;
      zeit.textContent = datumDe(b.datum);
      const mitte = document.createElement("div");
      const titel = document.createElement("b");
      titel.textContent = b.titel;
      mitte.append(titel);
      if (ETIKETTEN[b.etikett]) {
        const tag = document.createElement("span");
        tag.className = "tag" + (b.etikett === "neu" ? "" : " old");
        tag.textContent = ETIKETTEN[b.etikett];
        mitte.append(tag);
      }
      const knoepfe = document.createElement("div");
      knoepfe.className = "knoepfe";
      const bearbeiten = document.createElement("button");
      bearbeiten.type = "button";
      bearbeiten.className = "btn btn-line";
      bearbeiten.textContent = "Bearbeiten";
      bearbeiten.addEventListener("click", () => formularOeffnen(b.id));
      const loeschen = document.createElement("button");
      loeschen.type = "button";
      loeschen.className = "btn btn-rot";
      loeschen.textContent = "Löschen";
      loeschen.addEventListener("click", () => loeschenFragen(knoepfe, b));
      knoepfe.append(bearbeiten, loeschen);
      li.append(zeit, mitte, knoepfe);
      ul.append(li);
    }
    $("liste-laedt").hidden = sortiert.length > 0;
    $("liste-laedt").textContent = "Noch keine Mitteilungen. Mit „Neuer Beitrag“ veröffentlichen Sie die erste.";
    ul.hidden = sortiert.length === 0;
  }

  function loeschenFragen(knoepfe, b) {
    knoepfe.textContent = "";
    const frage = document.createElement("div");
    frage.className = "frage";
    frage.append("Endgültig löschen?");
    const ja = document.createElement("button");
    ja.type = "button";
    ja.className = "btn btn-rot";
    ja.textContent = "Ja, löschen";
    const nein = document.createElement("button");
    nein.type = "button";
    nein.className = "btn btn-line";
    nein.textContent = "Abbrechen";
    nein.addEventListener("click", listeZeigen);
    ja.addEventListener("click", async () => {
      ja.disabled = nein.disabled = true;
      ja.textContent = "Wird gelöscht …";
      try {
        await beitraegeLaden();
        const alt = daten.beitraege.find((x) => x.id === b.id);
        if (!alt) throw new Error("Diesen Beitrag gibt es nicht mehr.");
        daten.beitraege = daten.beitraege.filter((x) => x.id !== b.id);
        const dateien = [jsonDatei()];
        if (alt.pdf) dateien.push({ pfad: ORDNER_UPLOADS + alt.pdf, loeschen: true });
        if (alt.bild) dateien.push({ pfad: ORDNER_UPLOADS + alt.bild, loeschen: true });
        await commit(`Mitteilung gelöscht: ${alt.titel}`, dateien);
        meldung(`„${alt.titel}“ gelöscht. Die Webseite ist in 1–2 Minuten aktualisiert.`);
      } catch (e) {
        meldung(e.message);
        await beitraegeLaden().catch(() => {});
      }
      listeZeigen();
    });
    frage.append(ja, nein);
    knoepfe.append(frage);
  }

  // ---------- Formular ----------

  function formularOeffnen(id) {
    bearbeiteId = id || 0;
    const b = id ? daten.beitraege.find((x) => x.id === id) : {
      datum: new Date().toISOString().slice(0, 10), titel: "", rubrik: "", etikett: "neu", text: "",
      unterschrift: "Der GBA-Vorstand", pdf: "", pdf_name: "", bild: "", bild_alt: "",
    };
    $("formular-titel").textContent = id ? "Beitrag bearbeiten" : "Neuer Beitrag";
    $("speichern").textContent = id ? "Änderungen speichern" : "Veröffentlichen";
    for (const f of ["titel", "datum", "rubrik", "etikett", "text", "unterschrift"]) $(f).value = b[f] || "";
    $("pdf-name").value = b.pdf_name || "";
    $("bild-alt").value = b.bild_alt || "";
    $("pdf").value = "";
    $("bild").value = "";
    $("pdf-entfernen").checked = false;
    $("bild-entfernen").checked = false;
    $("pdf-aktuell").hidden = !b.pdf;
    $("pdf-link").href = b.pdf ? "../uploads/" + b.pdf : "#";
    $("bild-aktuell").hidden = !b.bild;
    $("bild-vorschau").src = b.bild ? "../uploads/" + b.bild : "";
    fehler("formular-fehler", "");
    meldung("");
    zeige("formular");
    $("titel").focus();
  }

  async function dateiPruefen(datei, art) {
    if (!datei) return null;
    const bytes = new Uint8Array(await datei.arrayBuffer());
    const kopf = (n) => Array.from(bytes.subarray(0, n));
    const ascii = (a, b) => String.fromCharCode(...bytes.subarray(a, b));
    let endung = "";
    if (art === "pdf") {
      if (ascii(0, 5) !== "%PDF-") throw new Error("Der Anhang muss eine PDF-Datei sein.");
      if (bytes.length > MAX_PDF) throw new Error("Die PDF-Datei ist größer als 10 MB.");
      endung = "pdf";
    } else {
      const k = kopf(4);
      if (k[0] === 0xff && k[1] === 0xd8) endung = "jpg";
      else if (k[0] === 0x89 && ascii(1, 4) === "PNG") endung = "png";
      else if (ascii(0, 4) === "RIFF" && ascii(8, 12) === "WEBP") endung = "webp";
      else throw new Error("Das Foto muss ein JPG, PNG oder WebP sein.");
      if (bytes.length > MAX_BILD) throw new Error("Das Foto ist größer als 8 MB.");
    }
    const zufall = Array.from(crypto.getRandomValues(new Uint8Array(8)), (x) => x.toString(16).padStart(2, "0")).join("");
    const name = `${new Date().toISOString().slice(0, 7)}-${zufall}.${endung}`;
    return { name, b64: bytesZuB64(bytes) };
  }

  async function speichern(ev) {
    ev.preventDefault();
    fehler("formular-fehler", "");
    const w = {
      datum: $("datum").value.trim(), titel: $("titel").value.trim(), rubrik: $("rubrik").value.trim(),
      etikett: ETIKETTEN[$("etikett").value] ? $("etikett").value : "", text: $("text").value.trim(),
      unterschrift: $("unterschrift").value.trim(), pdf_name: $("pdf-name").value.trim(), bild_alt: $("bild-alt").value.trim(),
    };
    try {
      if (!/^\d{4}-\d{2}-\d{2}$/.test(w.datum)) throw new Error("Bitte ein gültiges Datum angeben.");
      if (!w.titel) throw new Error("Bitte einen Titel angeben.");
      if (!w.text) throw new Error("Bitte einen Text eingeben.");
      const pdf = await dateiPruefen($("pdf").files[0], "pdf");
      const bild = await dateiPruefen($("bild").files[0], "bild");
      if (bild && !w.bild_alt) throw new Error("Bitte kurz beschreiben, was auf dem Foto zu sehen ist.");

      $("speichern").disabled = true;
      $("speichern").textContent = "Wird gespeichert …";

      await beitraegeLaden(); // neuesten Stand holen
      const alt = bearbeiteId ? daten.beitraege.find((x) => x.id === bearbeiteId) : null;
      if (bearbeiteId && !alt) throw new Error("Diesen Beitrag gibt es nicht mehr.");
      const dateien = [];
      const b = alt || { id: daten.beitraege.reduce((m, x) => Math.max(m, x.id), 0) + 1, pdf: "", bild: "" };
      Object.assign(b, w);

      if (pdf || $("pdf-entfernen").checked) {
        if (b.pdf) dateien.push({ pfad: ORDNER_UPLOADS + b.pdf, loeschen: true });
        b.pdf = "";
        if (pdf) { dateien.push({ pfad: ORDNER_UPLOADS + pdf.name, b64: pdf.b64 }); b.pdf = pdf.name; }
      }
      if (bild || $("bild-entfernen").checked) {
        if (b.bild) dateien.push({ pfad: ORDNER_UPLOADS + b.bild, loeschen: true });
        b.bild = "";
        if (bild) { dateien.push({ pfad: ORDNER_UPLOADS + bild.name, b64: bild.b64 }); b.bild = bild.name; }
      }
      if (!b.pdf) b.pdf_name = "";
      if (!b.bild) b.bild_alt = "";
      if (!alt) daten.beitraege.push(b);
      dateien.unshift(jsonDatei());

      await commit(`${alt ? "Mitteilung geändert" : "Neue Mitteilung"}: ${b.titel}`, dateien);
      meldung(`${alt ? "Änderungen gespeichert" : "Beitrag veröffentlicht"}. Die Webseite ist in 1–2 Minuten aktualisiert.`);
      listeZeigen();
      zeige("liste");
    } catch (e) {
      fehler("formular-fehler", e.message);
      $("formular-fehler").scrollIntoView({ block: "center" });
    } finally {
      $("speichern").disabled = false;
      $("speichern").textContent = bearbeiteId ? "Änderungen speichern" : "Veröffentlichen";
    }
  }

  // ---------- Start ----------

  async function nachLogin() {
    zeige("liste");
    $("liste-laedt").hidden = false;
    $("liste-laedt").textContent = "Mitteilungen werden geladen …";
    $("liste").hidden = true;
    try {
      await beitraegeLaden();
      listeZeigen();
    } catch (e) {
      $("liste-laedt").textContent = e.message;
    }
  }

  function abmelden() {
    token = null;
    sitzungSpeichern(null);
    $("passwort").value = "";
    meldung("");
    zeige("login");
  }

  $("login-form").addEventListener("submit", async (ev) => {
    ev.preventDefault();
    fehler("login-fehler", "");
    const knopf = $("login-knopf");
    knopf.disabled = true;
    knopf.textContent = "Wird geprüft …";
    try {
      token = await entschluesseln($("passwort").value);
      sitzungSpeichern(token);
      $("passwort").value = "";
      await nachLogin();
    } catch (e) {
      fehler("login-fehler", e.message);
    } finally {
      knopf.disabled = false;
      knopf.textContent = "Anmelden";
    }
  });

  $("abmelden").addEventListener("click", abmelden);
  $("neu").addEventListener("click", () => formularOeffnen(0));
  $("formular").addEventListener("submit", speichern);
  document.querySelectorAll("[data-zurueck]").forEach((k) => k.addEventListener("click", () => { meldung(""); zeige("liste"); }));

  (async function start() {
    const gespeichert = sitzungLesen();
    if (gespeichert) {
      try {
        await zugangLaden();
        token = gespeichert;
        await nachLogin();
        return;
      } catch (e) { /* weiter zum Login */ }
    }
    zeige("login");
  })();
})();
