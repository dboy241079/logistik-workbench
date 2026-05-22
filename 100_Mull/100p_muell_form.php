<?php
declare(strict_types=1);

$AUTH_DEFAULT_TAB   = 'special';                 // wie 100%-Prüfung
$AUTH_REQUIRE_LOGIN = true;
$AUTH_REQUIRE_EMBED = true;                      // nur aus der Workbench / iframe
$AUTH_ALLOWED_ROLES = ['admin','disposition','verpackung'];
$AUTH_DENY_MODE     = 'redirect';

require __DIR__ . '/../inc/auth_embed.php';
require __DIR__ . '/../api/_db.php';

// Mitarbeiterliste laden
$employees = [];
try {
    $stmt = $pdo->query("
      SELECT id, COALESCE(display_name, username, CONCAT('ID ', id)) AS name
      FROM users
      ORDER BY name
    ");
    $employees = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $employees = [];
}
?>
<!doctype html>
<html lang="de" class="h-full bg-slate-100">
<head>
  <meta charset="utf-8">
  <title>100% – Müll-Erfassung</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">

  <!-- Tailwind -->
  <script src="https://cdn.tailwindcss.com?plugins=forms,typography"></script>
  <script>
    tailwind.config = {
      theme: {
        extend: {
          colors: {
            brand: {
              light: '#e0f2fe',
              DEFAULT: '#0ea5e9',
              dark: '#0369a1'
            }
          }
        }
      }
    };
  </script>
</head>
<body class="min-h-full text-slate-900 text-base">
<main class="w-full px-3 sm:px-6 lg:px-10 py-4">
  <!-- Header -->
  <header class="mb-4">
    <div class="bg-white border border-slate-200 rounded-xl shadow-sm px-4 py-3 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
      <div>
        <div class="flex items-center gap-2">
          <span class="inline-flex h-9 w-9 items-center justify-center rounded-full bg-brand/10 text-brand">
            <!-- Icon -->
            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor">
              <path d="M5 3a2 2 0 00-2 2v1h14V5a2 2 0 00-2-2H5z" />
              <path fill-rule="evenodd" d="M4 8h12v7a2 2 0 01-2 2H6a2 2 0 01-2-2V8zm4 2a1 1 0 10-2 0v4a1 1 0 102 0v-4zm4-1a1 1 0 00-1 1v4a1 1 0 102 0v-4a1 1 0 00-1-1z" clip-rule="evenodd" />
            </svg>
          </span>
          <div>
            <h1 class="text-xl font-semibold text-slate-900">
              Müll-Erfassung (100%-Bereich)
            </h1>
            <p class="text-xs sm:text-sm text-slate-600">
              Entleerung Gitterbox / Holz / Karton / KLT inkl. Mitarbeiter und Zeiten.
            </p>
          </div>
        </div>
      </div>

      <div class="text-xs sm:text-sm text-slate-600">
        <span class="font-medium">Heute:</span>
        <span><?=htmlspecialchars(date('d.m.Y'))?></span>
      </div>
    </div>
  </header>

  <!-- Formular -->
  <section class="bg-white border border-slate-200 rounded-xl shadow-sm px-4 py-4">
    <form id="wasteForm" class="space-y-4">
      <!-- Form des Mülls -->
      <div class="flex flex-col gap-1.5 mb-3">
        <label class="text-sm font-medium text-slate-800">Form des Mülls</label>
        <select name="waste_type"
                class="rounded-md border-slate-300 text-base">
          <option value="">bitte wählen …</option>
          <option value="Entleerung Gibo">Entleerung Gitterbox 111 965</option>
          <option value="Entsorgung Holz">Entsorgung Holz (Paletten)</option>
          <option value="Entleerung Karton">Entleerung Karton (Behälter)</option>
          <option value="Entleerung KLT">Entleerung KLT (Belege aus Blister entfernen)</option>
        </select>
        <!-- Einheit wird dynamisch gesetzt (z.B. Gitterboxen, Paletten, …) -->
        <input type="hidden" name="unit" value="">
      </div>

      <!-- Zeile 2: Menge / Stapler -->
      <div class="grid gap-3 md:grid-cols-3">
        <div class="flex flex-col gap-1">
          <label class="text-sm font-medium text-slate-800">Anzahl / Menge</label>
          <input type="text"
                 name="quantity"
                 inputmode="decimal"
                 placeholder="z. B. 3"
                 class="rounded-md border-slate-300 text-base">
          <p id="qtyHelp" class="text-xs text-slate-500">
            z. B. Anzahl Einheiten.
          </p>
        </div>

        <div class="flex items-center gap-2 mt-5 md:mt-7">
          <input type="checkbox"
                 id="needs_forklift"
                 name="needs_forklift"
                 class="rounded border-slate-300 text-brand focus:ring-brand">
          <label for="needs_forklift"
                 class="text-sm font-medium text-slate-800">
            Stapler erforderlich
          </label>
        </div>

        <div></div>
      </div>

      <!-- Zeile 3: Mitarbeiter -->
      <div class="grid gap-3 md:grid-cols-3">
        <div class="flex flex-col gap-1.5">
          <label class="text-sm font-medium text-slate-800">Mitarbeiter (ausführend)</label>
          <select name="emp1_id"
                  class="rounded-md border-slate-300 text-base">
            <option value="">bitte wählen …</option>
            <?php foreach ($employees as $emp): ?>
              <option value="<?=$emp['id']?>"><?=htmlspecialchars($emp['name'])?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="flex flex-col gap-1.5">
          <label class="text-sm font-medium text-slate-800">zweiter Mitarbeiter (optional)</label>
          <select name="emp2_id"
                  class="rounded-md border-slate-300 text-base">
            <option value="">kein zweiter Mitarbeiter</option>
            <?php foreach ($employees as $emp): ?>
              <option value="<?=$emp['id']?>"><?=htmlspecialchars($emp['name'])?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="flex flex-col gap-1.5">
          <label class="text-sm font-medium text-slate-800">angeordnet durch</label>
          <select name="ordered_by_id"
                  class="rounded-md border-slate-300 text-base">
            <option value="">bitte wählen …</option>
            <?php foreach ($employees as $emp): ?>
              <option value="<?=$emp['id']?>"><?=htmlspecialchars($emp['name'])?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <!-- Zeile 4: Zeiten + kumulierte Stunden -->
      <div class="grid gap-3 md:grid-cols-3">
        <div class="flex flex-col gap-1.5">
          <label class="text-sm font-medium text-slate-800">von (Uhrzeit)</label>
          <input type="time"
                 name="time_start"
                 class="rounded-md border-slate-300 text-base">
        </div>

        <div class="flex flex-col gap-1.5">
          <label class="text-sm font-medium text-slate-800">bis (Uhrzeit)</label>
          <input type="time"
                 name="time_end"
                 class="rounded-md border-slate-300 text-base">
        </div>

        <div class="flex flex-col gap-1.5">
          <label class="text-sm font-medium text-slate-800">kumulierte Stunden</label>
          <input type="text"
                 name="hours"
                 readonly
                 class="rounded-md border-slate-200 bg-slate-50 text-base text-slate-700">
          <p class="text-xs text-slate-500">
            Wird automatisch aus Start- und Endzeit berechnet (Anzeige).
          </p>
        </div>
      </div>

      <!-- Kommentar -->
      <div class="flex flex-col gap-1.5">
        <label class="text-sm font-medium text-slate-800">Bemerkung (optional)</label>
        <textarea name="comment"
                  rows="2"
                  class="rounded-md border-slate-300 text-base"
                  placeholder="z. B. ‚3 Gibo 111 965 entleert, Stapler DT 834, Rampenbereich‘"></textarea>
      </div>

      <!-- Meldung + Buttons -->
      <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
        <p id="wasteMsg" class="text-sm text-slate-600"></p>

        <div class="flex gap-2 justify-end">
          <button type="reset"
                  class="inline-flex items-center rounded-md border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">
            Zurücksetzen
          </button>
          <button type="submit"
                  id="btnWasteSave"
                  class="inline-flex items-center rounded-md bg-brand text-white px-5 py-2 text-sm font-semibold shadow-sm hover:bg-brand-dark disabled:opacity-60 disabled:cursor-wait">
            Speichern
          </button>
        </div>
      </div>
    </form>
  </section>
</main>

<script>
  document.addEventListener('DOMContentLoaded', () => {
    const form    = document.getElementById('wasteForm');
    const btn     = document.getElementById('btnWasteSave');
    const msgBox  = document.getElementById('wasteMsg');

    if (!form || !btn) return;

    const timeFrom  = form.querySelector('input[name="time_start"]');
    const timeTo    = form.querySelector('input[name="time_end"]');
    const hoursInp  = form.querySelector('input[name="hours"]');
    const wasteSel  = form.querySelector('select[name="waste_type"]');
    const unitInp   = form.querySelector('input[name="unit"]');
    const qtyHelp   = document.getElementById('qtyHelp');

    function updateQtyHelp() {
      if (!qtyHelp || !wasteSel) return;
      const v = wasteSel.value;
      let text = 'z. B. Anzahl Einheiten.';

      switch (v) {
        case 'Entleerung Gibo':
          text = 'z. B. Anzahl entleerter Gitterboxen 111 965.';
          break;
        case 'Entsorgung Holz':
          text = 'z. B. Anzahl entsorgter Holzpaletten.';
          break;
        case 'Entleerung Karton':
          text = 'z. B. Anzahl entleerter Karton-Behälter.';
          break;
        case 'Entleerung KLT':
          text = 'z. B. Anzahl entleerter KLT / entfernte Belege.';
          break;
      }
      qtyHelp.textContent = text;
    }

    function updateUnit() {
      if (!unitInp || !wasteSel) return;
      const v = wasteSel.value;
      let unit = '';

      switch (v) {
        case 'Entleerung Gibo':
          unit = 'Gitterboxen';
          break;
        case 'Entsorgung Holz':
          unit = 'Holzpaletten';
          break;
        case 'Entleerung Karton':
          unit = 'Karton-Behälter';
          break;
        case 'Entleerung KLT':
          unit = 'KLT-Behälter';
          break;
        default:
          unit = '';
      }
      unitInp.value = unit;
    }

    function updateHours() {
      if (!timeFrom || !timeTo || !hoursInp) return;

      const start = timeFrom.value;
      const end   = timeTo.value;

      if (!start || !end) {
        hoursInp.value = '';
        return;
      }

      const [sh, sm] = start.split(':').map(Number);
      const [eh, em] = end.split(':').map(Number);

      const fromMin = sh * 60 + sm;
      const toMin   = eh * 60 + em;
      const diff    = toMin - fromMin;

      if (diff <= 0) {
        hoursInp.value = '';
        return;
      }

      const h = diff / 60;
      hoursInp.value = h.toFixed(2).replace('.', ',');
    }

    if (wasteSel) {
      wasteSel.addEventListener('change', () => {
        updateQtyHelp();
        updateUnit();
      });
      updateQtyHelp();
      updateUnit();
    }

    if (timeFrom && timeTo) {
      timeFrom.addEventListener('change', updateHours);
      timeTo.addEventListener('change', updateHours);
    }

    form.addEventListener('submit', async (e) => {
      e.preventDefault();

      msgBox.textContent = '';
      msgBox.className   = 'text-sm';

      const fd = new FormData(form);
      const body = new URLSearchParams();
      for (const [key, value] of fd.entries()) {
  body.append(key, String(value));
}

      btn.disabled = true;

      try {
        const res = await fetch('100p_muell_save_api.php', {
  method: 'POST',
  headers: {
    'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
  },
  body: body.toString()
});


        const j = await res.json().catch(() => null);

        if (!res.ok || !j || !j.ok) {
          const text = (j && j.msg) ? j.msg : 'Müll-Eintrag konnte nicht gespeichert werden.';
          msgBox.textContent = text;
          msgBox.classList.add('text-red-600');
          return;
        }

        msgBox.textContent = j.msg || 'Müll-Eintrag gespeichert.';
        msgBox.classList.add('text-emerald-600');

        // einige Felder merken und nach reset wieder setzen
        const wasteSelect    = form.elements['waste_type'];
        const emp1Select     = form.elements['emp1_id'];
        const orderedSelect  = form.elements['ordered_by_id'];

        const wasteVal   = wasteSelect ? wasteSelect.value : '';
        const emp1Val    = emp1Select ? emp1Select.value : '';
        const orderedVal = orderedSelect ? orderedSelect.value : '';

        form.reset();

        if (wasteSelect && wasteVal) {
          wasteSelect.value = wasteVal;
        }
        if (emp1Select && emp1Val) {
          emp1Select.value = emp1Val;
        }
        if (orderedSelect && orderedVal) {
          orderedSelect.value = orderedVal;
        }

        updateQtyHelp();
        updateUnit();
        if (hoursInp) hoursInp.value = '';

      } catch (err) {
        console.error(err);
        msgBox.textContent = 'Netzwerkfehler beim Speichern.';
        msgBox.classList.add('text-red-600');
      } finally {
        btn.disabled = false;
      }
    });
  });
</script>
</body>
</html>
