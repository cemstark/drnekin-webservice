/* DRN.EKİN OTO — Değer Kaybı Hesaplama Widget'ı (Form + State + Mount)
 * Bağımlılıklar: DKTables, DKCore, DKOutput, WidgetBase (sırayla yüklenmeli).
 *
 * Açılış:
 *   document.querySelector('#dk-launcher').addEventListener('click', () => DKWidget.launch());
 */
(function (root) {
  'use strict';

  const T = root.DKTables;
  const C = root.DKCore;
  const O = root.DKOutput;
  const W = root.WidgetBase;

  if (!T || !C || !O || !W) {
    console.error('[DKWidget] Bağımlılık eksik:', { T: !!T, C: !!C, O: !!O, W: !!W });
    return;
  }

  let widget = null;
  const STATE_KEY = 'drnDKState';

  function defaultState() {
    return {
      group: 'A',
      price: 0,
      damage: 0,
      km: 0,
      isCommercial: false,
      sbmCount: 0,
      partSelections: [],
      meta: { plate: '', brand: '', modelYear: '', chassisNo: '', reportNo: '' }
    };
  }

  function loadState() {
    try { return Object.assign(defaultState(), JSON.parse(sessionStorage.getItem(STATE_KEY)) || {}); }
    catch { return defaultState(); }
  }
  function saveState(s) {
    try { sessionStorage.setItem(STATE_KEY, JSON.stringify(s)); } catch {}
  }

  let state = loadState();

  function buildHtml() {
    const parts = T.PARTS[state.group] || [];
    return `
      <div class="dk-widget-content">
        <div class="dk-tabs" role="tablist">
          <button class="dk-tab active" data-tab="form" role="tab">📋 Bilgiler</button>
          <button class="dk-tab" data-tab="parts" role="tab">🔧 Parçalar</button>
          <button class="dk-tab" data-tab="meta" role="tab">📄 Rapor</button>
          <button class="dk-tab" data-tab="result" role="tab">✓ Sonuç</button>
        </div>

        <div class="dk-tab-panels">
          <!-- FORM -->
          <div class="dk-tab-panel active" data-panel="form">
            <div class="dk-field">
              <label>Araç Grubu</label>
              <select data-field="group">
                ${Object.entries(T.VEHICLE_GROUPS).map(([k, v]) =>
                  `<option value="${k}" ${state.group === k ? 'selected' : ''}${T.PARTS[k] ? '' : ' disabled'}>${k} — ${v}${T.PARTS[k] ? '' : ' (yakında)'}</option>`
                ).join('')}
              </select>
            </div>
            <div class="dk-field">
              <label>Rayiç Bedel (₺)</label>
              <input type="number" data-field="price" value="${state.price}" min="0" placeholder="Örn. 750000">
            </div>
            <div class="dk-field">
              <label>Hasar Onarım Bedeli (₺) <small>(KDV hariç, iskontosuz)</small></label>
              <input type="number" data-field="damage" value="${state.damage}" min="0" placeholder="Örn. 45000">
            </div>
            <div class="dk-field">
              <label>Kilometre</label>
              <input type="number" data-field="km" value="${state.km}" min="0" placeholder="Örn. 65000">
            </div>
            <div class="dk-field dk-field-row">
              <label class="dk-checkbox">
                <input type="checkbox" data-field="isCommercial" ${state.isCommercial ? 'checked' : ''}>
                <span>Ticari ya da kiralık araç</span>
              </label>
            </div>
            <div class="dk-field">
              <label>SBM Geçmiş Hasar Adedi</label>
              <input type="number" data-field="sbmCount" value="${state.sbmCount}" min="0" max="20" placeholder="0">
              <small>Her hasar -%3, maksimum -%15</small>
            </div>
          </div>

          <!-- PARTS -->
          <div class="dk-tab-panel" data-panel="parts">
            <div class="dk-parts-header">
              <span>Parça</span>
              <span>İşlem</span>
            </div>
            <div class="dk-parts-list">
              ${parts.map(p => `
                <div class="dk-part-row" data-part="${p.id}">
                  <span class="dk-part-name">${p.name}</span>
                  <select data-part-mode="${p.id}">
                    <option value="">— Seçiniz —</option>
                    <option value="change">Değişti (kat: ${p.change})</option>
                    ${p.repair ? `
                      <option value="repair_light">Onarıldı — Hafif (${p.repair.light})</option>
                      <option value="repair_mid">Onarıldı — Orta (${p.repair.mid})</option>
                      <option value="repair_heavy">Onarıldı — Ağır (${p.repair.heavy})</option>
                    ` : ''}
                    ${p.paint > 0 ? `
                      <option value="paint_full">Boya — TAM (${p.paint})</option>
                      <option value="paint_local">Boya — LOKAL (${(p.paint * 0.5).toFixed(2)})</option>
                    ` : ''}
                  </select>
                </div>
              `).join('')}
            </div>
          </div>

          <!-- META -->
          <div class="dk-tab-panel" data-panel="meta">
            <p class="dk-help">PDF/yazdır çıktısında görünecek araç ve rapor bilgileri.</p>
            <div class="dk-field"><label>Plaka</label><input type="text" data-meta="plate" value="${state.meta.plate}"></div>
            <div class="dk-field"><label>Marka / Tip</label><input type="text" data-meta="brand" value="${state.meta.brand}"></div>
            <div class="dk-field"><label>Model Yılı</label><input type="text" data-meta="modelYear" value="${state.meta.modelYear}"></div>
            <div class="dk-field"><label>Şasi No</label><input type="text" data-meta="chassisNo" value="${state.meta.chassisNo}"></div>
            <div class="dk-field"><label>Rapor No</label><input type="text" data-meta="reportNo" value="${state.meta.reportNo}"></div>
          </div>

          <!-- RESULT -->
          <div class="dk-tab-panel" data-panel="result">
            <div class="dk-result-area">${O.renderScreen(state, C.calculate(state))}</div>
            <div class="dk-actions">
              <button class="dk-btn dk-btn-primary" data-action="recalc">🔄 Yeniden Hesapla</button>
              <button class="dk-btn" data-action="print">🖨 Yazdır</button>
              <button class="dk-btn" data-action="pdf">📄 PDF İndir</button>
              <button class="dk-btn dk-btn-ghost" data-action="reset">⟲ Sıfırla</button>
            </div>
          </div>
        </div>
      </div>
    `;
  }

  function bind(body) {
    // Tab switch
    body.querySelectorAll('.dk-tab').forEach(tab => {
      tab.addEventListener('click', () => {
        body.querySelectorAll('.dk-tab').forEach(t => t.classList.remove('active'));
        body.querySelectorAll('.dk-tab-panel').forEach(p => p.classList.remove('active'));
        tab.classList.add('active');
        body.querySelector(`[data-panel="${tab.dataset.tab}"]`).classList.add('active');
        if (tab.dataset.tab === 'result') refreshResult(body);
      });
    });

    // Form fields
    body.querySelectorAll('[data-field]').forEach(input => {
      input.addEventListener('change', () => {
        const f = input.dataset.field;
        if (input.type === 'checkbox') state[f] = input.checked;
        else if (input.type === 'number') state[f] = parseFloat(input.value) || 0;
        else state[f] = input.value;
        saveState(state);
        if (f === 'group') rebuild();
      });
    });

    // Meta fields
    body.querySelectorAll('[data-meta]').forEach(input => {
      input.addEventListener('input', () => {
        state.meta[input.dataset.meta] = input.value;
        saveState(state);
      });
    });

    // Part selections
    body.querySelectorAll('[data-part-mode]').forEach(sel => {
      const partId = sel.dataset.partMode;
      const existing = state.partSelections.find(s => s.partId === partId);
      if (existing) sel.value = existing.mode;
      sel.addEventListener('change', () => {
        state.partSelections = state.partSelections.filter(s => s.partId !== partId);
        if (sel.value) state.partSelections.push({ partId, mode: sel.value });
        saveState(state);
      });
    });

    // Actions
    body.querySelector('[data-action="recalc"]').addEventListener('click', () => refreshResult(body));
    body.querySelector('[data-action="print"]').addEventListener('click', () => {
      const r = C.calculate(state);
      if (r.ok) O.printReport(state, r);
      else alert(r.error);
    });
    body.querySelector('[data-action="pdf"]').addEventListener('click', () => {
      const r = C.calculate(state);
      if (r.ok) O.exportPdf(state, r);
      else alert(r.error);
    });
    body.querySelector('[data-action="reset"]').addEventListener('click', () => {
      if (confirm('Tüm girişler silinsin mi?')) {
        state = defaultState();
        saveState(state);
        rebuild();
      }
    });
  }

  function refreshResult(body) {
    const area = body.querySelector('.dk-result-area');
    if (area) area.innerHTML = O.renderScreen(state, C.calculate(state));
  }

  function rebuild() {
    if (!widget || !widget.element) return;
    const body = widget.getBody();
    body.innerHTML = buildHtml();
    bind(body);
  }

  function launch() {
    if (widget && widget.element) { widget.open(); return; }
    widget = W.create({
      id: 'deger-kaybi',
      title: '🧮 Değer Kaybı Hesaplama',
      bodyHtml: buildHtml(),
      width: 520,
      height: 680,
      onOpen: (el) => bind(el.querySelector('.drn-widget-body')),
      onClose: () => { widget = null; }
    });
    widget.open();
  }

  root.DKWidget = { launch };
})(window);
