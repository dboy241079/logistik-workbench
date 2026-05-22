// /LKW/Lagerplan/js/lagerplan.viewport.js
(() => {
  const clamp = (v, min, max) => Math.max(min, Math.min(max, v));

  const Viewport = {
    cfg: null,
    vp: null,
    content: null,

    scale: 1,
    x: 0,
    y: 0,

    dragging: false,
    pointerId: null,
    last: { x: 0, y: 0 },

    rafId: null,

    init(cfg = {}) {
      this.cfg = {
        wrap: "#planViewport",
        content: "#planContent",
        minScale: 0.4,
        maxScale: 2.5,
        step: 0.12,
        ctrlZoomOnly: true,

        // Auf diesen Elementen NICHT pannen
        ignoreSelector: "a,button,input,select,textarea,[data-no-pan],.slot,.platz",

        ...cfg
      };

      this.vp = document.querySelector(this.cfg.wrap);
      this.content = document.querySelector(this.cfg.content);

      if (!this.vp || !this.content) {
        console.warn("LagerplanViewport: wrap/content nicht gefunden", this.cfg);
        return;
      }

      // Wichtig für Handy/Tablet:
      // Browser soll im Lagerplan nicht selbst scrollen/zoomen.
      this.vp.style.touchAction = "none";
      this.vp.style.userSelect = "none";
      this.vp.style.overscrollBehavior = "contain";

      // Startzustand
      this.apply();

      // Moderne Pointer Events: Maus + Touch + Stift in einem System
      this.vp.addEventListener("pointerdown", (e) => this.onPointerDown(e), { passive: false });
      this.vp.addEventListener("pointermove", (e) => this.onPointerMove(e), { passive: false });
      this.vp.addEventListener("pointerup", (e) => this.onPointerUp(e), { passive: true });
      this.vp.addEventListener("pointercancel", (e) => this.onPointerUp(e), { passive: true });
      this.vp.addEventListener("lostpointercapture", () => this.onPointerLost(), { passive: true });

      // Wheel Zoom
      this.vp.addEventListener("wheel", (e) => this.onWheel(e), { passive: false });

      // Buttons
      const controls = this.vp.querySelector(".plan-controls");
      if (controls) {
        controls.addEventListener("click", (e) => {
          const btn = e.target.closest("[data-zoom]");
          if (!btn) return;

          const z = btn.getAttribute("data-zoom");

          if (z === "in") this.zoomBy(+1);
          if (z === "out") this.zoomBy(-1);
          if (z === "reset") this.reset();
        });
      }

      return this;
    },

    apply() {
      if (!this.content) return;

      this.content.style.transform =
        `translate3d(${this.x}px, ${this.y}px, 0) scale(${this.scale})`;
    },

    scheduleApply() {
      if (this.rafId) return;

      this.rafId = requestAnimationFrame(() => {
        this.rafId = null;
        this.apply();
      });
    },

    reset() {
      this.scale = 1;
      this.x = 0;
      this.y = 0;
      this.apply();
    },

    zoomBy(dir) {
      const next = clamp(
        this.scale + dir * this.cfg.step,
        this.cfg.minScale,
        this.cfg.maxScale
      );

      if (next === this.scale) return;

      this.scale = next;
      this.apply();
    },

    onPointerDown(e) {
      // Nur linke Maustaste bei Maus
      if (e.pointerType === "mouse" && e.button !== 0) return;

      // Nicht pannen, wenn man auf Buttons, Inputs, Slots usw. klickt
      if (e.target.closest(this.cfg.ignoreSelector)) return;

      this.dragging = true;
      this.pointerId = e.pointerId;

      this.last.x = e.clientX;
      this.last.y = e.clientY;

      this.content.classList.add("dragging");

      try {
        this.vp.setPointerCapture(e.pointerId);
      } catch (err) {
        // Falls Browser/Element Pointer Capture nicht erlaubt, einfach ignorieren
      }

      if (e.cancelable) {
        e.preventDefault();
      }
    },

    onPointerMove(e) {
      if (!this.dragging) return;
      if (this.pointerId !== e.pointerId) return;

      const dx = e.clientX - this.last.x;
      const dy = e.clientY - this.last.y;

      this.last.x = e.clientX;
      this.last.y = e.clientY;

      this.x += dx;
      this.y += dy;

      // Performance: nicht bei jedem Pixel sofort rendern,
      // sondern maximal einmal pro Frame.
      this.scheduleApply();

      if (e.cancelable) {
        e.preventDefault();
      }
    },

    onPointerUp(e) {
      if (this.pointerId !== null && e.pointerId !== this.pointerId) return;

      this.dragging = false;
      this.pointerId = null;

      this.content?.classList.remove("dragging");

      try {
        this.vp.releasePointerCapture(e.pointerId);
      } catch (err) {
        // ignorieren
      }
    },

    onPointerLost() {
      this.dragging = false;
      this.pointerId = null;
      this.content?.classList.remove("dragging");
    },

    onWheel(e) {
      // Ohne STRG normal scrollen lassen
      if (this.cfg.ctrlZoomOnly && !e.ctrlKey) return;

      if (e.cancelable) {
        e.preventDefault();
      }

      const rect = this.vp.getBoundingClientRect();

      const mx = e.clientX - rect.left;
      const my = e.clientY - rect.top;

      const prev = this.scale;
      const delta = e.deltaY > 0 ? -1 : +1;

      const next = clamp(
        prev + delta * this.cfg.step,
        this.cfg.minScale,
        this.cfg.maxScale
      );

      if (next === prev) return;

      const k = next / prev;

      // Mauspunkt bleibt beim Zoomen stabil
      this.x = mx - (mx - this.x) * k;
      this.y = my - (my - this.y) * k;

      this.scale = next;
      this.apply();
    }
  };

  window.LagerplanViewport = Viewport;
})();