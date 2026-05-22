let PARTS = [];

async function loadPartsForAutocomplete() {
  try {
    const res = await fetch('/LKW/api/parts_api.php?action=list', {
      credentials: 'same-origin'
    });
    const j = await res.json();

    if (!j.ok) {
      console.error('API Fehler:', j);
      return;
    }

    PARTS = j.items.map(it => ({
      group: "", // optional, später für Lagergruppe
      part: it.sachnummer,
      norm: (it.sachnummer || "").toLowerCase().replace(/\s+/g, "")
    }));

    console.log('Sachnummern geladen:', PARTS.length);
  } catch (err) {
    console.error('Fetch/Parse Fehler:', err);
  }
}

document.addEventListener('DOMContentLoaded', loadPartsForAutocomplete);
