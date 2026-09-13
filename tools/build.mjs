// Einmaliger Transformer: GoLive/index.html (self-contained) -> Repo-Struktur mit Assets + SEO
import fs from 'node:fs';
import path from 'node:path';

const SRC = process.argv[2];
const OUT = process.argv[3] || '.';
let h = fs.readFileSync(SRC, 'utf8');

// ---------- 1. Bilder auslagern ----------
fs.mkdirSync(path.join(OUT, 'assets/img'), { recursive: true });
const seen = new Map();
let n = 0;
const slug = s => s.toLowerCase().replace(/ä/g,'ae').replace(/ö/g,'oe').replace(/ü/g,'ue').replace(/ß/g,'ss')
  .replace(/[^a-z0-9]+/g,'-').replace(/^-|-$/g,'').slice(0,40) || 'img';
h = h.replace(/<img([^>]*?)src="data:(image\/[a-z+]+);base64,([A-Za-z0-9+\/=]+)"([^>]*)>/g, (m, pre, mime, b64, post) => {
  const attrs = pre + post;
  const alt = (attrs.match(/alt="([^"]*)"/) || [,''])[1];
  const id  = (attrs.match(/id="([^"]*)"/) || [,''])[1];
  const ext = mime === 'image/png' ? 'png' : mime === 'image/jpeg' ? 'jpg' : mime.split('/')[1].replace('+xml','');
  const key = b64.slice(0, 64) + b64.length;
  let file;
  if (seen.has(key)) file = seen.get(key);
  else {
    n++;
    file = `assets/img/${String(n).padStart(2,'0')}-${slug(alt || id || 'img')}.${ext}`;
    fs.writeFileSync(path.join(OUT, file), Buffer.from(b64, 'base64'));
    seen.set(key, file);
  }
  const lazy = /class="[^"]*\btp\b|alt="(RayCube|RayBall|ThermProTEC)"|id="heroImg"/.test(attrs) || n <= 3 ? '' : ' loading="lazy" decoding="async"';
  return `<img${pre}src="${file}"${post}${lazy}>`;
});
// CSS/inline background data URIs
h = h.replace(/url\(["']?data:(image\/[a-z+]+);base64,([A-Za-z0-9+\/=]+)["']?\)/g, (m, mime, b64) => {
  n++;
  const ext = mime === 'image/png' ? 'png' : 'jpg';
  const file = `assets/img/${String(n).padStart(2,'0')}-bg.${ext}`;
  fs.writeFileSync(path.join(OUT, file), Buffer.from(b64, 'base64'));
  return `url(${file})`;
});
console.log('Bilder ausgelagert:', n);

// ---------- 2. Inhaltliche SEO-Anpassungen in den i18n-Texten ----------
const rep = (a, b) => { if (!h.includes(a)) throw new Error('nicht gefunden: ' + a.slice(0, 60)); h = h.replace(a, b); };
rep(`hero_eyebrow:'ThermProTEC Perlite-Technologie'`, `hero_eyebrow:'Perlit-Expansionsanlage von ThermProTEC'`);
rep(`hero_eyebrow:'ThermProTEC perlite technology'`, `hero_eyebrow:'Perlite expansion plant by ThermProTEC'`);
rep(`hero_h1:'Produzieren Sie Ihren Dämmstoff dort, wo er gebraucht wird.'`,
    `hero_h1:'Produzieren Sie Ihren mineralischen Dämmstoff dort, wo er gebraucht wird.'`);
rep(`hero_h1:'Produce your insulation material where it is needed.'`,
    `hero_h1:'Produce your mineral insulation material where it is needed.'`);
rep(`hero_sub:'RayCube ist das modulare NIR-Expansionssystem für die dezentrale Produktion von RayBall — dem geschlossenzelligen, nicht brennbaren mineralischen Leichtfüllstoff.'`,
    `hero_sub:'RayCube ist die modulare NIR-Expansionsanlage für die dezentrale Produktion von RayBall — expandiertem Perlit aus Vulkansand als geschlossenzelligem, nicht brennbarem Leichtzuschlag und Dämmstoff für die Bauindustrie.'`);
rep(`ft_about:'ThermProTEC entwickelt und baut induktive Wärmeanlagen und Sondermaschinen — und mit RayCube das modulare Produktionssystem für mineralische Hochleistungs-Leichtfüllstoffe.'`,
    `ft_about:'ThermProTEC entwickelt und baut induktive Wärmeanlagen und Sondermaschinen in Kehl — und mit RayCube die Perlit-Expansionsanlage für mineralische Hochleistungs-Leichtfüllstoffe (Blähperlit-Alternative zu Blähglas und EPS).'`);

// EN-Datenschutz: Abschnitt "Contacting us" beschrieb noch den mailto-Weg -> an Formular angleichen
rep(`<h4>Contacting us</h4><p>If you send an enquiry via the buttons on this page, your own e-mail client opens with the configuration you assembled; you send the message yourself. We process your details, date and time for the purpose of handling the enquiry. Legal basis is Art. 6 (1) subpara. 1 lit. f) GDPR and, where processing serves the initiation or performance of a contract, lit. b). Data is deleted once the enquiry has been concluded.</p>`,
    `<h4>Enquiry form</h4><p>Via the enquiry form we collect the data you enter (company, contact person, function, e-mail, phone, country, site, delivery date, annual demand, target bulk density, shift operation, intended use, remarks) together with your chosen plant configuration, date, time and IP address. The data is stored on our server in Europe and transmitted to us by e-mail; a confirmation of receipt is sent to the address you provide. Legal basis is your consent pursuant to Art. 6 (1) subpara. 1 lit. a) GDPR and, where processing serves the initiation or performance of a contract, lit. b). You may withdraw your consent at any time by e-mail to info@thermprotec.com. Data is deleted once the enquiry has been concluded, unless statutory retention periods apply.</p>`);

// ---------- 3. Head: Title, Description, Canonical, OG, JSON-LD, Favicon ----------
rep(`<title>RayCube — Dezentrale RayBall-Produktion | ThermProTEC</title>`,
    `<title>RayCube – Perlit-Expansionsanlage für mineralische Dämmstoffe aus Vulkansand | ThermProTEC</title>`);
rep(`<meta name="description" content="RayCube: modulare NIR-Expansionsanlagen für die dezentrale Produktion von RayBall-Leichtfüllstoffen. ROI-Rechner und Anlagen-Konfigurator.">`,
`<meta name="description" content="RayCube: modulare NIR-Expansionsanlage zur dezentralen Produktion von expandiertem Perlit (RayBall) – nicht brennbarer, mineralischer Leichtzuschlag und Dämmstoff aus Vulkansand. Ohne Fundament, 1–6 Module, ROI-Rechner und Anlagen-Konfigurator. ThermProTEC GmbH, Kehl.">
<meta name="keywords" content="Perlit-Expansionsanlage, Blähperlit Anlage, expandierter Perlit, expanded perlite plant, perlite expansion furnace, mineralischer Dämmstoff, Leichtzuschlag, Dämmstoff aus Vulkansand, NIR-Expansion, dezentrale Perlitproduktion, Blähglas Alternative, RayCube, RayBall, ThermProTEC">
<meta name="author" content="ThermProTEC GmbH">
<meta name="theme-color" content="#8C0000">
<link rel="canonical" href="https://raycube.de/">
<link rel="icon" type="image/png" href="assets/img/01-raycube.png">
<link rel="apple-touch-icon" href="assets/img/01-raycube.png">
<meta property="og:type" content="website">
<meta property="og:site_name" content="RayCube by ThermProTEC">
<meta property="og:locale" content="de_DE">
<meta property="og:locale:alternate" content="en_US">
<meta property="og:url" content="https://raycube.de/">
<meta property="og:title" content="RayCube – Perlit-Expansionsanlage für mineralische Dämmstoffe aus Vulkansand">
<meta property="og:description" content="Modulare NIR-Expansionsanlage für die dezentrale Produktion von expandiertem Perlit (RayBall). Ohne Fundament, 1–6 Module, ROI-Rechner und Konfigurator.">
<meta property="og:image" content="https://raycube.de/assets/img/03-raycube-anlage-3-module.jpg">
<meta name="twitter:card" content="summary_large_image">
<script type="application/ld+json">
{"@context":"https://schema.org","@graph":[
 {"@type":"Organization","@id":"https://www.thermprotec.com/#org","name":"ThermProTEC GmbH","url":"https://www.thermprotec.com","logo":"https://raycube.de/assets/img/02-thermprotec.png","email":"info@thermprotec.com","telephone":"+49-7854-98711-0",
  "address":{"@type":"PostalAddress","streetAddress":"Zunftstr. 20","postalCode":"77694","addressLocality":"Kehl-Marlen","addressCountry":"DE"}},
 {"@type":"WebSite","@id":"https://raycube.de/#website","url":"https://raycube.de/","name":"RayCube","inLanguage":["de","en"],"publisher":{"@id":"https://www.thermprotec.com/#org"}},
 {"@type":"Product","@id":"https://raycube.de/#raycube","name":"RayCube – Perlit-Expansionsanlage","brand":{"@type":"Brand","name":"RayCube"},"manufacturer":{"@id":"https://www.thermprotec.com/#org"},
  "description":"Modulare NIR-Expansionsanlage auf Containerplattform für die dezentrale Produktion von expandiertem Perlit (RayBall) – mineralischer Leichtzuschlag und Dämmstoff aus Vulkansand. Ausbaustufen 1, 3 und 6 Module, Aufstellung ohne Fundament.",
  "image":"https://raycube.de/assets/img/03-raycube-anlage-3-module.jpg","url":"https://raycube.de/#system","category":"Industrieanlagen / Baustofftechnik",
  "offers":{"@type":"Offer","availability":"https://schema.org/InStock","priceCurrency":"EUR","url":"https://raycube.de/#anfrage","description":"Investitionsrahmen auf Anfrage"}},
 {"@type":"Product","@id":"https://raycube.de/#rayball","name":"RayBall – expandierter Perlit","brand":{"@type":"Brand","name":"RayBall"},"manufacturer":{"@id":"https://www.thermprotec.com/#org"},
  "description":"Geschlossenzelliger, nicht brennbarer (A1) mineralischer Leichtzuschlag und Dämmstoff aus expandiertem Vulkansand mit verglaster Außenhaut.","url":"https://raycube.de/#produkt"}
]}
</script>`);

// ---------- 4. DE-Texte vorrendern (Inhalt ohne JS im HTML sichtbar -> Crawler, No-JS, LCP) ----------
const start = h.indexOf('const I18N = {');
const end = h.indexOf('\n};', start) + 3;
const I18N = new Function(h.slice(start, end) + '; return I18N;')();
let filled = 0;
h = h.replace(/<(\w+)([^>]*?\sdata-i18n="([\w]+)"[^>]*)><\/\1>/g, (m, tag, attrs, key) => {
  const v = I18N.de[key];
  if (typeof v !== 'string') return m;
  filled++;
  return `<${tag}${attrs}>${v}</${tag}>`;
});
console.log('i18n vorgerendert:', filled);

// ---------- 5. Sprache aus URL (?lang=en / #en) ----------
rep(`let LANG='de';`, `let LANG=(/[?&]lang=en\\b/.test(location.search)||location.hash==='#en')?'en':'de';`);

fs.writeFileSync(path.join(OUT, 'index.html'), h);
console.log('index.html:', (h.length/1024).toFixed(0), 'KB');
