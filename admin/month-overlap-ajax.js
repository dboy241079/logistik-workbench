(() => {
  let activeController = null;

  async function reloadMonthSection(form) {
    const oldSection = document.getElementById("month-overlap-section");
    if (!oldSection) return;

    // URL aus aktuellem Pfad + Form-Parametern bauen
    const url = new URL(form.action || window.location.href, window.location.origin);
    url.search = new URLSearchParams(new FormData(form)).toString();

    // Vorherigen Request abbrechen (wenn User schnell mehrfach klickt)
    if (activeController) activeController.abort();
    activeController = new AbortController();

    oldSection.classList.add("opacity-60");
    oldSection.style.pointerEvents = "none";

    try {
      const res = await fetch(url.toString(), {
        headers: { "X-Requested-With": "XMLHttpRequest" },
        signal: activeController.signal
      });

      if (!res.ok) throw new Error(`HTTP ${res.status}`);

      const html = await res.text();
      const doc = new DOMParser().parseFromString(html, "text/html");
      const newSection = doc.querySelector("#month-overlap-section");

      if (!newSection) {
        throw new Error("Section #month-overlap-section im Response nicht gefunden.");
      }

      oldSection.replaceWith(newSection);

      // URL im Browser aktualisieren (damit Bookmark/Reload auf gleichem Monat bleibt)
      history.replaceState({}, "", `${url.pathname}${url.search}`);

      // Falls du nach dem Replace noch JS initialisieren musst:
      // window.initAbsenceGrid?.();
      // window.bindAbsenceEvents?.();
    } catch (err) {
      if (err.name !== "AbortError") {
        console.error(err);
        alert("Monatsansicht konnte nicht geladen werden.");
      }
    } finally {
      const current = document.getElementById("month-overlap-section");
      if (current) {
        current.classList.remove("opacity-60");
        current.style.pointerEvents = "";
      }
    }
  }

  // Delegiert auf das Formular
  document.addEventListener("submit", (e) => {
    const form = e.target.closest(".js-month-switch-form");
    if (!form) return;
    e.preventDefault();
    reloadMonthSection(form);
  });
})();
