<?php
declare(strict_types=1);

require __DIR__ . '/../inc/session.php';
require __DIR__ . '/../api/_db.php'; // <-- PDO muss vor dem Guard da sein

// Rollen dynamisch pro Tab aus DB holen
function allowed_roles_for_tab(PDO $pdo, string $tabKey): array {
  $st = $pdo->prepare("SELECT role FROM app_tab_roles WHERE tab_key = :t");
  $st->execute([':t' => $tabKey]);
  $roles = $st->fetchAll(PDO::FETCH_COLUMN);

  $roles = array_values(array_unique(array_filter($roles, fn($r) => is_string($r) && $r !== '')));
  return $roles ?: ['admin']; // Fallback
}

// ================= Auth / Guard =================
$TAB_KEY = 'special';

$AUTH_DEFAULT_TAB   = $TAB_KEY;
$AUTH_REQUIRE_LOGIN = true;
$AUTH_REQUIRE_EMBED = false;
$AUTH_ALLOWED_ROLES = allowed_roles_for_tab($pdo, $TAB_KEY);
$AUTH_DENY_MODE     = 'redirect';

require __DIR__ . '/../inc/auth_embed.php';


// Overlap-Hinweis aus der Session holen (falls vorhanden)
$timeOverlap = $_SESSION['time_overlap'] ?? null;
if ($timeOverlap) {
    // nach dem ersten Anzeigen wieder entfernen
    unset($_SESSION['time_overlap']);
}

// Form-Prefill nach Zeit-Overlap (alle Felder außer Zeiten)
$overlapForm = $_SESSION['time_overlap_form'] ?? null;
if ($overlapForm) {
    // Zeiten bewusst leer lassen -> Mitarbeiter soll neue Zeit eingeben
    $overlapForm['time_start'] = '';
    $overlapForm['time_end']   = '';
    unset($_SESSION['time_overlap_form']);
}

// Hilfswerte für Selects / Radios
$overlapReason      = $overlapForm['reason']      ?? '';
$overlapEmployeeId  = $overlapForm['employee_id'] ?? null;
$resultValue        = $overlapForm['result']      ?? 'OK';
$selectedEmployeeId = $overlapEmployeeId ?? ($currentUserId ?? null);

// Mitarbeiter laden
$users = [];
try {
    $stmt = $pdo->query("
        SELECT id, COALESCE(display_name, username) AS name
        FROM users
        WHERE active = 1
        ORDER BY name
    ");
    $users = $stmt->fetchAll();
} catch (Throwable $e) {
    $users = [];
}



$currentUserId = $_SESSION['user_id'] ?? null;

// Daten für evtl. zusätzliche 100%-Prüfung nach Etikettierung
$prefill100 = $_SESSION['prefill_100p'] ?? null;
$ask100     = !empty($_GET['ask100']) && !empty($prefill100);
$last100    = $_SESSION['last_100p'] ?? null;

// Offene Paletten ...
$openPallets = [];
try {
    $stmt = $pdo->query("
        SELECT
            pallet_code,
            MIN(created_at) AS started_at,
            MAX(created_at) AS last_at,
            MAX(
              CASE 
                WHEN reason IN ('Umpacken auf Palette', 'Umfüllung in KLT')
                  THEN 1 ELSE 0
              END
            ) AS has_ump,
            MAX(CASE WHEN reason = 'Etikettierung KLT' THEN 1 ELSE 0 END) AS has_klt,
            MAX(CASE WHEN reason = '100% Prüfung'      THEN 1 ELSE 0 END) AS has_100,
            SUM(CASE WHEN reason = 'Etikettierung KLT' THEN COALESCE(klt_count,0) ELSE 0 END) AS klt_sum,
            MAX(delivery_note) AS delivery_note,
            MAX(material_no)   AS material_no
        FROM qc_100_pruefungen
        WHERE DATE(created_at) >= (CURDATE() - INTERVAL 7 DAY)
GROUP BY pallet_code
HAVING 
  (
    has_ump = 1
    AND (has_klt = 0 OR has_100 = 0)
  )
  OR (
    has_ump = 0
    AND has_klt = 1
    AND has_100 = 0
  )
ORDER BY last_at DESC
LIMIT 50

    ");
    $openPallets = $stmt->fetchAll();
} catch (Throwable $e) {
    $openPallets = [];
}

$today = new DateTimeImmutable('today');
$todayDate = $today->format('Y-m-d');

// Tages-Aufgaben (Bedarf) + bereits erledigte Paletten aus qc_100_pruefungen
$packTasks = [];
try {
    $stmt = $pdo->prepare("
      SELECT 
        n.*,
        COUNT(q.id) AS pallet_done   -- ✅ Anzahl erledigter Paletten
      FROM qc_100_pack_needs n
      LEFT JOIN qc_100_pruefungen q
        ON q.material_no = n.material_no
       AND q.reason      = n.reason
       AND DATE(q.created_at) = n.need_date
      WHERE n.need_date = :d
      GROUP BY 
        n.id,
        n.need_date,
        n.hall,
        n.material_no,
        n.reason,
        n.klt_target,
        n.comment,
        n.created_at,
        n.created_by
      ORDER BY n.hall, n.material_no
    ");
    $stmt->execute([':d' => $todayDate]);
    $packTasks = $stmt->fetchAll();
} catch (Throwable $e) {
    $packTasks = [];
}
if (!empty($packTasks)) {
    $packTasks = array_values(array_filter(
        $packTasks,
        function (array $t): bool {
            $target = (int)($t['klt_target']   ?? 0); // geplante Paletten
            $done   = (int)($t['pallet_done'] ?? 0); // erledigte Paletten

            return $target > 0 && $done < $target;
        }
    ));
}


?>
<!doctype html>
<html lang="de" class="h-full bg-slate-100">
<head>
  <meta charset="utf-8">
  <title>100%-Prüfung erfassen</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">

  <!-- Tailwind + Forms -->
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
  <style>
    html { font-size: 1.1rem; } /* alles etwas größer */
  </style>
</head>
<body class="min-h-full text-base text-slate-900">

  <!-- Volle Sichtbreite, mit etwas Rand -->
  <div class="w-full py-4 px-3 sm:px-6 lg:px-10">
    <header class="mb-4">
      <h1 class="text-2xl font-semibold text-slate-900">
        Sondertätigkeiten Verpackung
      </h1>
      <p class="mt-1 text-sm text-slate-600">
        Erfassung inkl. Mitarbeiter, Grund, Zeiten und Foto – direkt digital.
      </p>
    </header>

    <?php if ($last100): ?>
      <div class="mb-3 inline-flex items-center rounded-full border border-slate-200 bg-white px-3 py-1 text-xs text-slate-600 shadow-sm">
        <span class="mr-1 text-slate-400">Letzte Palette:</span>
        <span class="font-medium mr-2"><?=htmlspecialchars($last100['pallet_code'] ?? '')?></span>
        <?php if (!empty($last100['reason'])): ?>
          <span class="mr-2 text-slate-500">(<?=htmlspecialchars($last100['reason'])?><?php if (!empty($last100['klt_count'])): ?> – <?= (int)$last100['klt_count']?> KLT<?php endif; ?>)</span>
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <?php if (!empty($_GET['ok'])): ?>
      <div class="mb-4 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-2 text-sm text-emerald-800">
        Prüfung wurde gespeichert.
      </div>
    <?php endif; ?>

    <?php if ($packTasks): ?>
  <section class="mb-4 rounded-xl border border-indigo-200 bg-indigo-50 px-4 py-3 text-sm">
    <div class="flex items-center justify-between gap-2 mb-2">
      <div class="flex items-center gap-2">
        <span class="inline-flex h-6 w-6 items-center justify-center rounded-full bg-indigo-500 text-white text-xs font-bold">
          📦
        </span>
        <span class="font-semibold text-indigo-900">
          Tages-Aufgaben Verpackung (vom Dashboard geplant)
        </span>
      </div>
      <span class="text-xs text-indigo-800">
        <?=count($packTasks)?> Positionen für <?=$todayDate?>
      </span>
    </div>

    <div class="overflow-x-auto -mx-2">
      <table class="min-w-full border-collapse text-[12px] text-indigo-900">
        <thead>
          <tr class="border-b border-indigo-200 text-[11px] uppercase tracking-wide text-indigo-600">
            <th class="px-2 py-1 text-left">Halle</th>
            <th class="px-2 py-1 text-left">Sachnummer</th>
            <th class="px-2 py-1 text-left">Vorgang</th>
            <th class="px-2 py-1 text-right">Soll Paletten</th>
            <th class="px-2 py-1 text-right">Erledigt Paletten</th>
            <th class="px-2 py-1 text-right">Rest Paletten</th>
            <th class="px-2 py-1 text-left">Kommentar</th>
            <th class="px-2 py-1 text-left">Aktion</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($packTasks as $t):

            // Geplante / erledigte / offene Paletten
            $target = (int)($t['klt_target']   ?? 0);  // Anzahl Paletten
            $done   = (int)($t['pallet_done'] ?? 0);  // erledigte Paletten
            $rest   = max(0, $target - $done);

            if ($rest <= 0) {
              continue; // nur offene Paletten anzeigen
            }

            $restClass = $rest > 0
              ? 'text-amber-700 font-semibold'
              : 'text-slate-500';

            // ❗Hier: Sachnummer so oft anzeigen, wie noch Paletten offen sind
            for ($i = 0; $i < $rest; $i++):
          ?>
            <tr class="border-b border-indigo-100/80">
              <td class="px-2 py-1 whitespace-nowrap">
                <?=htmlspecialchars($t['hall'] ?? '-')?>
              </td>
              <td class="px-2 py-1 whitespace-nowrap font-medium">
                <?=htmlspecialchars($t['material_no'])?>
              </td>
              <td class="px-2 py-1 whitespace-nowrap">
                <?=htmlspecialchars($t['reason'])?>
              </td>
              <td class="px-2 py-1 text-right">
                <?=$target?>
              </td>
              <td class="px-2 py-1 text-right text-emerald-700">
                <?=$done?>
              </td>
              <td class="px-2 py-1 text-right <?=$restClass?>">
                <?=$rest?>
              </td>
              <td class="px-2 py-1">
                <?=htmlspecialchars($t['comment'] ?? '')?>
              </td>
              <td class="px-2 py-1">
                <button type="button"
                        class="inline-flex items-center rounded-md border border-indigo-300 bg-white px-2 py-0.5 text-[11px] font-medium text-indigo-800 hover:bg-indigo-100"
                        data-pack-task="1"
                        data-material="<?=htmlspecialchars($t['material_no'])?>"
                        data-reason="<?=htmlspecialchars($t['reason'])?>"
                        data-rest="<?=$rest?>">
                  Übernehmen
                </button>
              </td>
            </tr>
          <?php endfor; endforeach; ?>
        </tbody>
      </table>
    </div>
  </section>
<?php endif; ?>


        <?php if ($openPallets): ?>
      <section class="mb-4 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm">
        <div class="flex items-center justify-between gap-2 mb-2">
          <div class="flex items-center gap-2">
            <span class="inline-flex h-6 w-6 items-center justify-center rounded-full bg-amber-500 text-white text-xs font-bold">
              !
            </span>
            <span class="font-semibold text-amber-900">
              Offene Paletten (Workflow noch nicht komplett)
            </span>
          </div>
          <span class="text-xs text-amber-800">
            <?=count($openPallets)?> offen (Umpacken erledigt, Etikettierung oder 100%-Prüfung fehlt)
          </span>
        </div>

        <div class="overflow-x-auto -mx-2">
          <table class="min-w-full border-collapse text-[12px] text-amber-900">
            <thead>
              <tr class="border-b border-amber-200 text-[11px] uppercase tracking-wide text-amber-600">
                <th class="px-2 py-1 text-left">Palette / Prüflabel</th>
                <th class="px-2 py-1 text-left">Lieferschein</th>
                <th class="px-2 py-1 text-left">Sachnummer</th>
                <th class="px-2 py-1 text-left">Umpacken</th>
                <th class="px-2 py-1 text-left">Etikettierung</th>
                <th class="px-2 py-1 text-left">100% Prüfung</th>
                <th class="px-2 py-1 text-left">KLT gesamt</th>
                <th class="px-2 py-1 text-left">Letzte Aktivität</th>
                <th class="px-2 py-1 text-left">Aktion</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($openPallets as $op): 
                $last = new DateTimeImmutable($op['last_at']);
              ?>
                <tr class="border-b border-amber-100/80">
                  <!-- Palette -->
                  <td class="px-2 py-1 font-medium whitespace-nowrap">
                    <?=htmlspecialchars($op['pallet_code'])?>
                  </td>

                  <!-- Lieferschein -->
                  <td class="px-2 py-1 whitespace-nowrap">
                    <?=htmlspecialchars($op['delivery_note'] ?? '')?>
                  </td>

                  <!-- Sachnummer -->
                  <td class="px-2 py-1 whitespace-nowrap">
                    <?=htmlspecialchars($op['material_no'] ?? '')?>
                  </td>

                  <!-- Umpacken -->
                  <td class="px-2 py-1">
                    <?php if ($op['has_ump']): ?>
                      <span class="inline-flex items-center rounded-full bg-emerald-100 px-2 py-0.5 text-[11px] font-semibold text-emerald-700">
                        ✔ erledigt
                      </span>
                    <?php else: ?>
                      <span class="inline-flex items-center rounded-full bg-slate-100 px-2 py-0.5 text-[11px] text-slate-500">
                        – fehlt –
                      </span>
                    <?php endif; ?>
                  </td>

                  <!-- Etikettierung -->
                  <td class="px-2 py-1">
                    <?php if ($op['has_klt']): ?>
                      <span class="inline-flex items-center rounded-full bg-sky-100 px-2 py-0.5 text-[11px] font-semibold text-sky-700">
                        ✔ Etikettiert
                      </span>
                    <?php else: ?>
                      <span class="inline-flex items-center rounded-full bg-slate-100 px-2 py-0.5 text-[11px] text-slate-500">
                        – offen –
                      </span>
                    <?php endif; ?>
                  </td>

                  <!-- 100% Prüfung -->
                  <td class="px-2 py-1">
                    <?php if ($op['has_100']): ?>
                      <span class="inline-flex items-center rounded-full bg-emerald-100 px-2 py-0.5 text-[11px] font-semibold text-emerald-700">
                        ✔ geprüft
                      </span>
                    <?php else: ?>
                      <span class="inline-flex items-center rounded-full bg-slate-100 px-2 py-0.5 text-[11px] text-slate-500">
                        – offen –
                      </span>
                    <?php endif; ?>
                  </td>

                  <!-- KLT gesamt -->
                  <td class="px-2 py-1">
                    <?php if ((int)$op['klt_sum'] > 0): ?>
                      <span class="inline-flex items-center rounded-full bg-sky-50 px-2 py-0.5 text-[11px] font-medium text-sky-700">
                        <?=$op['klt_sum']?> KLT
                      </span>
                    <?php else: ?>
                      <span class="text-[11px] text-slate-400">–</span>
                    <?php endif; ?>
                  </td>

                  <!-- Letzte Aktivität -->
                  <td class="px-2 py-1 whitespace-nowrap text-[11px] text-amber-800">
                    <?=$last->format('d.m.Y H:i')?>
                  </td>

                  <!-- Aktion: Palette + LS + SN ins Formular übernehmen -->
                  <td class="px-2 py-1">
                    <button type="button"
        class="inline-flex items-center rounded-md border border-amber-300 bg-white px-2 py-0.5 text-[11px] font-medium text-amber-800 hover:bg-amber-100"
        data-fill-pallet="<?=htmlspecialchars($op['pallet_code'])?>"
        data-fill-delivery="<?=htmlspecialchars($op['delivery_note'] ?? '')?>"
        data-fill-material="<?=htmlspecialchars($op['material_no'] ?? '')?>"
        data-has-ump="<?= (int)$op['has_ump'] ?>"
        data-has-klt="<?= (int)$op['has_klt'] ?>"
        data-has-100="<?= (int)$op['has_100'] ?>">
  Übernehmen
</button>

                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </section>
    <?php endif; ?>



    <!-- Formular-Card -->
    <form action="100p_save.php"
      method="post"
      enctype="multipart/form-data"
      class="bg-white border border-slate-200 rounded-xl shadow-sm p-4 sm:p-6 w-full"
      id="qcForm">


      <div class="grid gap-4 md:grid-cols-2">
        <!-- Paletten- / Prüflabel -->
        <div class="md:col-span-2">
          <label class="block text-sm font-medium text-slate-800 mb-1">
            Paletten- / Prüflabel (Barcode)
          </label>
          <input type="text"
       name="pallet_code"
       class="block w-full rounded-md border border-slate-300 px-3 py-2 text-base shadow-sm focus:outline-none focus:ring-2 focus:ring-brand focus:border-brand"
       value="<?=htmlspecialchars($overlapForm['pallet_code'] ?? '')?>"
       required
       autofocus>

          <p class="mt-1 text-xs text-slate-500">
            Scan mit dem Handscanner – Formular wird NICHT automatisch abgeschickt.
          </p>
        </div>

        <!-- Lieferschein -->
        <div>
          <label class="block text-sm font-medium text-slate-800 mb-1">
            Lieferschein / Frachtbrief
          </label>
          <input type="text"
       name="delivery_note"
       class="block w-full rounded-md border border-slate-300 px-3 py-2 text-base shadow-sm focus:outline-none focus:ring-2 focus:ring-brand focus:border-brand"
       value="<?=htmlspecialchars($overlapForm['delivery_note'] ?? '')?>">

        </div>

        <!-- Sachnummer mit Autocomplete -->
        <div class="relative">
          <label class="block text-sm font-medium text-slate-800 mb-1">
            Sachnummer
          </label>
          <input type="text"
       name="material_no"
       id="material_no"
       autocomplete="off"
       placeholder="Sachnummer tippen oder scannen..."
       class="block w-full rounded-md border border-slate-300 px-3 py-2 text-base shadow-sm focus:outline-none focus:ring-2 focus:ring-brand focus:border-brand"
       value="<?=htmlspecialchars($overlapForm['material_no'] ?? '')?>">


          <div id="snSuggestions"
               class="absolute left-0 right-0 mt-1 bg-white border border-slate-200 rounded-md shadow-lg"
               style="z-index:1000; max-height:220px; overflow-y:auto; display:none;"></div>
        </div>
        
        <!-- Grund der Prüfung -->
        <div>
          <label class="block text-sm font-medium text-slate-800 mb-1">
            Grund der Prüfung
          </label>
          <select name="reason"
        required
        class="block w-full rounded-md border border-slate-300 px-3 py-2 text-base shadow-sm bg-white focus:outline-none focus:ring-2 focus:ring-brand focus:border-brand">
      <option value="">– bitte wählen –</option>
      <option value="100% Prüfung"
        <?=$overlapReason === '100% Prüfung' ? 'selected' : ''?>>
        100% Prüfung
      </option>
      <option value="Etikettierung KLT"
        <?=$overlapReason === 'Etikettierung KLT' ? 'selected' : ''?>>
        Etikettierung KLT
      </option>
      <option value="Umpacken auf Palette"
        <?=$overlapReason === 'Umpacken auf Palette' ? 'selected' : ''?>>
        Umpacken auf Palette
      </option>
      <option value="Umfüllung in KLT"
        <?=$overlapReason === 'Umfüllung in KLT' ? 'selected' : ''?>>
        Umfüllung in KLT
      </option>
     </select>

        </div>

            <!-- Anzahl KLT (bei KLT-Vorgängen) -->
        <div id="kltCountWrapper" class="hidden">
        <label class="block text-sm font-medium text-slate-800 mb-1">
          Anzahl der KLTs
        </label>
        <input type="number"
            name="klt_count"
            min="0"
            step="1"
            class="block w-full rounded-md border border-slate-300 px-3 py-2 text-base shadow-sm focus:outline-none focus:ring-2 focus:ring-brand focus:border-brand"
            value="<?=htmlspecialchars((string)($overlapForm['klt_count'] ?? ''))?>">

        <p class="mt-1 text-xs text-slate-500">
          Wird bei „Etikettierung KLT“, „Umpacken auf Palette“ und „Umfüllung in KLT“ benötigt.
        </p>
        </div>

      <!-- NEU: Anzahl pro KLT (nur bei Umfüllung in KLT) -->
      <div id="qtyPerKltWrapper" class="hidden">
        <label class="block text-sm font-medium text-slate-800 mb-1">
          Anzahl pro KLT
        </label>
        <input type="number"
            name="qty_per_klt"
            min="0"
            step="1"
            class="block w-full rounded-md border border-slate-300 px-3 py-2 text-base shadow-sm focus:outline-none focus:ring-2 focus:ring-brand focus:border-brand"
            value="<?=htmlspecialchars((string)($overlapForm['qty_per_klt'] ?? ''))?>">

        <p class="mt-1 text-xs text-slate-500">
          Nur ausfüllen, wenn „Umfüllung in KLT“ gewählt ist.
        </p>
      </div>


        <!-- Mitarbeiter -->
        <div>
          <label class="block text-sm font-medium text-slate-800 mb-1">
            Mitarbeiter
          </label>
          <select name="employee_id"
                  required
                  class="block w-full rounded-md border border-slate-300 px-3 py-2 text-base shadow-sm bg-white focus:outline-none focus:ring-2 focus:ring-brand focus:border-brand">
            <option value="">– bitte wählen –</option>
            <?php foreach ($users as $u): ?>
              <option value="<?=$u['id']?>"
                <?=$currentUserId == $u['id'] ? 'selected' : ''?>>
                <?=htmlspecialchars($u['name'])?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>

              <div>
        <label class="block text-sm font-medium text-slate-800 mb-1">
          Zeit Start
        </label>
        <input type="time"
              name="time_start"
              step="60"
              min="06:00"
              max="21:00"
              class="block w-full rounded-md border border-slate-300 px-3 py-2 text-base shadow-sm focus:outline-none focus:ring-2 focus:ring-brand focus:border-brand">
      </div>
      <div>
        <label class="block text-sm font-medium text-slate-800 mb-1">
          Zeit Ende
        </label>
        <input type="time"
              name="time_end"
              step="60"
              min="06:00"
              max="21:00"
              class="block w-full rounded-md border border-slate-300 px-3 py-2 text-base shadow-sm focus:outline-none focus:ring-2 focus:ring-brand focus:border-brand">
      </div>

            </div>

      <p class="mt-1 text-xs text-slate-500">
        Wenn Start und Ende angegeben sind, wird die Dauer automatisch in Minuten berechnet.
      </p>

      <!-- Ergebnis -->
      <div class="mt-4">
        <span class="block text-sm font-medium text-slate-800 mb-1">
          Ergebnis
        </span>
        <div class="inline-flex rounded-lg border border-slate-200 bg-slate-50 p-0.5">
          <label class="inline-flex items-center px-3 py-1.5 cursor-pointer">
            <input type="radio"
       name="result"
       id="resOk"
       value="OK"
       class="h-4 w-4 text-emerald-600 border-slate-300 focus:ring-emerald-500"
       <?= $resultValue === 'OK' ? 'checked' : '' ?>>
            <span class="ml-2 text-sm text-slate-800">OK</span>
          </label>
          <label class="inline-flex items-center px-3 py-1.5 cursor-pointer">
            <input type="radio"
       name="result"
       id="resBad"
       value="ABWEICHUNG"
       class="h-4 w-4 text-red-600 border-slate-300 focus:ring-red-500"
       <?= $resultValue === 'ABWEICHUNG' ? 'checked' : '' ?>>
            <span class="ml-2 text-sm text-slate-800">Abweichung</span>
          </label>
        </div>
      </div>

      <!-- Kommentar -->
      <div class="mt-4">
        <label class="block text-sm font-medium text-slate-800 mb-1">
          Kommentar <span class="text-xs text-slate-500">(Pflicht bei Abweichung)</span>
        </label>
        <textarea name="comment"
          rows="2"
          class="block w-full rounded-md border border-slate-300 px-3 py-2 text-base shadow-sm focus:outline-none focus:ring-2 focus:ring-brand focus:border-brand"><?=htmlspecialchars($overlapForm['comment'] ?? '')?></textarea>

      </div>

      <!-- Foto -->
      <div class="mt-4">
        <label class="block text-sm font-medium text-slate-800 mb-1">
          Foto aufnehmen
        </label>
        <input type="file"
               name="photo"
               accept="image/*"
               capture="environment"
               class="block w-full rounded-md border border-slate-300 px-3 py-2 text-base shadow-sm bg-white focus:outline-none focus:ring-2 focus:ring-brand focus:border-brand">
        <p class="mt-1 text-xs text-slate-500">
          Ideal: Bild der geprüften Palette / Etiketten als Nachweis.
        </p>
      </div>

      <!-- Absenden -->
      <div class="mt-6">
        <button type="submit"
                class="inline-flex w-full items-center justify-center rounded-md bg-brand px-5 py-2.5 text-base font-semibold text-white shadow-sm hover:bg-brand-dark focus:outline-none focus:ring-2 focus:ring-brand focus:ring-offset-1">
          Speichern
        </button>
      </div>
    </form>
  </div>

<iframe
  src="/100_Mull/100p_muell_form.php?embed=1"
  class="w-full h-[80vh] border-0 rounded-xl shadow-sm">
</iframe>




<?php if ($ask100): ?>
  <!-- Overlay: Frage nach zusätzlicher 100%-Prüfung -->
  <div id="ask100Overlay"
       class="fixed inset-0 z-40 flex items-center justify-center bg-black/50">
    <div class="max-w-md w-[90%] rounded-xl bg-white shadow-xl p-4 sm:p-5">
      <h2 class="text-lg font-semibold text-slate-900 mb-2">
        100%-Prüfung durchgeführt?
      </h2>
      <p id="ask100Text" class="text-sm text-slate-600 mb-4">
        <!-- Text wird per JS gesetzt -->
      </p>

      <div class="flex flex-col sm:flex-row sm:justify-end gap-2">
        <button type="button"
                id="ask100No"
                class="inline-flex items-center justify-center rounded-md border border-slate-300 bg-white px-3 py-1.5 text-sm font-medium text-slate-700 hover:bg-slate-50">
          Nein
        </button>
        <button type="button"
                id="ask100Yes"
                class="inline-flex items-center justify-center rounded-md bg-brand px-3 py-1.5 text-sm font-semibold text-white hover:bg-brand-dark">
          Ja
        </button>
      </div>
    </div>
  </div>
<?php endif; ?>


<script>
const ASK_100 = <?php echo $ask100 ? 'true' : 'false'; ?>;
const PREFILL_100 = <?php echo $prefill100
    ? json_encode($prefill100, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT)
    : 'null'; ?>;

// === Kommentar + Zeiten + KLT prüfen ===
document.getElementById('qcForm').addEventListener('submit', function (e) {
  const resBad   = document.getElementById('resBad');
  const comment  = this.elements['comment'].value.trim();
  const start    = this.elements['time_start'].value;
  const end      = this.elements['time_end'].value;
  const reason   = this.elements['reason'] ? this.elements['reason'].value : '';
  const kltInput = this.elements['klt_count'];

  // Kommentar-Pflicht bei Abweichung
  if (resBad.checked && comment === '') {
    e.preventDefault();
    alert('Bitte bei Abweichung einen Kommentar eingeben.');
    return;
  }

    // Zeit-Validierung
  if ((start && !end) || (!start && end)) {
    e.preventDefault();
    alert('Bitte Start- UND Endzeit angeben (oder beide leer lassen).');
    return;
  }

  if (start && end) {
    if (end <= start) {
      e.preventDefault();
      alert('Endzeit muss nach der Startzeit liegen.');
      return;
    }

    // NEU: Zeiten nur zwischen 07:00 und 20:00 Uhr zulassen
    const MIN = '06:00';
    const MAX = '21:00';

    if (start < MIN || end > MAX) {
      e.preventDefault();
      alert('Bitte Zeiten nur im Zeitraum von 07:00 bis 20:00 Uhr erfassen.');
      return;
    }
  }


  // KLT-Anzahl bei Etikettierung/Umpacken/Umfüllung prüfen
if (
  (reason === 'Etikettierung KLT' ||
   reason === 'Umpacken auf Palette' ||
   reason === 'Umfüllung in KLT') &&
  kltInput
) {
  const val = kltInput.value.trim();
  if (val === '' || Number(val) <= 0) {
    e.preventDefault();
    alert('Bitte die Anzahl der KLTs angeben.');
    return;
  }
}

// Zusätzliche Pflicht bei Umfüllung: Anzahl pro KLT
const qtyInput = this.elements['qty_per_klt'];
if (reason === 'Umfüllung in KLT' && qtyInput) {
  const val2 = qtyInput.value.trim();
  if (val2 === '' || Number(val2) <= 0) {
    e.preventDefault();
    alert('Bitte die Anzahl pro KLT angeben.');
    return;
  }
}

});

// === Enter bei Scans nicht automatisch Formular absenden ===
(function () {
  const form = document.getElementById('qcForm');
  if (!form) return;

  // Reihenfolge der Felder, durch die man beim Enter gehen soll
    const order = [
    'pallet_code',
    'delivery_note',
    'material_no',
    'reason',
    'klt_count',
    'qty_per_klt',   // NEU
    'employee_id',
    'time_start',
    'time_end',
    'comment'
  ];


    form.addEventListener('keydown', (e) => {
    if (e.key !== 'Enter') return;
    const target = e.target;
    if (target.tagName === 'TEXTAREA') return;
    e.preventDefault();

    const name = target.name;
    let idx    = order.indexOf(name);
    if (idx === -1) return;

    let nextName = order[idx + 1];

    // Nach "reason" abhängig vom Grund verzweigen
    if (name === 'reason') {
      const val = form.elements['reason'].value;
      if (
        val === 'Etikettierung KLT' ||
        val === 'Umpacken auf Palette' ||
        val === 'Umfüllung in KLT'
      ) {
        nextName = 'klt_count';
      } else {
        nextName = 'employee_id';
      }
    } else if (name === 'klt_count') {
      const val = form.elements['reason'].value;
      // Nur bei Umfüllung weiter zu qty_per_klt, sonst direkt Mitarbeiter
      if (val === 'Umfüllung in KLT') {
        nextName = 'qty_per_klt';
      } else {
        nextName = 'employee_id';
      }
    }

    if (!nextName) return;
    const nextEl = form.elements[nextName];
    if (nextEl && typeof nextEl.focus === 'function') {
      nextEl.focus();
    }
  });

})();

// === Sachnummern-Live-Autocomplete über deine Stammdaten-API ===
(function () {
  const input = document.getElementById('material_no');
  const box   = document.getElementById('snSuggestions');
  let timer   = null;

  if (!input || !box) return;

  const API = '/api/stammdaten_api.php';

  async function apiListSachnummern(q) {
    const url = new URL(API, location.origin);
    url.searchParams.set('type', 'sachnummer');
    url.searchParams.set('action', 'list');
    if (q) url.searchParams.set('q', q);

    const res = await fetch(url, { credentials: 'same-origin', cache: 'no-store' });
    const j   = await res.json().catch(() => ({}));
    if (!res.ok || !j?.ok || !Array.isArray(j.items)) {
      console.warn('Sachnummer-API Fehler', j);
      return [];
    }
    return j.items;
  }

  function clearSuggestions() {
    box.innerHTML = '';
    box.style.display = 'none';
  }

  function showSuggestions(items) {
    box.innerHTML = '';
    if (!items.length) {
      clearSuggestions();
      return;
    }

    items.slice(0, 20).forEach(it => {
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'w-full text-left px-3 py-1.5 text-sm hover:bg-slate-100 focus:bg-slate-100';

      let label = it.sachnummer;
      if (it.lagergruppe)  label += ' (' + it.lagergruppe;
      if (it.behaelter_nr) label += ', ' + it.behaelter_nr;
      if (label.endsWith('(')) label = label.slice(0, -1);
      if (label.includes('(')) label += ')';

      btn.textContent = label;

      btn.addEventListener('click', () => {
        input.value = it.sachnummer;
        clearSuggestions();
        input.focus();
      });

      box.appendChild(btn);
    });

    box.style.display = 'block';
  }

  input.addEventListener('input', () => {
    const q = input.value.trim();

    if (timer) clearTimeout(timer);

    if (q.length < 3) {
      clearSuggestions();
      return;
    }

    timer = setTimeout(async () => {
      const items = await apiListSachnummern(q);
      showSuggestions(items);
    }, 200);
  });

  document.addEventListener('click', (ev) => {
    if (!box.contains(ev.target) && ev.target !== input) {
      clearSuggestions();
    }
  });
})();

// === Folge-Schritt nach KLT-Vorgängen anbieten (Modal + Prefill) ===
(function () {
  if (!ASK_100 || !PREFILL_100) return;

  const overlay = document.getElementById('ask100Overlay');
  const btnYes  = document.getElementById('ask100Yes');
  const btnNo   = document.getElementById('ask100No');
  const form    = document.getElementById('qcForm');
  const textEl  = document.getElementById('ask100Text');

  if (!overlay || !btnYes || !btnNo || !form || !textEl) return;

  // Letzter Grund aus dem Prefill (aus 100p_save.php)
  const lastReason = (PREFILL_100 && PREFILL_100.reason) ? PREFILL_100.reason : '';

  // Texte im Modal je nach letztem Grund
  if (lastReason === 'Umpacken auf Palette' || lastReason === 'Umfüllung in KLT') {
    textEl.textContent = 'Du hast einen Umpack-/Umfüll-Vorgang erfasst. Möchtest du für diese Palette jetzt die Etikettierung der KLTs erfassen?';
    btnNo.textContent  = 'Nein, nur Umpacken';
    btnYes.textContent = 'Ja, Etikettierung erfassen';
  } else if (lastReason === 'Etikettierung KLT') {
    textEl.textContent = 'Du hast eine Etikettierung KLT erfasst. Hast du für diese Palette auch eine 100%-Prüfung durchgeführt?';
    btnNo.textContent  = 'Nein, nur Etikettierung';
    btnYes.textContent = 'Ja, 100%-Prüfung erfassen';
  } else {
    // Fallback – sollte selten vorkommen
    textEl.textContent = 'Für diesen Vorgang kannst du eine 100%-Prüfung erfassen. Möchtest du das jetzt tun?';
    btnNo.textContent  = 'Nein';
    btnYes.textContent = 'Ja, 100%-Prüfung erfassen';
  }

  function closeOverlay() {
    overlay.classList.add('hidden');
  }

  btnNo.addEventListener('click', () => {
    // Kein weiterer Schritt -> einfach Overlay schließen
    closeOverlay();
  });

  btnYes.addEventListener('click', () => {
    const d = PREFILL_100 || {};
    const reasonSel = form.elements['reason'];

    // Form mit den alten Daten füllen
    if (d.pallet_code)   form.elements['pallet_code'].value   = d.pallet_code;
    if (d.delivery_note) form.elements['delivery_note'].value = d.delivery_note;
    if (d.material_no)   form.elements['material_no'].value   = d.material_no;
    if (d.employee_id)   form.elements['employee_id'].value   = d.employee_id;
    if (d.time_start)    form.elements['time_start'].value    = d.time_start;
    if (d.time_end)      form.elements['time_end'].value      = d.time_end;
    if (d.comment)       form.elements['comment'].value       = d.comment;

    // KLT-Felder leeren (werden im nächsten Schritt neu erfasst)
    if (form.elements['klt_count']) {
      form.elements['klt_count'].value = '';
    }
    if (form.elements['qty_per_klt']) {
      form.elements['qty_per_klt'].value = '';
    }

    if (reasonSel) {
      let targetReason = '100% Prüfung';

      // 1. Schritt: nach Umpacken/Umfüllung -> Etikettierung
      if (lastReason === 'Umpacken auf Palette' || lastReason === 'Umfüllung in KLT') {
        targetReason = 'Etikettierung KLT';
      }
      // 2. Schritt: nach Etikettierung -> 100%-Prüfung
      else if (lastReason === 'Etikettierung KLT') {
        targetReason = '100% Prüfung';
      }

      // Grund setzen
      reasonSel.value = targetReason;

      // change-Event feuern, damit KLT-/Umfüll-Felder reagieren
      const evt = new Event('change', { bubbles: true });
      reasonSel.dispatchEvent(evt);

      // Alle anderen Gründe sperren, damit keine Fehler passieren
      Array.from(reasonSel.options).forEach(opt => {
        if (!opt.value) return; // Placeholder "– bitte wählen –" in Ruhe lassen
        opt.disabled = (opt.value !== targetReason);
      });
    }

    closeOverlay();
  });
})();


// === Palette + Lieferschein + Sachnummer übernehmen UND nächsten logischen Grund setzen ===
// === Palette / Sachnummer aus Listen übernehmen UND logischen Grund setzen ===
(function () {
  const form = document.getElementById('qcForm');
  if (!form) return;

  const palletInput   = form.elements['pallet_code'];
  const deliveryInput = form.elements['delivery_note'];
  const materialInput = form.elements['material_no'];
  const reasonSel     = form.elements['reason'];

  if (!materialInput || !reasonSel) return;

  // Hilfsfunktion aus "offenen Paletten" (falls du sie schon hast)
  function applyOpenToForm(code, del, mat, hasStep1, hasKlt, has100) {
    if (palletInput)   palletInput.value   = code || '';
    if (deliveryInput) deliveryInput.value = del  || '';
    materialInput.value = mat || '';

    let nextReason = '';
    if (hasStep1 && !hasKlt) {
      nextReason = 'Etikettierung KLT';
    } else if (hasStep1 && hasKlt && !has100) {
      nextReason = '100% Prüfung';
    }

    Array.from(reasonSel.options).forEach(opt => { opt.disabled = false; });

    if (nextReason) {
      reasonSel.value = nextReason;
      const evt = new Event('change', { bubbles: true });
      reasonSel.dispatchEvent(evt);

      Array.from(reasonSel.options).forEach(opt => {
        if (!opt.value) return;
        opt.disabled = (opt.value !== nextReason);
      });
    }

    if (form.elements['klt_count']) form.elements['klt_count'].value = '';
    if (form.elements['qty_per_klt']) form.elements['qty_per_klt'].value = '';

    reasonSel.focus();
  }

  // 1) Offene Paletten (hast du ja schon)
  document.querySelectorAll('[data-fill-pallet]').forEach(btn => {
    btn.addEventListener('click', () => {
      const code   = btn.dataset.fillPallet   || '';
      const del    = btn.dataset.fillDelivery || '';
      const mat    = btn.dataset.fillMaterial || '';
      const hasUmp = btn.dataset.hasUmp  === '1';
      const hasKlt = btn.dataset.hasKlt  === '1';
      const has100 = btn.dataset.has100 === '1';

      applyOpenToForm(code, del, mat, hasUmp, hasKlt, has100);
    });
  });

  // 2) NEU: Tages-Aufgaben (Bedarfsliste vom Dashboard)
  document.querySelectorAll('[data-pack-task]').forEach(btn => {
    btn.addEventListener('click', () => {
      const mat    = btn.dataset.material || '';
      const reason = btn.dataset.reason   || 'Etikettierung KLT';
      const rest   = parseInt(btn.dataset.rest || '0', 10) || 0;

      // Sachnummer & Grund setzen
      materialInput.value = mat;
      reasonSel.value     = reason;

      // KLT-Feld mit Rest vorfüllen (kann der Mitarbeiter anpassen)
      if (form.elements['klt_count']) {
        form.elements['klt_count'].value = rest > 0 ? rest : '';
      }

      // sorgt dafür, dass KLT-Felder bei KLT-Gründen sichtbar sind
      const evt = new Event('change', { bubbles: true });
      reasonSel.dispatchEvent(evt);

      // optional andere Gründe sperren, damit keine Fehlbedienung:
      Array.from(reasonSel.options).forEach(opt => {
        if (!opt.value) return;
        opt.disabled = (opt.value !== reason);
      });

      materialInput.focus();
    });
  });
})();

// === KLT-Eingabe je nach Grund ein-/ausblenden (inkl. Umfüllung + qty_per_klt) ===
(function () {
  const reasonSel = document.querySelector('select[name="reason"]');
  const kltWrap   = document.getElementById('kltCountWrapper');
  const qtyWrap   = document.getElementById('qtyPerKltWrapper');

  if (!reasonSel || !kltWrap || !qtyWrap) return;

  function updateKltVisibility() {
    const val = reasonSel.value;

    // KLT-Anzahl bei Etikettierung, Umpacken und Umfüllung sichtbar
    if (
      val === 'Etikettierung KLT' ||
      val === 'Umpacken auf Palette' ||
      val === 'Umfüllung in KLT'
    ) {
      kltWrap.classList.remove('hidden');
    } else {
      kltWrap.classList.add('hidden');
      const kltInput = document.querySelector('input[name="klt_count"]');
      if (kltInput) kltInput.value = '';
    }

    // "Anzahl pro KLT" NUR bei Umfüllung anzeigen
    if (val === 'Umfüllung in KLT') {
      qtyWrap.classList.remove('hidden');
    } else {
      qtyWrap.classList.add('hidden');
      const qtyInput = document.querySelector('input[name="qty_per_klt"]');
      if (qtyInput) qtyInput.value = '';
    }
  }

  reasonSel.addEventListener('change', updateKltVisibility);
  updateKltVisibility(); // Initialzustand beim Laden
})();

</script>

<?php if (!empty($timeOverlap)): ?>
<div id="timeOverlapOverlay" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40">
  <div class="bg-white rounded-xl shadow-xl w-full max-w-md mx-4 p-4">
    <h2 class="text-base font-semibold text-slate-900 mb-2">
      Hinweis: Zeit bereits erfasst
    </h2>

    <?php if (!empty($timeOverlap['pallet_code']) || !empty($timeOverlap['material_no'])): ?>
      <p class="text-xs text-slate-700 mb-2">
        Palette: <?=htmlspecialchars($timeOverlap['pallet_code'] ?? '-')?><br>
        Sachnummer: <?=htmlspecialchars($timeOverlap['material_no'] ?? '-')?>
      </p>
    <?php endif; ?>

    <p class="text-sm text-slate-700 mb-3">
      <?=htmlspecialchars($timeOverlap['message'])?>
    </p>

    <p class="text-xs text-slate-700 mb-2">
      Neue Zeit:
      <?=htmlspecialchars($timeOverlap['new_slot']['start'] ?? '')?>
      – <?=htmlspecialchars($timeOverlap['new_slot']['end'] ?? '')?>
    </p>

    <?php if (!empty($timeOverlap['conflicts'])): ?>
      <div class="mb-3 text-xs text-slate-700">
        <div class="font-semibold mb-1">Bereits erfasste Zeiten heute:</div>
        <?php foreach ($timeOverlap['conflicts'] as $c): ?>
          <div><?=htmlspecialchars($c['start'])?> – <?=htmlspecialchars($c['end'])?></div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <div class="mt-3 flex flex-col sm:flex-row sm:justify-end gap-2">
      <button type="button"
              id="overlapEditTimeBtn"
              class="inline-flex items-center justify-center rounded-md bg-brand px-3 py-1.5 text-xs sm:text-sm font-semibold text-white hover:bg-brand-dark">
        Andere Zeit erfassen
      </button>
      <button type="button"
              id="overlapCloseBtn"
              class="inline-flex items-center justify-center rounded-md border border-slate-300 bg-white px-3 py-1.5 text-xs sm:text-sm font-medium text-slate-700 hover:bg-slate-50">
        Abbrechen
      </button>
    </div>
  </div>
</div>
<?php endif; ?>
<script>
(function () {
  const overlay = document.getElementById('timeOverlapOverlay');
  if (!overlay) return;

  const btnEdit  = document.getElementById('overlapEditTimeBtn');
  const btnClose = document.getElementById('overlapCloseBtn');
  const form     = document.getElementById('qcForm');

  // Andere Zeit erfassen: Overlay ausblenden, Fokus auf Zeit Start
  if (btnEdit) {
    btnEdit.addEventListener('click', () => {
      overlay.classList.add('hidden');
      if (form && form.elements['time_start']) {
        form.elements['time_start'].focus();
      }
    });
  }

  // Abbrechen: komplett neu laden (leeres Formular)
  if (btnClose) {
    btnClose.addEventListener('click', () => {
      window.location.href = '100p_form.php';
    });
  }
})();
</script>

</body>
</html>
