const els = {
  companyLine: document.getElementById("companyLine"),
  docTitle: document.getElementById("docTitle"),
  docNumber: document.getElementById("docNumber"),
  rev: document.getElementById("rev"),
  printDate: document.getElementById("printDate"),
  pageInfo: document.getElementById("pageInfo"),

  purposeTitle: document.getElementById("purposeTitle"),
  scopeTitle: document.getElementById("scopeTitle"),
  processTitle: document.getElementById("processTitle"),
  safetyTitle: document.getElementById("safetyTitle"),
  documentationTitle: document.getElementById("documentationTitle"),

  purpose: document.getElementById("purpose"),
  scope: document.getElementById("scope"),
  process: document.getElementById("process"),
  safety: document.getElementById("safety"),
  documentation: document.getElementById("documentation"),

  author: document.getElementById("author"),
  reviewer: document.getElementById("reviewer"),
  approver: document.getElementById("approver"),
  updated: document.getElementById("updated"),

  rolesContainer: document.getElementById("rolesContainer"),
  historyContainer: document.getElementById("historyContainer"),
  signatureContainer: document.getElementById("signatureContainer"),

  preview: document.getElementById("documentPreview"),

  addRoleBtn: document.getElementById("addRoleBtn"),
  addHistoryBtn: document.getElementById("addHistoryBtn"),
  addSignatureBtn: document.getElementById("addSignatureBtn"),

  downloadBtn: document.getElementById("downloadBtn"),
  printBtn: document.getElementById("printBtn"),
  saveJsonBtn: document.getElementById("saveJsonBtn"),
  loadJsonBtn: document.getElementById("loadJsonBtn"),
  resetBtn: document.getElementById("resetBtn"),
  jsonFileInput: document.getElementById("jsonFileInput")
};

const logoPath = "Bilder/logo_standard_tpo.svg";
let logoDataUrl = "";

const defaultRoles = [
  {
    role: "Disposition",
    task: "Erkennen der Störung, Kommunikation mit beteiligten Stellen, Dokumentation der Abweichung"
  },
  {
    role: "Vorgesetzte / Lagerleitung",
    task: "Unterstützung bei Eskalationen, Entscheidung über weitere Maßnahmen"
  }
];

const defaultHistory = [
  {
    reason: "Ersterstellung",
    creator: "MAD",
    date: "21.04.2026"
  }
];

const defaultSignatures = [
  {
    name: "",
    signature: ""
  }
];

const imageState = {
  purpose: [],
  scope: [],
  process: [],
  safety: [],
  documentation: []
};

function escapeHtml(value = "") {
  return String(value)
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;")
    .replace(/'/g, "&#39;");
}

function cloneImageState() {
  return {
    purpose: imageState.purpose.map(img => ({ ...img })),
    scope: imageState.scope.map(img => ({ ...img })),
    process: imageState.process.map(img => ({ ...img })),
    safety: imageState.safety.map(img => ({ ...img })),
    documentation: imageState.documentation.map(img => ({ ...img }))
  };
}

function formatEditor(editorId, command) {
  const editor = document.getElementById(editorId);
  if (!editor) return;

  editor.focus();
  document.execCommand(command, false, null);
  syncEditorToHiddenInput(editorId);
  renderPreview();
}

function syncEditorToHiddenInput(editorId) {
  const editor = document.getElementById(editorId);
  const hiddenInputId = editorId.replace("Editor", "");
  const hiddenInput = document.getElementById(hiddenInputId);

  if (!editor || !hiddenInput) return;
  hiddenInput.value = editor.innerHTML;
}

function initRichEditor(editorId) {
  const editor = document.getElementById(editorId);
  if (!editor) return;

  editor.addEventListener("input", () => {
    syncEditorToHiddenInput(editorId);
    renderPreview();
  });

  syncEditorToHiddenInput(editorId);
}

function loadRichEditor(editorId, value) {
  const editor = document.getElementById(editorId);
  const hiddenInputId = editorId.replace("Editor", "");
  const hiddenInput = document.getElementById(hiddenInputId);

  if (!editor || !hiddenInput) return;

  editor.innerHTML = value || "";
  hiddenInput.value = value || "";
}

function ensureImageUploadUI() {
  const sections = [
    { key: "purpose", label: "Zweck", anchorId: "purposeEditor" },
    { key: "scope", label: "Geltungsbereich", anchorId: "scopeEditor" },
    { key: "process", label: "Arbeitsablauf", anchorId: "processEditor" },
    { key: "safety", label: "Sicherheitshinweise", anchorId: "safetyEditor" },
    { key: "documentation", label: "Dokumentation", anchorId: "documentationEditor" }
  ];

  sections.forEach(section => {
    const anchor = document.getElementById(section.anchorId);
    if (!anchor || anchor.dataset.imageUiReady === "1") return;

    const wrap = document.createElement("div");
    wrap.className = "repeat-item";
    wrap.style.marginTop = "-4px";
    wrap.innerHTML = `
      <h4>Bilder für ${section.label}</h4>
      <label>Bis zu 2 Bilder hochladen</label>
      <input type="file" accept="image/*" multiple data-image-input="${section.key}">
      <div class="help">Es werden maximal 2 Bilder für diesen Abschnitt übernommen.</div>
      <div class="image-preview-grid" id="preview_${section.key}"></div>
    `;

    anchor.insertAdjacentElement("afterend", wrap);
    anchor.dataset.imageUiReady = "1";

    const input = wrap.querySelector('input[type="file"]');
    input.addEventListener("change", event => handleImageUpload(section.key, event.target.files));
  });
}

function handleImageUpload(sectionKey, files) {
  const selected = [...files];
  if (!selected.length) return;

  const existingImages = imageState[sectionKey] ? [...imageState[sectionKey]] : [];
  const freeSlots = 2 - existingImages.length;

  if (freeSlots <= 0) {
    alert("Es sind bereits 2 Bilder für diesen Abschnitt vorhanden. Bitte erst ein Bild entfernen.");
    return;
  }

  const filesToLoad = selected.slice(0, freeSlots);
  let loadedCount = 0;
  const newImages = [];

  filesToLoad.forEach(file => {
    const reader = new FileReader();

    reader.onload = e => {
      const newIndex = existingImages.length + newImages.length + 1;

      newImages.push({
        name: file.name,
        src: e.target.result,
        label: `Bild ${newIndex}`
      });

      loadedCount += 1;

      if (loadedCount === filesToLoad.length) {
        imageState[sectionKey] = [...existingImages, ...newImages].slice(0, 2);
        refreshImagePreview(sectionKey);
        renderPreview();
      }
    };

    reader.readAsDataURL(file);
  });
}

function refreshImagePreview(sectionKey) {
  const previewBox = document.getElementById(`preview_${sectionKey}`);
  if (!previewBox) return;

  previewBox.innerHTML = "";

  imageState[sectionKey].forEach((img, index) => {
    const currentLabel = img.label || `Bild ${index + 1}`;

    const card = document.createElement("div");
    card.style.border = "1px solid #d8dee6";
    card.style.borderRadius = "10px";
    card.style.padding = "8px";
    card.style.background = "#fff";

    card.innerHTML = `
      <label style="font-size:12px;font-weight:700;margin-bottom:4px;display:block;">Bildname</label>
      <input
        type="text"
        class="image-title-input"
        value="${escapeHtml(currentLabel)}"
        data-image-label="${sectionKey}"
        data-index="${index}"
      >
      <img src="${img.src}" alt="${escapeHtml(img.name)}" style="width:100%;height:110px;object-fit:cover;border-radius:8px;display:block;margin-bottom:6px;">
      <div class="image-filename">${escapeHtml(img.name)}</div>
      <button type="button" class="btn-danger" style="margin-top:8px;padding:8px 10px;font-size:12px;" data-remove-image="${sectionKey}" data-index="${index}">Entfernen</button>
    `;

    previewBox.appendChild(card);
  });

  previewBox.querySelectorAll("[data-remove-image]").forEach(btn => {
    btn.addEventListener("click", () => {
      const idx = Number(btn.dataset.index);
      imageState[sectionKey].splice(idx, 1);

      imageState[sectionKey] = imageState[sectionKey].map((img, i) => ({
        ...img,
        label: img.label && !/^Bild \d+$/.test(img.label) ? img.label : `Bild ${i + 1}`
      }));

      refreshImagePreview(sectionKey);
      renderPreview();
    });
  });

  previewBox.querySelectorAll("[data-image-label]").forEach(input => {
    input.addEventListener("input", () => {
      const idx = Number(input.dataset.index);
      const value = input.value.trim();

      if (imageState[sectionKey] && imageState[sectionKey][idx]) {
        imageState[sectionKey][idx].label = value || `Bild ${idx + 1}`;
        renderPreview();
      }
    });
  });
}

function imagesMarkup(sectionKey) {
  const images = imageState[sectionKey] || [];
  if (!images.length) return "";

  const first = images[0]
    ? `
      <div class="doc-image-card">
        <div style="width:100%;">
          <div class="image-label">${escapeHtml(images[0].label || "Bild 1")}</div>
          <img src="${images[0].src}" alt="${escapeHtml(images[0].name)}">
        </div>
      </div>
    `
    : "";

  const second = images[1]
    ? `
      <div class="doc-image-card">
        <div style="width:100%;">
          <div class="image-label">${escapeHtml(images[1].label || "Bild 2")}</div>
          <img src="${images[1].src}" alt="${escapeHtml(images[1].name)}">
        </div>
      </div>
    `
    : "";

  return `
    <table class="doc-image-table">
      <tr>
        <td>${first}</td>
        <td>${second}</td>
      </tr>
    </table>
  `;
}

function buildRichSectionBlock(title, html, imageKey) {
  let content = `
    <div class="doc-block">
      <div class="section-title">${escapeHtml(title)}</div>
      <div class="doc-p">${html || ""}</div>
    </div>
  `;

  if ((imageState[imageKey] || []).length) {
    content += `
      <div class="doc-block">
        ${imagesMarkup(imageKey)}
      </div>
    `;
  }

  return content;
}

function createRoleItem(data = { role: "", task: "" }) {
  const wrapper = document.createElement("div");
  wrapper.className = "repeat-item role-item";
  wrapper.innerHTML = `
    <h4>Rolle</h4>
    <label>Rolle</label>
    <input class="role-name" value="${escapeHtml(data.role)}">
    <label>Aufgaben</label>
    <textarea class="role-task">${escapeHtml(data.task)}</textarea>
    <button type="button" class="btn-danger remove-role">Entfernen</button>
  `;

  wrapper.querySelectorAll("input, textarea").forEach(el => {
    el.addEventListener("input", renderPreview);
  });

  wrapper.querySelector(".remove-role").addEventListener("click", () => {
    wrapper.remove();
    renderPreview();
  });

  return wrapper;
}

function createHistoryItem(data = { reason: "", creator: "", date: "" }) {
  const wrapper = document.createElement("div");
  wrapper.className = "repeat-item history-item";
  wrapper.innerHTML = `
    <h4>Historie</h4>
    <label>Grund der Änderung</label>
    <input class="hist-reason" value="${escapeHtml(data.reason)}">
    <div class="grid-2">
      <div>
        <label>Ersteller</label>
        <input class="hist-creator" value="${escapeHtml(data.creator)}">
      </div>
      <div>
        <label>Stand</label>
        <input class="hist-date" value="${escapeHtml(data.date)}">
      </div>
    </div>
    <button type="button" class="btn-danger remove-history">Entfernen</button>
  `;

  wrapper.querySelectorAll("input").forEach(el => {
    el.addEventListener("input", renderPreview);
  });

  wrapper.querySelector(".remove-history").addEventListener("click", () => {
    wrapper.remove();
    renderPreview();
  });

  return wrapper;
}

function createSignatureItem(data = { name: "", signature: "" }) {
  const wrapper = document.createElement("div");
  wrapper.className = "repeat-item signature-item";

  wrapper.innerHTML = `
    <h4>Unterschrift</h4>
    <div class="grid-2">
      <div>
        <label>Name</label>
        <input class="signature-name" value="${escapeHtml(data.name)}">
      </div>
      <div>
        <label>Unterschrift / Text</label>
        <input class="signature-sign" value="${escapeHtml(data.signature)}" placeholder="z. B. handschriftlich oder Name">
      </div>
    </div>
    <button type="button" class="btn-danger remove-signature">Entfernen</button>
  `;

  wrapper.querySelectorAll("input").forEach(el => {
    el.addEventListener("input", renderPreview);
  });

  wrapper.querySelector(".remove-signature").addEventListener("click", () => {
    wrapper.remove();
    renderPreview();
  });

  return wrapper;
}

function fillDefaults() {
  els.rolesContainer.innerHTML = "";
  defaultRoles.forEach(item => els.rolesContainer.appendChild(createRoleItem(item)));

  els.historyContainer.innerHTML = "";
  defaultHistory.forEach(item => els.historyContainer.appendChild(createHistoryItem(item)));

  els.signatureContainer.innerHTML = "";
  defaultSignatures.forEach(item => els.signatureContainer.appendChild(createSignatureItem(item)));
}

function getRoles() {
  return [...document.querySelectorAll(".role-item")]
    .map(item => ({
      role: item.querySelector(".role-name").value.trim(),
      task: item.querySelector(".role-task").value.trim()
    }))
    .filter(item => item.role || item.task);
}

function getHistory() {
  return [...document.querySelectorAll(".history-item")]
    .map(item => ({
      reason: item.querySelector(".hist-reason").value.trim(),
      creator: item.querySelector(".hist-creator").value.trim(),
      date: item.querySelector(".hist-date").value.trim()
    }))
    .filter(item => item.reason || item.creator || item.date);
}

function getSignatures() {
  return [...document.querySelectorAll(".signature-item")]
    .map(item => ({
      name: item.querySelector(".signature-name").value.trim(),
      signature: item.querySelector(".signature-sign").value.trim()
    }))
    .filter(item => item.name || item.signature);
}

function getFormData() {
  return {
    companyLine: els.companyLine.value.trim(),
    docTitle: els.docTitle.value.trim(),
    docNumber: els.docNumber.value.trim(),
    rev: els.rev.value.trim(),
    printDate: els.printDate.value,
    pageInfo: els.pageInfo.value.trim(),

    purposeTitle: els.purposeTitle.value.trim(),
    scopeTitle: els.scopeTitle.value.trim(),
    processTitle: els.processTitle.value.trim(),
    safetyTitle: els.safetyTitle.value.trim(),
    documentationTitle: els.documentationTitle.value.trim(),

    purpose: els.purpose.value || "",
    scope: els.scope.value || "",
    process: els.process.value || "",
    safety: els.safety.value || "",
    documentation: els.documentation.value || "",

    author: els.author.value.trim(),
    reviewer: els.reviewer.value.trim(),
    approver: els.approver.value.trim(),
    updated: els.updated.value.trim(),

    roles: getRoles(),
    history: getHistory(),
    signatures: getSignatures(),
    images: cloneImageState()
  };
}

function buildPageHeaderHtml(data) {
  return `
    <table class="page-header-table">
      <tr>
        <td class="page-header-left-cell">
          <div class="page-header-kicker">Arbeitsanweisung</div>
          <div class="page-header-title">${escapeHtml(data.docTitle)}</div>
        </td>
        <td class="page-header-right-cell">
          <img src="${logoDataUrl || logoPath}" alt="Logo" class="page-header-logo">
        </td>
      </tr>
    </table>
  `;
}

function buildPageFooterHtml(data, pageNumber, totalPages) {
  return `
    <table class="page-footer-table">
      <tr>
        <td class="page-footer-col-1">
          <span class="page-footer-line">Ersteller/Datum: ${escapeHtml(data.author)}</span>
          <span class="page-footer-line">Aktualisiert/ Datum: ${escapeHtml(data.updated || "")}</span>
          <span class="page-footer-line">Dokumentname: ${escapeHtml(data.docNumber)}</span>
          <span class="page-footer-line">Rev.-Nr. ${escapeHtml(data.rev)}</span>
        </td>
        <td class="page-footer-col-2">
          <span class="page-footer-line">Prüfer/Datum: ${escapeHtml(data.reviewer)}</span>
        </td>
        <td class="page-footer-col-3">
          <span class="page-footer-line">Freigeber/Datum: ${escapeHtml(data.approver)}</span>
          <span class="page-footer-line">Druckdatum: ${escapeHtml(data.printDate)} / Seite ${pageNumber} von ${totalPages}</span>
        </td>
      </tr>
    </table>
  `;
}

function buildDocumentHtml(data) {
  const roleRows = data.roles.map(item => `
    <tr>
      <td>${escapeHtml(item.role)}</td>
      <td>${escapeHtml(item.task)}</td>
    </tr>
  `).join("") || '<tr><td colspan="2">Keine Einträge vorhanden.</td></tr>';

  const historyRows = data.history.map(item => `
    <tr>
      <td>${escapeHtml(item.reason)}</td>
      <td>${escapeHtml(item.creator)}</td>
      <td>${escapeHtml(item.date)}</td>
    </tr>
  `).join("") || '<tr><td colspan="3">Keine Historie vorhanden.</td></tr>';

  const signatureRows = data.signatures.map(item => `
    <tr>
      <td style="width:40%;">${escapeHtml(item.name)}</td>
      <td>${item.signature ? escapeHtml(item.signature) : '<span class="signature-line"></span>'}</td>
    </tr>
  `).join("");

  const signaturesBlock = signatureRows ? `
    <div class="doc-block">
      <div class="section-title">Zusätzliche Unterschriften</div>
      <table class="signature-table">
        <thead>
          <tr>
            <th>Name</th>
            <th>Unterschrift</th>
          </tr>
        </thead>
        <tbody>
          ${signatureRows}
        </tbody>
      </table>
    </div>
  ` : "";

  return `
    ${buildRichSectionBlock(data.purposeTitle || "Zweck", data.purpose, "purpose")}
    ${buildRichSectionBlock(data.scopeTitle || "Geltungsbereich", data.scope, "scope")}

    <div class="doc-block">
      <div class="section-title">Verantwortlichkeiten</div>
      <table class="doc-table">
        <thead>
          <tr>
            <th style="width: 28%;">Rolle</th>
            <th>Aufgaben</th>
          </tr>
        </thead>
        <tbody>
          ${roleRows}
        </tbody>
      </table>
    </div>

    ${buildRichSectionBlock(data.processTitle || "Arbeitsablauf", data.process, "process")}
    ${buildRichSectionBlock(data.safetyTitle || "Sicherheitshinweise", data.safety, "safety")}
    ${buildRichSectionBlock(data.documentationTitle || "Dokumentation", data.documentation, "documentation")}

    <div class="doc-block">
      <table class="meta-table">
        <thead>
          <tr>
            <th>Grund der Änderung</th>
            <th>Ersteller</th>
            <th>Stand</th>
          </tr>
        </thead>
        <tbody>
          ${historyRows}
        </tbody>
      </table>
    </div>

    ${signaturesBlock}
  `;
}

function paginatePreview() {
  const data = getFormData();

  const tempWrapper = document.createElement("div");
  tempWrapper.innerHTML = buildDocumentHtml(data);

  const contentBlocks = [...tempWrapper.children].map(block => block.cloneNode(true));

  els.preview.innerHTML = "";

  function createPageShell(pageNumber, totalPages) {
    const page = document.createElement("div");
    page.className = "document-page";

    const header = document.createElement("div");
    header.innerHTML = buildPageHeaderHtml(data);

    const content = document.createElement("div");
    content.className = "page-content";

    const footer = document.createElement("div");
    footer.className = "page-footer";
    footer.innerHTML = buildPageFooterHtml(data, pageNumber, totalPages);

    page.appendChild(header);
    page.appendChild(content);
    page.appendChild(footer);

    els.preview.appendChild(page);

    return { page, header, content, footer };
  }

  let pageObj = createPageShell(1, 1);

  const pageHeight = pageObj.page.clientHeight;
  const headerHeight = pageObj.header ? pageObj.header.offsetHeight : 0;
  const footerHeight = pageObj.footer ? pageObj.footer.offsetHeight : 74;
  const pageStyles = window.getComputedStyle(pageObj.page);
  const paddingTop = parseFloat(pageStyles.paddingTop) || 0;
  const paddingBottom = parseFloat(pageStyles.paddingBottom) || 0;
  const safety = 6;

  const maxContentHeight = pageHeight - headerHeight - footerHeight - paddingTop - paddingBottom - safety;
  pageObj.content.style.maxHeight = `${maxContentHeight}px`;

  const pages = [pageObj];

  contentBlocks.forEach(block => {
    pageObj.content.appendChild(block);

    if (pageObj.content.scrollHeight > maxContentHeight && pageObj.content.children.length > 1) {
      pageObj.content.removeChild(block);

      const cut = document.createElement("div");
      cut.className = "page-cut-label";
      cut.textContent = "Seitenumbruch";
      els.preview.appendChild(cut);

      pageObj = createPageShell(pages.length + 1, 1);
      pageObj.content.style.maxHeight = `${maxContentHeight}px`;
      pages.push(pageObj);
      pageObj.content.appendChild(block);
    }
  });

  const totalPages = pages.length;

  pages.forEach((item, index) => {
    item.footer.innerHTML = buildPageFooterHtml(data, index + 1, totalPages);
  });
}

function renderPreview() {
  paginatePreview();
}

function downloadWordFile() {
  const content = `
    <html xmlns:o="urn:schemas-microsoft-com:office:office"
          xmlns:w="urn:schemas-microsoft-com:office:word"
          xmlns="http://www.w3.org/TR/REC-html40">
    <head>
      <meta charset="utf-8">
      <title>${escapeHtml(els.docTitle.value.trim() || "Arbeitsanweisung")}</title>
      <style>
        @page { size: A4; margin: 14mm; }
        body { font-family: Arial, Helvetica, sans-serif; color: #000; }

        .document-page { display:flex; flex-direction:column; min-height:297mm; page-break-after:always; }
        .document-page:last-child { page-break-after:auto; }
        .page-content { flex:1 1 auto; }
        .page-cut-label { display:none; }

        .page-header-table { width:100%; border-collapse:collapse; table-layout:fixed; margin:0 0 14px 0; border-bottom:1px solid #c9c9c9; }
        .page-header-table td { border:none !important; padding:0 0 10px 0; vertical-align:bottom; }
        .page-header-left-cell { width:auto; }
        .page-header-right-cell { width:58mm; text-align:right; }
        .page-header-kicker { font-size:17px; font-weight:bold; color:#7b7b7b; line-height:1.1; }
        .page-header-title { font-size:17px; font-weight:bold; color:#6f6f6f; line-height:1.15; margin-top:2px; }
        .page-header-logo { width:54mm; max-width:54mm; height:auto; display:block; margin-left:auto; }

        .page-footer { margin-top:auto; border-top:1px solid #c9c9c9; padding-top:8px; height:74px; min-height:74px; font-size:10.5px; color:#8f8f8f; overflow:hidden; }
        .page-footer-table { width:100%; border-collapse:collapse; table-layout:fixed; }
        .page-footer-table td { border:none !important; padding:0; vertical-align:top; font-size:10.5px; line-height:1.2; color:#8f8f8f; }
        .page-footer-col-1 { width:42%; }
        .page-footer-col-2 { width:23%; padding-left:14px; }
        .page-footer-col-3 { width:35%; text-align:right; }
        .page-footer-line { display:block; white-space:normal; word-break:break-word; }

        .section-title { font-size:15px; font-weight:bold; text-decoration:underline; margin:16px 0 6px; }
        .doc-p, .doc-p div, .doc-p p, .doc-p li, td, th, div { font-size:12px; line-height:1.5; }
        .doc-p ul, .doc-p ol { margin:6px 0; padding-left:22px; }

        table { border-collapse:collapse; width:100%; margin-top:8px; }
        th, td { border:1px solid #666; padding:6px 8px; text-align:left; vertical-align:top; }
        th { background:#f2f2f2; }

        .doc-image-table { width:100%; border-collapse:collapse; table-layout:fixed; margin:10px 0 12px; }
        .doc-image-table td { width:50%; vertical-align:top; border:none; padding:0 5px; }
        .doc-image-table td:first-child { padding-left:0; }
        .doc-image-table td:last-child { padding-right:0; }
        .doc-image-card { border:1px solid #666; padding:6px; min-height:150px; background:#fff; overflow:hidden; }
        .doc-image-card img { width:100%; height:auto; max-height:190px; object-fit:contain; display:block; }

        .signature-line { display:block; width:100%; min-height:24px; border-bottom:1px solid #999; }
      </style>
    </head>
    <body>${els.preview.innerHTML}</body>
    </html>
  `;

  const blob = new Blob(["\ufeff", content], { type: "application/msword" });
  const fileNameBase = (els.docNumber.value.trim() || els.docTitle.value.trim() || "arbeitsanweisung")
    .replace(/[\/:*?"<>|]+/g, "_");

  const link = document.createElement("a");
  link.href = URL.createObjectURL(blob);
  link.download = `${fileNameBase}.doc`;
  document.body.appendChild(link);
  link.click();
  document.body.removeChild(link);

  setTimeout(() => URL.revokeObjectURL(link.href), 1000);
}

function saveJsonTemplate() {
  const data = getFormData();
  const blob = new Blob([JSON.stringify(data, null, 2)], { type: "application/json" });

  const link = document.createElement("a");
  link.href = URL.createObjectURL(blob);

  const fileNameBase = (data.docNumber || data.docTitle || "arbeitsanweisung_vorlage")
    .replace(/[\/:*?"<>|]+/g, "_");

  link.download = `${fileNameBase}.json`;
  document.body.appendChild(link);
  link.click();
  document.body.removeChild(link);

  setTimeout(() => URL.revokeObjectURL(link.href), 1000);
}

function loadJsonTemplate(file) {
  const reader = new FileReader();

  reader.onload = e => {
    try {
      const data = JSON.parse(e.target.result);

      els.companyLine.value = data.companyLine || "";
      els.docTitle.value = data.docTitle || "";
      els.docNumber.value = data.docNumber || "";
      els.rev.value = data.rev || "";
      els.printDate.value = data.printDate || "";
      els.pageInfo.value = data.pageInfo || "";

      els.purposeTitle.value = data.purposeTitle || "Zweck";
      els.scopeTitle.value = data.scopeTitle || "Geltungsbereich";
      els.processTitle.value = data.processTitle || "Arbeitsablauf";
      els.safetyTitle.value = data.safetyTitle || "Sicherheitshinweise";
      els.documentationTitle.value = data.documentationTitle || "Dokumentation";

      els.purpose.value = data.purpose || "";
      els.scope.value = data.scope || "";
      els.process.value = data.process || "";
      els.safety.value = data.safety || "";
      els.documentation.value = data.documentation || "";

      loadRichEditor("purposeEditor", data.purpose || "");
      loadRichEditor("scopeEditor", data.scope || "");
      loadRichEditor("processEditor", data.process || "");
      loadRichEditor("safetyEditor", data.safety || "");
      loadRichEditor("documentationEditor", data.documentation || "");

      els.author.value = data.author || "";
      els.reviewer.value = data.reviewer || "";
      els.approver.value = data.approver || "";
      els.updated.value = data.updated || "";

      els.rolesContainer.innerHTML = "";
      (data.roles || []).forEach(item => els.rolesContainer.appendChild(createRoleItem(item)));
      if (!data.roles || !data.roles.length) {
        els.rolesContainer.appendChild(createRoleItem());
      }

      els.historyContainer.innerHTML = "";
      (data.history || []).forEach(item => els.historyContainer.appendChild(createHistoryItem(item)));
      if (!data.history || !data.history.length) {
        els.historyContainer.appendChild(createHistoryItem());
      }

      els.signatureContainer.innerHTML = "";
      (data.signatures || []).forEach(item => els.signatureContainer.appendChild(createSignatureItem(item)));
      if (!data.signatures || !data.signatures.length) {
        els.signatureContainer.appendChild(createSignatureItem());
      }

      ["purpose", "scope", "process", "safety", "documentation"].forEach(key => {
        imageState[key] = (data.images && data.images[key])
          ? data.images[key].map((img, index) => ({
              ...img,
              label: img.label || `Bild ${index + 1}`
            }))
          : [];

        refreshImagePreview(key);
      });

      renderPreview();
    } catch (error) {
      alert("Die JSON-Datei konnte nicht geladen werden.");
    }
  };

  reader.readAsText(file, "utf-8");
}

async function loadLogo() {
  logoDataUrl = logoPath;
  renderPreview();
}

function resetForm() {
  els.companyLine.value = "Arbeitsanweisung – VW Wunstorf";
  els.docTitle.value = "Kommunikation bei Störungen und Verzögerungen in der Disposition";
  els.docNumber.value = "AA_059_0021 Kommunikation bei Störungen und Verzögerungen";
  els.rev.value = "1.0";
  els.pageInfo.value = "Seite 1 von 2";

  els.purposeTitle.value = "Zweck";
  els.scopeTitle.value = "Geltungsbereich";
  els.processTitle.value = "Arbeitsablauf";
  els.safetyTitle.value = "Sicherheitshinweise";
  els.documentationTitle.value = "Dokumentation";

  els.purpose.value = "Diese Arbeitsanweisung beschreibt das standardisierte Vorgehen bei Störungen oder Verzögerungen im Ablauf der Disposition. Ziel ist eine schnelle Reaktion, klare Kommunikationswege und eine nachvollziehbare Dokumentation.";
  els.scope.value = "Diese Arbeitsanweisung gilt für alle Mitarbeitenden der Disposition sowie beteiligte Bereiche im Wareneingang, Warenausgang und Lager.";
  els.process.value = `<ul>
    <li>Verspäteten LKW anhand geplanter Ankunftszeit prüfen.</li>
    <li>Fahrer oder Spedition telefonisch kontaktieren.</li>
    <li>Neue Ankunftszeit und Ursache dokumentieren.</li>
    <li>Lager, Wareneingang oder Warenausgang informieren.</li>
    <li>Bei fehlender Ware Lagerplatz erneut prüfen lassen.</li>
    <li>Abweichung auf den Unterlagen vermerken und Rücksprache mit dem Vorgesetzten halten.</li>
  </ul>`;
  els.safety.value = `<ul>
    <li>Keine eigenständigen Änderungen ohne Freigabe durchführen.</li>
    <li>Sicherheitsvorschriften und interne Vorgaben einhalten.</li>
    <li>Bei unklaren Situationen den Vorgang stoppen und Rücksprache halten.</li>
  </ul>`;
  els.documentation.value = `<ul>
    <li>Eintrag in Excel oder Schichtprotokoll.</li>
    <li>Abweichung auf Lieferschein, B-Beleg oder Ladeliste vermerken.</li>
    <li>Telefonische Rücksprache bei Bedarf im Tagesverlauf festhalten.</li>
  </ul>`;

  loadRichEditor("purposeEditor", els.purpose.value);
  loadRichEditor("scopeEditor", els.scope.value);
  loadRichEditor("processEditor", els.process.value);
  loadRichEditor("safetyEditor", els.safety.value);
  loadRichEditor("documentationEditor", els.documentation.value);

  els.author.value = "MAD / 21.04.2026";
  els.reviewer.value = "MED / 21.04.2026";
  els.approver.value = "CBE / 21.04.2026";
  els.updated.value = "";

  const today = new Date();
  els.printDate.value = today.toISOString().slice(0, 10);

  ["purpose", "scope", "process", "safety", "documentation"].forEach(key => {
    imageState[key] = [];
    refreshImagePreview(key);
  });

  fillDefaults();
  renderPreview();
}

[...document.querySelectorAll("input, textarea, select")].forEach(el => {
  el.addEventListener("input", renderPreview);
  el.addEventListener("change", renderPreview);
});

els.addRoleBtn.addEventListener("click", () => {
  els.rolesContainer.appendChild(createRoleItem());
  renderPreview();
});

els.addHistoryBtn.addEventListener("click", () => {
  els.historyContainer.appendChild(createHistoryItem());
  renderPreview();
});

els.addSignatureBtn.addEventListener("click", () => {
  els.signatureContainer.appendChild(createSignatureItem());
  renderPreview();
});

els.downloadBtn.addEventListener("click", downloadWordFile);

els.printBtn.addEventListener("click", () => {
  renderPreview();
  requestAnimationFrame(() => {
    requestAnimationFrame(() => {
      window.print();
    });
  });
});

els.saveJsonBtn.addEventListener("click", saveJsonTemplate);
els.loadJsonBtn.addEventListener("click", () => els.jsonFileInput.click());

els.jsonFileInput.addEventListener("change", e => {
  const file = e.target.files[0];
  if (file) loadJsonTemplate(file);
  e.target.value = "";
});

els.resetBtn.addEventListener("click", resetForm);

if (!els.printDate.value) {
  const today = new Date();
  els.printDate.value = today.toISOString().slice(0, 10);
}

fillDefaults();
initRichEditor("purposeEditor");
initRichEditor("scopeEditor");
initRichEditor("processEditor");
initRichEditor("safetyEditor");
initRichEditor("documentationEditor");
ensureImageUploadUI();
resetForm();
loadLogo();