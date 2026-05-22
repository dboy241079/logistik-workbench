(async () => {
let PARTS = [];  // ganz oben im File, innerhalb der IIFE

try {
  const res = await fetch('/api/stammdaten_api.php?type=sachnummer&action=list', { credentials:'same-origin' });
  const j = await res.json();
  PARTS = j.ok ? j.items.map(it => ({
    group: "", // später Lagergruppe
    part: it.sachnummer,
    norm: (it.sachnummer || "").toLowerCase().replace(/\s+/g,'')
  })) : [];
} catch(e) {
  console.warn('Sachnummern-Load fehlgeschlagen:', e);
}
})();
