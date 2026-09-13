# raycube.de — RayCube-Produktwebsite (ThermProTEC GmbH)

Statische One-Page-Website (DE/EN) mit serverseitigem Anfrageformular. Wird per **Plesk Git** aus diesem Repository auf das GoDaddy-Hosting deployt: jeder Push auf `main` löst über Webhook den Pull in `httpdocs` von raycube.de aus.

## Struktur

| Pfad | Zweck |
|---|---|
| `index.html` | Gesamte Seite: Markup, CSS, i18n-Texte (DE/EN), ROI-Rechner, Konfigurator, Rechtstexte |
| `anfrage.php` | Formular-Endpunkt: Mail an info@thermprotec.com, Bestätigung an Absender, CSV-Log in `data/` (PHP ≥ 8.1) |
| `assets/img/` | Bilder (aus der ehemals eingebetteten Fassung ausgelagert) |
| `assets/js/jspdf.umd.min.js` | jsPDF für „Konfiguration als PDF speichern" |
| `web.config` | IIS: Standarddokument, Sicherheits-Header, Caching, sperrt `/data`, `/tools`, `/.git` |
| `robots.txt`, `sitemap.xml` | SEO |
| `tools/build.mjs` | Einmal-Transformer, mit dem `index.html` aus der self-contained GoLive-Datei erzeugt wurde (Dokumentation) |

`data/` wird von `anfrage.php` zur Laufzeit angelegt und ist per `.gitignore` ausgeschlossen — Anfragen landen nie im Repository.

## Texte ändern

Alle sichtbaren Texte stehen im Objekt `I18N` in `index.html` (`de:{…}`, `en:{…}`). Die DE-Texte sind zusätzlich direkt im Markup vorgerendert (für Crawler und LCP); beim Ändern eines DE-Textes also **beide Stellen** anpassen — oder `tools/build.mjs` erneut über die Ausgangsdatei laufen lassen.

Sprache per URL: `https://raycube.de/?lang=en`.

## Rechtliches

Impressum und Datenschutzerklärung liegen im Objekt `LEGAL` in `index.html` (DE + EN). Keine Cookies, kein Tracking, keine Drittanbieter-Inhalte — daher kein Cookie-Banner.

## Preise / ROI

Die Seite enthält keine Listenpreise. Konfigurator zeigt nur Positionen und technische Daten; der ROI-Rechner (Abschnitt „Wirtschaftlichkeit") rechnet mit dem vom Besucher eingegebenen Investitionsbudget (Vorbelegung 1.000.000 € als neutraler Rundwert) und einem anonymen Referenzprodukt (Standard-Granulat 1.000 €/t, High-End 2.000 €/t, Fracht 250 €/t) — keine Wettbewerbernamen, alle Werte editierbar.
