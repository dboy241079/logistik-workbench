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


$action = $_POST['action'] ?? '';

try {
    // ======================================================================
    // 1) Verpack-Aufgaben speichern (Modal „Verpack-Aufgaben planen“)
    // ======================================================================
    if ($action === 'save_needs') {
        $date = $_POST['need_date'] ?? '';
        $hall = trim($_POST['hall'] ?? '');

        if ($date === '') {
            http_response_code(400);
            echo json_encode(['ok' => false, 'msg' => 'Datum fehlt']);
            exit;
        }

        $itemsJson = $_POST['items'] ?? '[]';
        $items = json_decode($itemsJson, true);
        if (!is_array($items)) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'msg' => 'Ungültige Daten']);
            exit;
        }

        // erlaubte Gründe
        $allowedReasons = [
          '100% Prüfung',
          'Etikettierung KLT',
          'Umpacken auf Palette',
          'Umfüllung in KLT'
        ];

        // Halle-Parameter (NULL wenn leer)
        $hParam = ($hall === '') ? null : $hall;

        // 🔁 Wir behandeln das Modal als "Neuplanung" für (Datum + Halle):
        //    -> erst ALLE Einträge für dieses Datum + diese Halle löschen
        //    -> dann die übergebenen Items neu einfügen/aktualisieren
        $stmtDel = $pdo->prepare("
          DELETE FROM qc_100_pack_needs
          WHERE need_date = :d
            AND ((hall IS NULL AND :h IS NULL) OR hall = :h)
        ");
        $stmtDel->execute([
          ':d' => $date,
          ':h' => $hParam,
        ]);

        // Selektieren brauchen wir technisch nicht mehr, aber wir lassen die
        // Logik drin – falls du später doch wieder auf "Updaten" umstellen willst.
        $stmtSel = $pdo->prepare("
          SELECT id 
          FROM qc_100_pack_needs
          WHERE need_date = :d
            AND material_no = :m
            AND reason      = :r
            AND ((hall IS NULL AND :h IS NULL) OR hall = :h)
          LIMIT 1
        ");

        $stmtIns = $pdo->prepare("
          INSERT INTO qc_100_pack_needs
            (need_date, hall, material_no, reason, klt_target, comment, created_by)
          VALUES
            (:d, :h, :m, :r, :k, :c, :uid)
        ");

        $stmtUpd = $pdo->prepare("
          UPDATE qc_100_pack_needs
          SET klt_target = :k,
              comment    = :c,
              created_at = NOW(),
              created_by = :uid
          WHERE id = :id
        ");

        $count = 0;

        foreach ($items as $it) {
            $mat    = trim($it['material_no'] ?? '');
            $reason = $it['reason'] ?? 'Etikettierung KLT';
            $klt    = (int)($it['klt_target'] ?? 0); // = Anzahl Paletten
            $comm   = trim($it['comment'] ?? '');

            // Leere / ungültige Zeilen ignorieren
            if ($mat === '' || $klt <= 0) {
                continue;
            }
            if (!in_array($reason, $allowedReasons, true)) {
                $reason = 'Etikettierung KLT';
            }

            // Nach dem globalen DELETE oben existiert hier technisch nichts mehr,
            // aber wir lassen die Selektions-Logik drin:
            $stmtSel->execute([
              ':d' => $date,
              ':m' => $mat,
              ':r' => $reason,
              ':h' => $hParam,
            ]);

            if ($row = $stmtSel->fetch()) {
                // (würde nur greifen, wenn du das DELETE entfernst)
                $stmtUpd->execute([
                  ':k'   => $klt,
                  ':c'   => $comm,
                  ':uid' => $userId,
                  ':id'  => $row['id'],
                ]);
            } else {
                // neuer Eintrag
                $stmtIns->execute([
                  ':d'   => $date,
                  ':h'   => $hParam,
                  ':m'   => $mat,
                  ':r'   => $reason,
                  ':k'   => $klt,
                  ':c'   => $comm,
                  ':uid' => $userId,
                ]);
            }

            $count++;
        }

        echo json_encode([
          'ok'    => true,
          'msg'   => "Verpack-Aufgaben gespeichert ({$count} Positionen).",
          'count' => $count,
        ]);
        exit;
    }

    // ======================================================================
    // 2) Einzelnen Bedarf löschen (Button "löschen" in der Tages-Aufgaben-Tabelle)
    // ======================================================================
    if ($action === 'delete_need') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'msg' => 'Ungültige ID']);
            exit;
        }

        $stmtDel = $pdo->prepare("DELETE FROM qc_100_pack_needs WHERE id = :id");
        $stmtDel->execute([':id' => $id]);

        echo json_encode([
          'ok'  => true,
          'msg' => 'Verpack-Aufgabe wurde gelöscht.',
        ]);
        exit;
    }

    // ======================================================================
    // Unbekannte Aktion
    // ======================================================================
    http_response_code(400);
    echo json_encode(['ok' => false, 'msg' => 'Unbekannte Aktion']);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'msg' => 'Serverfehler']);
}
