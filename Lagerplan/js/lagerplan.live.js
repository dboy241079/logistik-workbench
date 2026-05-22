console.log("✅ lagerplan.live.js geladen");

// /LKW/Lagerplan/js/lagerplan.live.js
(() => {
  const Live = {
    since: 0,
    timer: null,
    inFlight: false,

    cfg: {
      pollUrl: "/Lagerplan/api/lagerplan_updates.php",
      pollInterval: 2000,
      debug: false,
      onPatch: null,      // function(patch)
      mapRowToPatch: null // optional: function(row) -> patch
    },

    init(options = {}) {
      this.cfg = { ...this.cfg, ...options };

      if (typeof this.cfg.mapRowToPatch !== "function") {
        this.cfg.mapRowToPatch = (row) => ({
          type: "place_update",
          place: `${row.halle}-${row.zone}-${row.reihe}-${row.platz}-${row.slot_index}`,
          row
        });
      }

      return this._bootstrap();
    },

    async _bootstrap() {
      const peekUrl = this._urlWithParams(this.cfg.pollUrl, { peek: 1 });

      const data = await this._fetchJson(peekUrl);
      if (data?.ok) this.since = Number(data.since || 0);

      if (this.cfg.debug) console.log("[Live] bootstrap since =", this.since);
      this.start();
    },

    start() {
      if (this.timer) clearInterval(this.timer);
      this.timer = setInterval(() => this.tick(), this.cfg.pollInterval);
      this.tick();
    },

    stop() {
      if (this.timer) clearInterval(this.timer);
      this.timer = null;
    },

    async tick() {
      if (this.inFlight) return;       // ✅ kein Overlap
      this.inFlight = true;

      const url = this._urlWithParams(this.cfg.pollUrl, { since: this.since });

      try {
        const data = await this._fetchJson(url);
        if (!data?.ok) return;

        this.since = Number(data.since || this.since);

        if (this.cfg.debug) {
          console.log("[Live] tick =>", { count: data.count, since: this.since, url });
        }

        if (Array.isArray(data.rows) && data.rows.length) {
          for (const row of data.rows) {
            const patch = this.cfg.mapRowToPatch(row);
            if (typeof this.cfg.onPatch === "function") this.cfg.onPatch(patch);
          }
        }
      } catch (e) {
        console.warn("[Live] tick failed", { url, err: String(e) });
      } finally {
        this.inFlight = false;
      }
    },

    _urlWithParams(base, params) {
      const u = new URL(base, window.location.href);
      Object.entries(params).forEach(([k, v]) => u.searchParams.set(k, String(v)));
      return u.toString();
    },

    async _fetchJson(url) {
      const ctl = new AbortController();
      const t = setTimeout(() => ctl.abort(), 8000);

      try {
        const r = await fetch(url, {
          cache: "no-store",
          credentials: "same-origin",
          signal: ctl.signal
        });

        const text = await r.text();

        if (!r.ok) {
          console.error("[Live] HTTP error", r.status, url, text.slice(0, 300));
          return { ok: false, error: "http_" + r.status };
        }

        try {
          return JSON.parse(text);
        } catch {
          console.error("[Live] Non-JSON response", url, text.slice(0, 500));
          return { ok: false, error: "non_json_response" };
        }
      } catch (e) {
        console.error("[Live] fetch failed", url, e);
        return { ok: false, error: "fetch_failed" };
      } finally {
        clearTimeout(t);
      }
    }
  };

  window.LagerplanLive = Live;
})();
