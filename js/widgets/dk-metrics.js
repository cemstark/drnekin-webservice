/* DRN.EKİN OTO — Metrics Section
 * 3 kart: Toplam araç / Serviste aktif (yeşil) / Teslim edilmiş.
 * Veri kaynağı: DKStorage.stats()
 */
(function (root) {
  'use strict';

  const S = root.DKStorage;
  if (!S) { console.error('[DKMetrics] DKStorage yok'); return; }

  let containerEl = null;

  function buildHtml() {
    const s = S.stats();
    return `
      <div class="metrics-grid">
        <div class="metric-card metric-total">
          <div class="metric-icon">🚗</div>
          <div class="metric-body">
            <div class="metric-value" data-metric="total">${s.total}</div>
            <div class="metric-label">Toplam Araç Sayısı</div>
          </div>
        </div>
        <div class="metric-card metric-active">
          <div class="metric-icon">🔧</div>
          <div class="metric-body">
            <div class="metric-value" data-metric="active">${s.active}</div>
            <div class="metric-label">Serviste Aktif</div>
          </div>
          <div class="metric-pulse"></div>
        </div>
        <div class="metric-card metric-delivered">
          <div class="metric-icon">✓</div>
          <div class="metric-body">
            <div class="metric-value" data-metric="delivered">${s.delivered}</div>
            <div class="metric-label">Teslim Edildi</div>
          </div>
        </div>
      </div>
    `;
  }

  function mount(target) {
    containerEl = (typeof target === 'string') ? document.querySelector(target) : target;
    if (!containerEl) { console.error('[DKMetrics] mount target yok'); return; }
    refresh();
  }

  function refresh() {
    if (!containerEl) return;
    containerEl.innerHTML = buildHtml();
  }

  root.DKMetrics = { mount, refresh };
})(window);
