<!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Container</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

  <style>
    /* ====== Layout: 2 Bereiche wie Foto (oben / unten) ====== */
    .yard-wrap{
  display:grid;
  grid-template-columns: 1fr 170px 1fr; /* Mitte = Gebäude */
  gap: 16px;
  align-items:stretch;
}
@media (max-width: 992px){
  .yard-wrap{ grid-template-columns: 1fr; }
}

/* mittleres Gebäude */
.building-card{
  background:#fff;
  border:1px solid #e5e7eb;
  border-radius: 14px;
  padding: 10px;
  display:flex;
  flex-direction:column;
  justify-content:space-between;
}
.building-silhouette{
  flex:1;
  border:2px solid #111;
  border-radius: 16px;
  background: #f8fafc;
  display:flex;
  align-items:center;
  justify-content:center;
  text-align:center;
  font-weight:800;
  letter-spacing:.5px;
  padding: 10px;
  min-height: 240px;
}
.building-hint{
  font-size: 12px;
  color:#64748b;
  text-align:center;
  margin-top:8px;
}

    @media (max-width: 992px){
      .yard-wrap{ grid-template-columns: 1fr; }
    }

    .yard-card{
      background:#fff;
      border:1px solid #e5e7eb;
      border-radius: 14px;
      padding: 10px;
    }
    .yard-title{
      font-weight:700;
      margin: 0 0 8px 0;
      display:flex;
      justify-content:space-between;
      align-items:center;
    }

    /* Wichtig: Das Grid, in das JS die Buttons bei r/c setzt */
    .yard-grid{
      display:grid;
      grid-template-columns: repeat(6, minmax(0, 1fr));
      grid-template-rows: repeat(24, minmax(0, 1fr));
      gap: 8px;
      min-height: min(75vh, 820px);
    }

    /* Buttons */
    .cbtn{
      border: 1px solid #111;
      border-radius: 10px;
      padding: 6px;
      font-size: 12px;
      background: #e2e8f0;
      cursor:pointer;
      display:flex;
      flex-direction:column;
      align-items:center;
      justify-content:center;
      gap:2px;
      user-select:none;
      transition: transform .08s ease;
      min-height: 3rem;
      min-width: 5rem;
      position:relative;
    }
    .cbtn:hover{ transform: scale(1.02); }
    .cbtn.dim{ opacity: .25; }
    .badge-mini{ font-size: 10px; }
    .cbtn.full{ background:#fecaca; }
    .cbtn.mid{ background:#fde68a; }
    .cbtn.low{ background:#bbf7d0; }
    .cbtn.empty{ background:#e2e8f0; }

    .camIcon{
  position:absolute;
  top:4px;
  right:6px;
  font-size:14px;
  line-height:1;
  opacity:.85;
  background: transparent;
  border: 0;
}
.camIcon:hover{
  opacity:1;
  transform: scale(1.08);
}

    /* Modal */
    .modalX { position:fixed; inset:0; background:rgba(0,0,0,.45); display:none; align-items:center; justify-content:center; z-index:10000; }
    .modalX.show{ display:flex; }
    .modalCard{ background:#fff; border-radius:16px; width:min(980px, 95vw); max-height:92vh; overflow:auto; padding:14px; }
    /* ===== Slot-Karten (Modal) ===== */
.posGrid{
  display:grid;
  grid-template-columns: repeat(8, minmax(0, 1fr));
  gap:8px;
}

@media (max-width: 1200px){
  .posGrid{ grid-template-columns: repeat(6, minmax(0, 1fr)); }
}
@media (max-width: 900px){
  .posGrid{ grid-template-columns: repeat(4, minmax(0, 1fr)); }
}
@media (max-width: 620px){
  .posGrid{ grid-template-columns: repeat(2, minmax(0, 1fr)); }
}

.posCell{
  border:1px solid #cbd5e1;
  border-radius:12px;
  padding:8px;
  background:#f8fafc;
  min-height:130px;
  display:flex;
  flex-direction:column;
  gap:6px;
  font-size:12px;
  transition: box-shadow .12s ease, transform .08s ease;
}
.posCell:hover{
  box-shadow: 0 4px 12px rgba(15,23,42,.08);
}

.posCell.used{
  background:#eff6ff;
  border-color:#60a5fa;
}
.posCell.free{
  background:#f1f5f9;
  color:#64748b;
  min-height:72px;
  justify-content:center;
  align-items:flex-start;
}

.slot-head{
  display:flex;
  justify-content:space-between;
  align-items:center;
  gap:6px;
}

.slot-pos{
  font-weight:700;
  font-size:12px;
  color:#0f172a;
}

.slot-state{
  font-size:10px;
  border:1px solid #bfdbfe;
  background:#dbeafe;
  color:#1d4ed8;
  border-radius:999px;
  padding:1px 6px;
  line-height:1.4;
}

.slot-lines{
  display:grid;
  gap:2px;
  line-height:1.2;
}

.slot-ref{
  font-weight:600;
  color:#0f172a;
  word-break:break-word;
}

.slot-meta{
  color:#475569;
  font-size:11px;
  word-break:break-word;
}

.slot-photo-wrap{
  margin-top:2px;
  border-top:1px dashed #cbd5e1;
  padding-top:6px;
}

.slot-photo-title{
  font-size:10px;
  font-weight:700;
  color:#64748b;
  text-transform:uppercase;
  letter-spacing:.3px;
  margin-bottom:4px;
}

.slot-photo{
  width:100%;
  height:72px;
  object-fit:cover;
  border-radius:8px;
  border:1px solid #cbd5e1;
  background:#fff;
  display:block;
  cursor:pointer;
}

.slot-photo.placeholder{
  display:flex;
  align-items:center;
  justify-content:center;
  color:#94a3b8;
  font-size:11px;
  background:#f8fafc;
}

.slot-file{
  font-size:11px;
}

.slot-actions{
  display:grid;
  grid-template-columns: 1fr 1fr;
  gap:4px;
}

.slot-actions-3{
  display:grid;
  grid-template-columns: 1fr 1fr 1fr;
  gap:4px;
}

.slot-actions .btn,
.slot-actions-3 .btn{
  font-size:11px;
  padding:4px 6px;
  line-height:1.2;
}

.slot-msg{
  min-height:14px;
  font-size:10px;
  color:#64748b;
}

/* Freie Slots kompakt */
.free .slot-head{
  margin-bottom:0;
}
.free .slot-pos{
  color:#64748b;
}
.free .slot-free-text{
  font-size:11px;
  color:#94a3b8;
}
    
  </style>
</head>

<body class="bg-light">
  <div class="container-fluid p-3">

    <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
      <h1 class="h5 mb-0">Container (52 × 48)</h1>

      <div class="ms-auto d-flex flex-wrap gap-2 align-items-center">
        <input id="q" class="form-control form-control-sm" style="width:240px" placeholder="Suche Ref/Sach/LS…">
        <button id="btnClear" class="btn btn-sm btn-outline-secondary" type="button">Reset</button>

        <div class="form-check form-switch">
          <input class="form-check-input" type="checkbox" role="switch" id="onlyFree">
          <label class="form-check-label small" for="onlyFree">nur freie</label>
        </div>
        <div class="form-check form-switch">
          <input class="form-check-input" type="checkbox" role="switch" id="onlyFull">
          <label class="form-check-label small" for="onlyFull">nur volle</label>
        </div>
      </div>
    </div>

    <!-- FOTO-LAYOUT -->
    <div class="yard-wrap">
      <div class="yard-card">
        <div class="yard-title">Oberhalb Gebäude <span class="text-muted small">C27–C52</span></div>
        <div id="ringNorth" class="yard-grid"></div>
      </div>


  <!-- ✅ Gebäude Mitte -->
  <div class="building-card">
    <div class="yard-title">Gebäude</div>
    <div class="building-silhouette">
      GEBÄUDE
    </div>
    <div class="building-hint">
      Links: oberhalb · Rechts: unterhalb
    </div>
  </div>

  <div class="yard-card">
    <div class="yard-title">Unterhalb Gebäude <span class="text-muted small">C01–C26</span></div>
    <div id="ringSouth" class="yard-grid"></div>
  </div>
</div>

  </div>
   
  <!-- Container Modal -->
  <div class="modalX" id="modal">
    <div class="modalCard">
      <div class="d-flex align-items-center justify-content-between mb-2">
        <div>
          <div class="fw-semibold" id="mTitle">Container</div>
          <div class="text-muted small" id="mMeta">–</div>
          <button id="btnPrint" type="button" class="btn btn-sm btn-outline-primary">Drucken</button>

        </div>
        <button class="btn btn-sm btn-outline-secondary" id="mClose" type="button">Schließen</button>
      </div>

      <div class="row g-2 mb-3">
        <div class="col-md-3"><input id="mRef" class="form-control form-control-sm" placeholder="Referenz scannen…"></div>
        <div class="col-md-3"><input id="mSach" class="form-control form-control-sm" placeholder="Sachnummer…"></div>
        <div class="col-md-2"><input id="mLs" class="form-control form-control-sm" placeholder="Lieferschein…"></div>
        <div class="col-md-2"><input id="mQty" type="number" min="1" step="1" class="form-control form-control-sm" value="1"></div>
        <div class="col-md-2 d-grid"><button id="mAdd" class="btn btn-sm btn-success" type="button">Einlagern (auto)</button></div>
      </div>

      <div class="posGrid" id="posGrid"></div>
      <div class="text-muted small mt-2" id="mStatus"></div>

      <hr class="my-2">
      <div class="small fw-semibold mb-2">Container-Bilder (2)</div>

      <div class="row g-2">
        <div class="col-6">
          <img id="imgPrev1" class="w-100 rounded border mb-1 d-none" style="height:110px;object-fit:cover" src="" alt="">
          <input id="imgFile1" type="file" accept="image/*" capture="environment" class="form-control form-control-sm">
          <div class="d-flex gap-2 mt-1">
            <button id="imgUp1" class="btn btn-sm btn-outline-primary" type="button">Upload</button>
            <button id="imgDel1" class="btn btn-sm btn-outline-danger" type="button">Löschen</button>
          </div>
        </div>

        <div class="col-6">
          <img id="imgPrev2" class="w-100 rounded border mb-1 d-none" style="height:110px;object-fit:cover" src="" alt="">
          <input id="imgFile2" type="file" accept="image/*" capture="environment" class="form-control form-control-sm">
          <div class="d-flex gap-2 mt-1">
            <button id="imgUp2" class="btn btn-sm btn-outline-primary" type="button">Upload</button>
            <button id="imgDel2" class="btn btn-sm btn-outline-danger" type="button">Löschen</button>
          </div>
        </div>
      </div>

    </div>
  </div>

  <!-- Lightbox -->
  <div class="modalX" id="imgBox">
    <div class="modalCard" style="width:min(1200px,96vw);">
      <div class="d-flex align-items-center justify-content-between mb-2">
        <div class="fw-semibold">Container-Bild</div>
        <button class="btn btn-sm btn-outline-secondary" id="imgBoxClose" type="button">Schließen</button>
      </div>
      <img id="imgBoxImg" alt="" style="width:100%;max-height:82vh;object-fit:contain;border-radius:12px;">
    </div>
  </div>

  <!-- Slot-Foto Modal (zentriert) -->
<div class="modalX" id="slotPhotoModal">
  <div class="modalCard" style="width:min(520px,95vw);">
    <div class="d-flex align-items-center justify-content-between mb-2">
      <div>
        <div class="fw-semibold" id="spmTitle">Slot-Foto</div>
        <div class="text-muted small" id="spmMeta">Container / Pos</div>
      </div>
      <button class="btn btn-sm btn-outline-secondary" id="spmClose" type="button">Schließen</button>
    </div>

    <img id="spmImg" class="w-100 rounded border mb-2 d-none"
         style="height:220px;object-fit:cover;cursor:pointer" src="" alt="Slot-Foto">

    <div id="spmPlaceholder" class="border rounded p-3 text-center text-muted mb-2">
      Kein Bild vorhanden
    </div>

    <input id="spmFile" class="form-control form-control-sm mb-2" type="file" accept="image/*" capture="environment">

    <div class="d-grid gap-2" style="grid-template-columns: 1fr 1fr 1fr;">
      <button id="spmUpload" class="btn btn-sm btn-outline-primary" type="button">Upload</button>
      <button id="spmDelete" class="btn btn-sm btn-outline-secondary" type="button">Löschen</button>
      <button id="spmView" class="btn btn-sm btn-outline-dark" type="button">Ansehen</button>
    </div>

    <div id="spmMsg" class="text-muted small mt-2"></div>
  </div>
</div>

  <script src="/Container/containerplan.js?v=1"></script>
</body>
</html>
