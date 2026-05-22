(function () {
  "use strict";

  const MobileCameraScanner = {
    modalId: "mobileCameraScannerModal",
    videoId: "mobileCameraScannerVideo",
    canvasId: "mobileCameraScannerCanvas",
    statusId: "mobileCameraScannerStatus",
    previewId: "mobileCameraScannerPreview",
    manualInputId: "mobileCameraScannerManualInput",

    stream: null,
    detector: null,
    scanTimer: null,
    isOpen: false,
    lastValue: "",
    lastAt: 0,

    targetInputId: "searchRefInput",
    targetMode: "button", // button | callback | none
    targetButtonId: "btnSearchRef",
    targetCallbackName: "",

    async init() {
      this.injectStyles();
      this.injectModal();
      this.bindButtons();
    },

    bindButtons() {
      document.getElementById("btnOpenMobileScanner")?.addEventListener("click", () => {
        this.open({
          title: "Referenz / Sachnummer / Lieferschein scannen",
          targetInputId: "searchRefInput",
          targetMode: "button",
          targetButtonId: "btnSearchRef"
        });
      });

      document.getElementById("btnOpenManualRefScanner")?.addEventListener("click", () => {
        this.open({
          title: "Referenz für manuelle Einlagerung scannen",
          targetInputId: "manualRef",
          targetMode: "none"
        });
      });

      document.getElementById("btnOpenOutRefScanner")?.addEventListener("click", () => {
        this.open({
          title: "Referenz zum Ausbuchen scannen",
          targetInputId: "outRef",
          targetMode: "callback",
          targetCallbackName: "startOutbookFromRef"
        });
      });

      document.getElementById("mobileCameraScannerClose")?.addEventListener("click", () => this.close());
      document.getElementById("mobileCameraScannerCancel")?.addEventListener("click", () => this.close());
      document.getElementById("mobileCameraScannerUseValue")?.addEventListener("click", () => this.useManualValue());

      document.getElementById(this.manualInputId)?.addEventListener("keydown", (e) => {
        if (e.key === "Enter") {
          e.preventDefault();
          this.useManualValue();
        }
      });

      document.getElementById(this.modalId)?.addEventListener("click", (e) => {
        if (e.target?.id === this.modalId) this.close();
      });

      document.addEventListener("keydown", (e) => {
        if (e.key === "Escape" && this.isOpen) this.close();
      });

      document.addEventListener("visibilitychange", () => {
        if (document.hidden && this.isOpen) this.close();
      });
    },

    async open(options = {}) {
      this.targetInputId = options.targetInputId || "searchRefInput";
      this.targetMode = options.targetMode || "none";
      this.targetButtonId = options.targetButtonId || "";
      this.targetCallbackName = options.targetCallbackName || "";

      const titleEl = document.getElementById("mobileCameraScannerTitle");
      if (titleEl) titleEl.textContent = options.title || "Barcode mit Kamera scannen";

      this.resetUi();

      const modal = document.getElementById(this.modalId);
      if (!modal) return;

      modal.classList.remove("d-none");
      modal.classList.add("d-flex");
      this.isOpen = true;

      if (!("BarcodeDetector" in window)) {
        this.setStatus("Dieser Browser unterstützt Kamera-Barcode-Scan hier nicht. Bitte Chrome auf Android nutzen oder den Code unten manuell eingeben.", "error");
        return;
      }

      try {
        const supported = await BarcodeDetector.getSupportedFormats();
        const preferred = [
          "code_128",
          "code_39",
          "codabar",
          "ean_13",
          "ean_8",
          "upc_a",
          "upc_e",
          "itf"
        ].filter(fmt => supported.includes(fmt));

        this.detector = preferred.length
          ? new BarcodeDetector({ formats: preferred })
          : new BarcodeDetector();

        this.setStatus("Kamera wird gestartet …", "info");

        this.stream = await navigator.mediaDevices.getUserMedia({
          video: {
            facingMode: { ideal: "environment" }
          },
          audio: false
        });

        const video = document.getElementById(this.videoId);
        if (!video) return;

        video.srcObject = this.stream;
        await video.play();

        this.setStatus("Kamera aktiv. Barcode ins Bild halten.", "success");
        this.startLoop();
      } catch (err) {
        console.error("Kamera-Startfehler:", err);
        this.setStatus("Kamera konnte nicht gestartet werden. Bitte Berechtigung prüfen oder unten manuell eingeben.", "error");
      }
    },

    async close() {
      this.stopLoop();

      const video = document.getElementById(this.videoId);
      if (video) {
        try {
          video.pause();
          video.srcObject = null;
        } catch (_) {}
      }

      if (this.stream) {
        this.stream.getTracks().forEach(track => track.stop());
        this.stream = null;
      }

      const modal = document.getElementById(this.modalId);
      if (modal) {
        modal.classList.add("d-none");
        modal.classList.remove("d-flex");
      }

      this.isOpen = false;
    },

    startLoop() {
      this.stopLoop();

      this.scanTimer = setInterval(async () => {
        await this.scanFrame();
      }, 350);
    },

    stopLoop() {
      if (this.scanTimer) {
        clearInterval(this.scanTimer);
        this.scanTimer = null;
      }
    },

    async scanFrame() {
      if (!this.detector) return;

      const video = document.getElementById(this.videoId);
      const canvas = document.getElementById(this.canvasId);
      if (!video || !canvas) return;
      if (video.readyState < 2) return;

      const w = video.videoWidth || 0;
      const h = video.videoHeight || 0;
      if (!w || !h) return;

      canvas.width = w;
      canvas.height = h;

      const ctx = canvas.getContext("2d", { willReadFrequently: true });
      if (!ctx) return;

      ctx.drawImage(video, 0, 0, w, h);

      try {
        const barcodes = await this.detector.detect(canvas);
        if (!Array.isArray(barcodes) || !barcodes.length) return;

        const value = String(barcodes[0].rawValue || "").trim();
        if (!value) return;

        const now = Date.now();
        if (value === this.lastValue && now - this.lastAt < 1500) return;

        this.lastValue = value;
        this.lastAt = now;

        const preview = document.getElementById(this.previewId);
        if (preview) preview.value = value;

        this.setStatus(`Erkannt: ${value}`, "success");
        this.applyValue(value);

        if (typeof window.soundSuccess === "function") {
          window.soundSuccess();
        } else if (typeof window.beepSuccess === "function") {
          window.beepSuccess();
        }

        this.close();
      } catch (err) {
        console.error("Scanfehler:", err);
      }
    },

    applyValue(value) {
      const input = document.getElementById(this.targetInputId);
      if (!input) {
        this.setStatus("Zielfeld nicht gefunden.", "error");
        return;
      }

      input.value = value;
      input.dispatchEvent(new Event("input", { bubbles: true }));
      input.dispatchEvent(new Event("change", { bubbles: true }));
      input.focus();

      if (this.targetMode === "button") {
        const btn = document.getElementById(this.targetButtonId);
        if (btn) btn.click();
        return;
      }

      if (this.targetMode === "callback") {
        const fn = window[this.targetCallbackName];
        if (typeof fn === "function") {
          fn();
        }
        return;
      }
    },

    useManualValue() {
      const input = document.getElementById(this.manualInputId);
      const value = String(input?.value || "").trim();

      if (!value) {
        this.setStatus("Bitte einen Wert eingeben.", "error");
        return;
      }

      this.applyValue(value);

      if (typeof window.soundSuccess === "function") {
        window.soundSuccess();
      } else if (typeof window.beepSuccess === "function") {
        window.beepSuccess();
      }

      this.close();
    },

    resetUi() {
      const preview = document.getElementById(this.previewId);
      const manual = document.getElementById(this.manualInputId);

      if (preview) preview.value = "";
      if (manual) manual.value = "";

      this.setStatus("Bereit.", "info");
    },

    setStatus(message, type = "info") {
      const el = document.getElementById(this.statusId);
      if (!el) return;

      let cls = "small rounded px-2 py-1 border mb-2 ";
      if (type === "success") {
        cls += "bg-success-subtle text-success border-success-subtle";
      } else if (type === "error") {
        cls += "bg-danger-subtle text-danger border-danger-subtle";
      } else {
        cls += "bg-light text-secondary border-light";
      }

      el.className = cls;
      el.textContent = message;
    },

    injectModal() {
      if (document.getElementById(this.modalId)) return;

      document.body.insertAdjacentHTML("beforeend", `
        <div id="${this.modalId}" class="d-none position-fixed top-0 start-0 w-100 h-100 align-items-center justify-content-center" style="z-index:99999;background:rgba(0,0,0,.65);">
          <div class="bg-white rounded-4 shadow p-3 mobile-camera-modal">
            <div class="d-flex justify-content-between align-items-center mb-2">
              <div class="fw-semibold" id="mobileCameraScannerTitle">Barcode mit Kamera scannen</div>
              <button type="button" id="mobileCameraScannerClose" class="btn btn-sm btn-outline-secondary">×</button>
            </div>

            <div id="${this.statusId}" class="small rounded px-2 py-1 border mb-2 bg-light text-secondary border-light">
              Bereit.
            </div>

            <div class="mobile-camera-video-wrap mb-2">
              <video id="${this.videoId}" autoplay playsinline muted></video>
              <canvas id="${this.canvasId}" class="d-none"></canvas>
            </div>

            <div class="mb-2">
              <label class="form-label small fw-semibold mb-1">Erkannt</label>
              <input id="${this.previewId}" class="form-control form-control-sm" readonly>
            </div>

            <div class="border-top pt-2 mt-2">
              <label class="form-label small fw-semibold mb-1">Falls Kamera nichts erkennt: manuell eingeben</label>
              <div class="input-group input-group-sm">
                <input id="${this.manualInputId}" class="form-control" placeholder="Barcode eingeben …" autocomplete="off">
                <button type="button" id="mobileCameraScannerUseValue" class="btn btn-outline-primary">Übernehmen</button>
              </div>
            </div>

            <div class="d-flex justify-content-end mt-3">
              <button type="button" id="mobileCameraScannerCancel" class="btn btn-sm btn-outline-secondary">Schließen</button>
            </div>
          </div>
        </div>
      `);
    },

    injectStyles() {
      if (document.getElementById("mobileCameraScannerStyles")) return;

      const style = document.createElement("style");
      style.id = "mobileCameraScannerStyles";
      style.textContent = `
        .mobile-camera-modal {
          width: min(96vw, 560px);
        }

        .mobile-camera-video-wrap {
          width: 100%;
          min-height: 280px;
          border: 1px solid #cbd5e1;
          border-radius: 16px;
          overflow: hidden;
          background: #0f172a;
          display: flex;
          align-items: center;
          justify-content: center;
        }

        .mobile-camera-video-wrap video {
          width: 100%;
          height: auto;
          display: block;
        }

        @media (max-width: 768px) {
          .mobile-camera-modal {
            width: 96vw;
            max-height: 92vh;
            overflow: auto;
          }

          .mobile-camera-video-wrap {
            min-height: 220px;
          }
        }
      `;
      document.head.appendChild(style);
    }
  };

  window.MobileCameraScanner = MobileCameraScanner;

  document.addEventListener("DOMContentLoaded", () => {
    MobileCameraScanner.init();
  });
})();