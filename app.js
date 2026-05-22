// Simple SPA with vanilla JS. No external libs.
// Core logic mirrors the Excel rules discussed mit Daniel.

const STATE_KEY = "tourenAppState_v1";

const VEHICLES = [
  { id: "veh1", title: "Fahrzeug 1", plate: "xx-xx-xxxx", driver: "Muster, Peter" },
  { id: "veh2", title: "Fahrzeug 2", plate: "xx-xx-xxxx", driver: "Muster, Peter" },
  { id: "veh3", title: "Fahrzeug 3", plate: "xx-xx-xxxx", driver: "Muster, Peter" },
];

function getMonday(d=new Date()){
  const x = new Date(d);
  const day = x.getDay(); // 0=Sun..6=Sat
  const diff = (day === 0 ? -6 : 1) - day; // back to Monday
  x.setDate(x.getDate() + diff);
  x.setHours(0,0,0,0);
  return x;
}

function formatDateISO(d){ // yyyy-mm-dd for <input type=date>
  const y = d.getFullYear();
  const m = String(d.getMonth()+1).padStart(2,'0');
  const dd = String(d.getDate()).padStart(2,'0');
  return `${y}-${m}-${dd}`;
}

function prettyHM(minutes){
  if (!isFinite(minutes) || minutes<0) return "0:00";
  const h = Math.floor(minutes/60);
  const m = Math.round(minutes%60);
  return `${h}:${String(m).padStart(2,'0')}`;
}

function parseTimeToMinutes(t){ // "HH:MM" -> minutes from 00:00
  if (!t) return null;
  const [h,m] = t.split(":").map(Number);
  if (Number.isNaN(h)||Number.isNaN(m)) return null;
  return h*60 + m;
}

function weekdayName(dateStr){
  const d = new Date(dateStr+"T00:00:00");
  return d.toLocaleDateString("de-DE", { weekday:"long" });
}

function defaultWeek(){
  const monday = getMonday(new Date());
  const days = Array.from({length:5},(_,i)=>{
    const d = new Date(monday); d.setDate(monday.getDate()+i);
    return formatDateISO(d);
  });
  // per vehicle: 5 days × 4 tours
  const tours = [];
  let num=1;
  for (const day of days){
    for (let i=0;i<4;i++){
      tours.push({
        tour: num++,
        date: day,
        depart: "", // "HH:MM"
        arrive: "",
        pauseMin: 0,
        note: "",
        reported: "", // Ja/Nein
      });
    }
  }
  const vehState = {};
  for (const v of VEHICLES){
    vehState[v.id] = JSON.parse(JSON.stringify(tours));
  }
  return { monday: formatDateISO(monday), data: vehState };
}

let state = loadState();

function loadState(){
  const raw = localStorage.getItem(STATE_KEY);
  if (raw){
    try { return JSON.parse(raw); } catch(e){}
  }
  return defaultWeek();
}
function saveState(){ localStorage.setItem(STATE_KEY, JSON.stringify(state)); }

// ----- Calculations -----
function calcDuration(dep, arr){
  const d = parseTimeToMinutes(dep);
  const a = parseTimeToMinutes(arr);
  if (d==null || a==null) return null;
  if (a<d) return null; // no overnight handling
  return a-d;
}
function calcNet(dep, arr, pauseMin){
  const dur = calcDuration(dep, arr);
  if (dur==null) return null;
  return Math.max(0, dur - (Number(pauseMin)||0));
}

// Status rules (simple):
// Rot, wenn Abfahrt gesetzt und Ankunft leer. Sonst Grün.
function rowStatus(dep, arr, date, vehId){
  if (dep && !arr) return 0; // red
  return 2; // green
}

// Daily totals per vehicle
function totalsByDay(vehId){
  const map = new Map(); // dateISO -> net minutes sum
  for (const r of state.data[vehId]){
    const net = calcNet(r.depart, r.arrive, r.pauseMin);
    if (!map.has(r.date)) map.set(r.date, 0);
    map.set(r.date, map.get(r.date) + (net||0));
  }
  return map;
}

// ----- Rendering -----
function render(){
  renderVehicle("veh1","veh1Wrap");
  renderVehicle("veh2","veh2Wrap");
  renderVehicle("veh3","veh3Wrap");
  renderOverview();
  renderWeekView();
}

function renderVehicle(vehId, mountId){
  const container = document.getElementById(mountId);
  const rows = state.data[vehId];
  const todayISO = formatDateISO(new Date());
  const totals = totalsByDay(vehId);

  const thead = `
    <thead>
      <tr>
        <th>Tour</th>
        <th>Tour-Datum</th>
        <th class="help" title="Automatisch aus dem Datum berechnet">Wochentag</th>
        <th class="help" title="Arbeitszeitfenster 06:00–20:00">Abfahrt Wunstorf</th>
        <th class="help" title="Ende der Fahrt">Ankunft Wunstorf</th>
        <th class="help" title="Ankunft minus Abfahrt">Dauer</th>
        <th class="help" title="Pausenminuten (wirken auf Netto)">Pause (min)</th>
        <th class="help" title="Dauer minus Pause">Netto</th>
        <th class="help" title="Grün: Abfahrt+Ankunft; Rot: nur Abfahrt">Status</th>
        <th class="help" title="z. B. Stau, Verzögerung Abladung">Hinweis</th>
        <th class="help" title="Pflicht: Meldung bei Eintreffen">Meldung</th>
      </tr>
    </thead>
  `;

  const tbody = rows.map((r, idx)=>{
    const isToday = r.date === todayISO;
    const dur = calcDuration(r.depart,r.arrive);
    const net = calcNet(r.depart,r.arrive,r.pauseMin);
    const status = rowStatus(r.depart,r.arrive,r.date,vehId);
    const dayTotal = totals.get(r.date)||0;
    const badge = status===2 ? 'badge green"><span class="dot"></span>OK' :
                 status===1 ? 'badge yellow"><span class="dot"></span>Hinweis' :
                               'badge red"><span class="dot"></span>Offen';
    return `
      <tr class="${isToday?'today':''}">
        <td>${r.tour}</td>
        <td><input type="date" value="${r.date}" data-veh="${vehId}" data-row="${idx}" data-field="date"></td>
        <td class="small">${weekdayName(r.date)}</td>
        <td><input type="time" min="06:00" max="20:00" value="${r.depart}" data-veh="${vehId}" data-row="${idx}" data-field="depart"></td>
        <td><input type="time" min="06:00" max="20:00" value="${r.arrive}" data-veh="${vehId}" data-row="${idx}" data-field="arrive"></td>
        <td>${dur==null?"":prettyHM(dur)}</td>
        <td><input type="number" min="0" step="1" value="${r.pauseMin||0}" data-veh="${vehId}" data-row="${idx}" data-field="pauseMin"></td>
        <td>${net==null?"":prettyHM(net)}</td>
        <td><span class="${badge}</span></td>
        <td><textarea data-veh="${vehId}" data-row="${idx}" data-field="note" placeholder="Notiz...">${r.note||""}</textarea></td>
        <td>
          <select data-veh="${vehId}" data-row="${idx}" data-field="reported">
            <option value="" ${r.reported===""?"selected":""}>–</option>
            <option value="Ja" ${r.reported==="Ja"?"selected":""}>Ja</option>
            <option value="Nein" ${r.reported==="Nein"?"selected":""}>Nein</option>
          </select>
        </td>
      </tr>
    `;
  }).join("");

  // summary per day
  const monday = getMonday(new Date(state.monday));
  const days = Array.from({length:5},(_,i)=>{
    const d = new Date(monday); d.setDate(monday.getDate()+i);
    return formatDateISO(d);
  });
  const sumRows = days.map(dateISO=>{
    const sum = totals.get(dateISO)||0;
    const wd = weekdayName(dateISO);
    const isTodayRow = dateISO===formatDateISO(new Date());
    return `<tr class="${isTodayRow?'today':''}"><td>${dateISO}</td><td>${wd}</td><td class="">${prettyHM(sum)}</td><td>${sum>600?"Ausnahme (>10h)":(sum>540?">9h":"")}</td></tr>`;
  }).join("");

  container.innerHTML = `
    <div class="note">Aktuelle Woche ab: <strong>${new Date(state.monday).toLocaleDateString("de-DE")}</strong></div>
    <table>${thead}<tbody>${tbody}</tbody></table>
    <div class="note">Tagesübersicht & Wochenregeln</div>
    <table>
      <thead><tr><th>Datum</th><th>Wochentag</th><th>Summe Netto (Tag)</th><th>Hinweis</th></tr></thead>
      <tbody>${sumRows}</tbody>
    </table>
  `;

  // attach listeners
  container.querySelectorAll("input,select,textarea").forEach(el=>{
    el.addEventListener("change", onFieldChange);
  });
}

function renderOverview(){
  const wrap = document.getElementById("overviewTableWrap");
  const todayISO = formatDateISO(new Date());
  let totalAll = 0;
  const rows = [];
  for (const v of VEHICLES){
    for (const r of state.data[v.id]){
      const dur = calcDuration(r.depart,r.arrive);
      const net = calcNet(r.depart,r.arrive,r.pauseMin);
      totalAll += net||0;
      const status = rowStatus(r.depart,r.arrive,r.date,v.id);
      const badge = status===2 ? 'badge green"><span class="dot"></span>OK' :
                   status===1 ? 'badge yellow"><span class="dot"></span>Hinweis' :
                                 'badge red"><span class="dot"></span>Offen';
      rows.push(`
        <tr class="${r.date===todayISO?'today':''}">
          <td>${v.title}</td>
          <td>${r.date}</td>
          <td class="small">${weekdayName(r.date)}</td>
          <td>${r.tour}</td>
          <td>${r.depart||""}</td>
          <td>${r.arrive||""}</td>
          <td>${dur==null?"":prettyHM(dur)}</td>
          <td>${r.pauseMin||0}</td>
          <td>${net==null?"":prettyHM(net)}</td>
          <td><span class="${badge}</span></td>
          <td class="small" title="z. B. Stau, Abladung verzögert">${(r.note||"")}</td>
          <td>${r.reported||""}</td>
        </tr>
      `);
    }
  }
  wrap.innerHTML = `
    <table>
      <thead>
        <tr>
          <th>Fahrzeug</th><th>Datum</th><th>Wochentag</th><th>Tour</th>
          <th>Abfahrt</th><th>Ankunft</th><th>Dauer</th><th>Pause (min)</th>
          <th>Netto</th><th>Status</th><th>Hinweis</th><th>Meldung</th>
        </tr>
      </thead>
      <tbody>${rows.join("")}</tbody>
    </table>
  `;
  document.getElementById("totalNetAll").textContent = prettyHM(totalAll);
}

function renderWeekView(){
  const wrap = document.getElementById("weekWrap");
  const monday = getMonday(new Date(state.monday));
  const days = Array.from({length:5},(_,i)=>{
    const d = new Date(monday); d.setDate(monday.getDate()+i);
    return d;
  });
  const rows = days.map(d=>{
    const iso = formatDateISO(d);
    const sums = VEHICLES.map(v=>{
      const total = totalsByDay(v.id).get(iso)||0;
      return `<td>${prettyHM(total)}</td>`;
    }).join("");
    const totalAll = VEHICLES.reduce((acc,v)=>acc+(totalsByDay(v.id).get(iso)||0),0);
    return `<tr class="${formatDateISO(new Date())===iso?'today':''}"><td>${d.toLocaleDateString("de-DE")}</td><td>${weekdayName(iso)}</td>${sums}<td>${prettyHM(totalAll)}</td></tr>`;
  }).join("");

  wrap.innerHTML = `
    <table>
      <thead><tr><th>Datum</th><th>Wochentag</th><th>Fahrzeug 1</th><th>Fahrzeug 2</th><th>Fahrzeug 3</th><th>Gesamt</th></tr></thead>
      <tbody>${rows}</tbody>
    </table>
  `;
}

// ----- Events -----
function onFieldChange(e){
  const el = e.target;
  const veh = el.dataset.veh;
  const row = Number(el.dataset.row);
  const field = el.dataset.field;
  if (!veh || isNaN(row) || !field) return;
  let val = el.value;
  if (field==="pauseMin") val = Number(val)||0;
  state.data[veh][row][field] = val;
  saveState();
  render(); // re-render to update calculations
}

// Tabs
document.querySelectorAll(".tab-btn").forEach(btn=>{
  btn.addEventListener("click", ()=>{
    document.querySelectorAll(".tab-btn").forEach(b=>b.classList.remove("active"));
    document.querySelectorAll(".tab").forEach(s=>s.classList.remove("active"));
    btn.classList.add("active");
    document.getElementById(btn.dataset.tab).classList.add("active");
  });
});

// Toolbar
document.getElementById("exportJson").addEventListener("click", ()=>{
  const blob = new Blob([JSON.stringify(state,null,2)], {type:"application/json"});
  const a = document.createElement("a");
  a.href = URL.createObjectURL(blob);
  a.download = "touren_week.json";
  a.click();
});

document.getElementById("importJsonBtn").addEventListener("click", ()=>{
  document.getElementById("importFile").click();
});
document.getElementById("importFile").addEventListener("change", (e)=>{
  const f = e.target.files[0];
  if (!f) return;
  const reader = new FileReader();
  reader.onload = ()=>{
    try{
      const obj = JSON.parse(reader.result);
      state = obj;
      saveState();
      render();
    }catch(err){
      alert("Ungültige JSON-Datei.");
    }
  };
  reader.readAsText(f);
});

document.getElementById("printBtn").addEventListener("click", ()=>window.print());

document.getElementById("resetWeek").addEventListener("click", ()=>{
  if (!confirm("Woche wirklich leeren?")) return;
  state = defaultWeek();
  saveState();
  render();
});

// Initialize
render();
