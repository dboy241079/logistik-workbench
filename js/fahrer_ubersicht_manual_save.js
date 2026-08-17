(() => {
  'use strict';

  const DRIVER_TAB_SELECTOR = '#tabF1, #tabF2, #tabF3';
  const EDITABLE_SELECTOR = 'td[contenteditable][data-field][data-veh][data-date][data-tour]';

  function isDriverCell(el) {
    return el instanceof HTMLElement &&
      el.matches(EDITABLE_SELECTOR) &&
      !!el.closest(DRIVER_TAB_SELECTOR);
  }

  function setStatus(message, type = 'secondary') {
    const el = document.getElementById('statusLbl');
    if (!el) return;

    const cls = {
      success: 'text-success',
      danger: 'text-danger',
      warning: 'text-warning',
      info: 'text-primary',
      secondary: 'text-muted',
    }[type] || 'text-muted';

    el.className = `text-end mt-3 small fw-semibold ${cls}`;
    el.textContent = message;
  }

  function normalizeTime(value) {
    let v = String(value ?? '').trim();
    if (!v) return '';

    v = v.replace(/\s+/g, '').replace(/\./g, ':');

    if (/^\d{1,2}:\d{2}$/.test(v)) {
      const [h, m] = v.split(':').map(Number);
      if (h >= 0 && h <= 23 && m >= 0 && m <= 59) {
        return `${String(h).padStart(2, '0')}:${String(m).padStart(2, '0')}`;
      }
      return v;
    }

    if (/^\d{1,2}$/.test(v)) {
      const h = Number(v);
      if (h >= 0 && h <= 23) return `${String(h).padStart(2, '0')}:00`;
      return v;
    }

    if (/^\d{3,4}$/.test(v)) {
      const h = Number(v.length === 3 ? v.slice(0, 1) : v.slice(0, 2));
      const m = Number(v.slice(-2));
      if (h >= 0 && h <= 23 && m >= 0 && m <= 59) {
        return `${String(h).padStart(2, '0')}:${String(m).padStart(2, '0')}`;
      }
    }

    return v;
  }

  async function readJsonResponse(res) {
    const text = await res.text();
    let data = {};
    try {
      data = text ? JSON.parse(text) : {};
    } catch {
      throw new Error(`Ungültige Serverantwort (HTTP ${res.status}): ${text.slice(0, 180) || 'leer'}`);
    }

    if (!res.ok || data.ok !== true) {
      throw new Error(data.error || data.msg || `HTTP ${res.status}`);
    }

    return data;
  }

  async function persistCell(td) {
    if (td.dataset.manualSaveBusy === '1') return;

    const { field, veh, date, tour } = td.dataset;
    if (!field || !veh || !date || !tour) {
      setStatus('❌ Speichern nicht möglich: Fahrzeug/Datum/Tour/Feld fehlt.', 'danger');
      td.style.background = '#f8d7da';
      return;
    }

    const raw = td.textContent.trim();
    const value = normalizeTime(raw);
    td.textContent = value;
    td.dataset.manualSaveBusy = '1';

    setStatus(`⏳ Speichere ${veh} · ${date} · Tour ${tour} · ${field} = ${value || 'leer'} …`, 'info');

    try {
      const res = await fetch('/api/update_time.php', {
        method: 'POST',
        credentials: 'include',
        cache: 'no-store',
        headers: {
          'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
          'Accept': 'application/json',
        },
        body: new URLSearchParams({
          veh_id: veh,
          date,
          tour,
          field,
          value,
        }),
      });

      await readJsonResponse(res);

      // Direkt aus derselben Datenquelle zurücklesen, die beim Neuladen benutzt wird.
      const verifyRes = await fetch(
        `/api/get_day.php?veh_id=${encodeURIComponent(veh)}&date=${encodeURIComponent(date)}&_=${Date.now()}`,
        {
          credentials: 'include',
          cache: 'no-store',
          headers: { 'Accept': 'application/json' },
        }
      );
      const verify = await readJsonResponse(verifyRes);
      const row = Array.isArray(verify.rows)
        ? verify.rows.find(r => String(r.tour) === String(tour))
        : null;

      if (!row) {
        throw new Error('Gespeichert, aber Tour konnte beim Kontroll-Lesen nicht gefunden werden.');
      }

      const serverValue = String(row[field] ?? '');
      if (serverValue !== value) {
        throw new Error(`Kontroll-Lesen abweichend: Server liefert „${serverValue || 'leer'}“ statt „${value || 'leer'}“.`);
      }

      td.style.background = '#d1e7dd';
      setTimeout(() => {
        if (td.isConnected) td.style.background = '';
      }, 1000);

      const time = new Date().toLocaleTimeString('de-DE', {
        hour: '2-digit', minute: '2-digit', second: '2-digit'
      });
      setStatus(`✅ Gespeichert & geprüft · ${veh} · ${date} · Tour ${tour} · ${field} = ${value || 'leer'} · ${time}`, 'success');
    } catch (err) {
      console.error('[Fahrer manuell speichern]', err);
      td.style.background = '#f8d7da';
      const msg = err instanceof Error ? err.message : String(err);
      setStatus(`❌ Speichern fehlgeschlagen: ${msg}`, 'danger');
    } finally {
      delete td.dataset.manualSaveBusy;
    }
  }

  // Dieser Listener wird beim Laden der Workbench registriert, bevor das dynamische
  // Fahrer-Modul importiert wird. Damit übernimmt er gezielt die manuelle Speicherung
  // und verhindert einen zweiten, alten blur-Speicherlauf für dieselbe Zelle.
  document.addEventListener('blur', (event) => {
    const td = event.target instanceof HTMLElement
      ? event.target.closest(EDITABLE_SELECTOR)
      : null;
    if (!td || !isDriverCell(td)) return;

    event.stopImmediatePropagation();
    void persistCell(td);
  }, true);

  // Enter bestätigt den Wert wie in einer Tabelle, statt eine neue Zeile in der Zelle anzulegen.
  document.addEventListener('keydown', (event) => {
    const td = event.target instanceof HTMLElement
      ? event.target.closest(EDITABLE_SELECTOR)
      : null;
    if (!td || !isDriverCell(td)) return;

    if (event.key === 'Enter') {
      event.preventDefault();
      td.blur();
    }
  }, true);

  window.FahrerManualSave = {
    saveCell: persistCell,
  };
})();
