// js/halle3.rows.layout.js
(function () {
  function el(tag, cls, attrs = {}) {
    const n = document.createElement(tag);
    if (cls) n.className = cls;
    for (const [k, v] of Object.entries(attrs)) {
      if (v == null) continue;
      if (k === "text") n.textContent = String(v);
      else n.setAttribute(k, String(v));
    }
    return n;
  }

  function buildRow({ row, zone = "W1", placeMaxForRow }) {
    const max = (typeof placeMaxForRow === "function") ? placeMaxForRow(row) : 30;

    const wrap = el(
      "div",
      "zone lager-reihe shrink-0 flex flex-col border border-slate-600 bg-yellow-100",
      { "data-zone": `${zone}-F${row}`, "data-row": row }
    );

    const head = el(
      "div",
      "px-2 py-1 border-b border-slate-400 text-[11px] font-semibold text-slate-800",
      { text: `Reihe ${row}` }
    );

    const body = el("div", "flex-1 flex flex-col");
    const cont = el("div", "platz-container p-1", {
      "data-row": row,
      "data-range-start": "1",
      "data-range-end": String(max),
    });

    body.appendChild(cont);
    wrap.appendChild(head);
    wrap.appendChild(body);
    return wrap;
  }


  function build({ mountId, zone = "W1", bands = [], placeMaxForRow }) {
    const mount = document.getElementById(mountId);
    if (!mount) return;

    mount.innerHTML = "";

    const stack = el("div", "", { id: "w1-block-rows" });

    // bands = [{from,to,label?}, ...] in der Reihenfolge: oben -> unten
    bands.forEach((b) => {
      const band = el("div", "rows-band", {
        "data-band": `${b.from}-${b.to}`
      });

      // optional Label
      if (b.label) {
        const lbl = el("div", "text-[11px] font-semibold text-slate-700 px-1", { text: b.label });
        const wrap = el("div", "rows-band-wrap");
        wrap.appendChild(lbl);
        wrap.appendChild(band);
        stack.appendChild(wrap);
      } else {
        stack.appendChild(band);
      }

      for (let r = b.from; r <= b.to; r++) {
        band.appendChild(buildRow({ row: r, zone, placeMaxForRow }));
      }
    });

    mount.appendChild(stack);
  }

  window.Halle3RowsLayout = { build };
})();
