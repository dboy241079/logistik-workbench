// /LKW/js/stammdaten-ac-live-sach.js
(() => {
  "use strict";

  const API = window.STAMMDATEN_API_URL || "";
  const MIN_CHARS = 1;
  const DEBOUNCE_MS = 180;
  const LIMIT = 30;

  if (!API) {
    console.warn("STAMMDATEN_API_URL fehlt. Setze window.STAMMDATEN_API_URL in halle3.php");
  }

  // Shared dropdown
  let dd, ddList;
  let activeInput = null;
  let items = [];
  let activeIndex = -1;

  // per input state
  const state = new WeakMap(); // { t, abort }

  function ensureDropdown() {
    if (dd) return;

    dd = document.createElement("div");
    dd.id = "ac-sachnummer";
    dd.className = "fixed z-[99999] hidden bg-white border border-slate-300 rounded-md shadow-lg overflow-hidden";
    dd.innerHTML = `<div class="max-h-64 overflow-auto text-xs"></div>`;
    ddList = dd.firstElementChild;

    document.body.appendChild(dd);

    // click outside closes
    document.addEventListener("mousedown", (e) => {
      if (dd.classList.contains("hidden")) return;
      if (e.target === dd || dd.contains(e.target)) return;
      if (activeInput && (e.target === activeInput)) return;
      hide();
    });

    window.addEventListener("scroll", () => position(), true);
    window.addEventListener("resize", () => position());
  }

  function position() {
    if (!dd || !activeInput || dd.classList.contains("hidden")) return;
    const r = activeInput.getBoundingClientRect();
    dd.style.left = `${Math.round(r.left)}px`;
    dd.style.top = `${Math.round(r.bottom + 4)}px`;
    dd.style.width = `${Math.round(r.width)}px`;
  }

  function hide() {
    if (!dd) return;
    dd.classList.add("hidden");
    ddList.innerHTML = "";
    items = [];
    activeIndex = -1;
  }

  function setActive(i) {
    activeIndex = i;
    const rows = Array.from(ddList.children);
    rows.forEach(el => el.classList.remove("bg-slate-100"));
    if (rows[i]) {
      rows[i].classList.add("bg-slate-100");
      rows[i].scrollIntoView({ block: "nearest" });
    }
  }

  function pick(i) {
    const it = items[i];
    if (!activeInput || !it) return;

    activeInput.value = it.sachnummer || "";
    // optional: Lagergruppe als Dataset ablegen (falls du’s woanders brauchst)
    if (it.lagergruppe) activeInput.dataset.lagergruppe = it.lagergruppe;

    activeInput.dispatchEvent(new Event("input", { bubbles: true }));
    activeInput.dispatchEvent(new Event("change", { bubbles: true }));

    hide();
    activeInput.focus();
  }


  function showEmpty(message) {
  ensureDropdown();
  items = [];
  activeIndex = -1;

  ddList.innerHTML = `
    <div class="px-2 py-2 text-slate-500 text-xs">
      ${escapeHtml(message || "Keine Ergebnisse")}
    </div>
  `;

  position();
  dd.classList.remove("hidden");
  }

  function render(list) {
    ensureDropdown();
    items = (Array.isArray(list) ? list : []).slice(0, LIMIT);
    activeIndex = -1;

    ddList.innerHTML = "";

    if (!items.length) {
  showEmpty("Keine Ergebnisse");
  return;
}


    for (let i = 0; i < items.length; i++) {
      const it = items[i];

      const row = document.createElement("div");
      row.className = "px-2 py-1.5 cursor-pointer hover:bg-slate-100 flex items-center justify-between gap-2";

      const left = document.createElement("div");
      left.className = "font-medium text-slate-900";
      left.textContent = it.sachnummer || "";

      const right = document.createElement("div");
      right.className = "text-[10px] text-slate-500 whitespace-nowrap";
      right.textContent = it.lagergruppe ? `LG: ${it.lagergruppe}` : "";

      row.appendChild(left);
      row.appendChild(right);

      row.addEventListener("mouseenter", () => setActive(i));
      row.addEventListener("mousedown", (e) => {
        e.preventDefault(); // verhindert blur
        pick(i);
      });

      ddList.appendChild(row);
    }

    position();
    dd.classList.remove("hidden");
  }

  async function fetchSachnummer(q, signal) {
    const url =
      `${API}?type=sachnummer&action=list&q=${encodeURIComponent(q)}`;

    const res = await fetch(url, { signal, cache: "no-store" });

    // wichtig: damit du sofort siehst, wenn PHP Warnings/HTML kommen
    const text = await res.text();
    let data;
    try {
      data = JSON.parse(text);
    } catch (e) {
      console.error("Stammdaten-API liefert kein JSON:", text);
      throw new Error("Stammdaten-API liefert kein JSON (PHP Warning / falscher Pfad).");
    }

    if (!data || data.ok !== true) {
      throw new Error(data?.error || "Stammdaten-API: ok=false");
    }

    return Array.isArray(data.items) ? data.items : [];
  }

  function schedule(input) {
    const st = state.get(input) || {};
    state.set(input, st);

    if (st.t) clearTimeout(st.t);

    st.t = setTimeout(async () => {
      const q = (input.value || "").trim();
      if (q.length < MIN_CHARS) {
        hide();
        return;
      }

      // abort previous
      if (st.abort) st.abort.abort();
      st.abort = new AbortController();

      try {
        const list = await fetchSachnummer(q, st.abort.signal);
        // nur zeigen, wenn dieses input noch aktiv ist
        if (activeInput === input) render(list);
      } catch (err) {
        // wenn aborted -> ignorieren
        if (String(err?.name) === "AbortError") return;
        console.error(err);
        hide();
      }
    }, DEBOUNCE_MS);
  }

  function attachSachnummerAC(input) {
    if (!input) return;
    ensureDropdown();

    // nicht doppelt binden
    if (input.dataset.acSachBound === "1") return;
    input.dataset.acSachBound = "1";

    input.setAttribute("autocomplete", "off");

    input.addEventListener("focus", () => {
      activeInput = input;
      schedule(input);
    });

    input.addEventListener("blur", () => {
      setTimeout(() => {
        // falls du ins dropdown klickst, soll es nicht sofort schließen
        if (document.activeElement !== input) hide();
      }, 140);
    });

    input.addEventListener("input", () => {
      activeInput = input;
      schedule(input);
    });

    input.addEventListener("keydown", (e) => {
      if (!dd || dd.classList.contains("hidden")) return;

      if (e.key === "ArrowDown") {
        e.preventDefault();
        setActive(Math.min(activeIndex + 1, items.length - 1));
      } else if (e.key === "ArrowUp") {
        e.preventDefault();
        setActive(Math.max(activeIndex - 1, 0));
      } else if (e.key === "Enter") {
        if (activeIndex >= 0) {
          e.preventDefault();
          pick(activeIndex);
        } else {
          hide();
        }
      } else if (e.key === "Escape") {
        hide();
      }
    });
  }
  function escapeHtml(s) {
  return String(s ?? "")
    .replaceAll("&", "&amp;")
    .replaceAll("<", "&lt;")
    .replaceAll(">", "&gt;")
    .replaceAll('"', "&quot;")
    .replaceAll("'", "&#039;");
}


  // ✅ wichtig: exakt der Name, den deine halle3.js nutzt
  window.attachSachnummerAC = attachSachnummerAC;
})();
