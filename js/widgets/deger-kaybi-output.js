/* DRN.EKİN OTO — Değer Kaybı Çıktı Modülleri
 * 3 sunum: ekran (live render), print, PDF (html2pdf.js).
 * Ortak: renderReport(state, result) → HTML string.
 */
(function (root) {
  'use strict';

  const TRY = (n) => (typeof n === 'number' ? n.toLocaleString('tr-TR', {maximumFractionDigits: 2}) : n);
  const CURR = (n) => (typeof n === 'number' ? n.toLocaleString('tr-TR', {maximumFractionDigits: 0}) + ' ₺' : n);

  /**
   * Ekran (widget içi) sonuç bölgesi — kompakt.
   */
  function renderScreen(state, result) {
    if (!result || !result.ok) {
      return `<div class="dk-result-error">${result?.error || 'Hesaplama yapılamadı'}</div>`;
    }
    const b = result.breakdown;
    return `
      <div class="dk-result">
        <div class="dk-result-total">
          <span class="dk-result-label">Tahmini Değer Kaybı</span>
          <span class="dk-result-value">${CURR(result.total)}</span>
        </div>
        <details class="dk-result-detail">
          <summary>Hesaplama Detayı</summary>
          <table class="dk-detail-table">
            <tr><th>Rayiç Bedel (C5)</th><td>${CURR(b.C5)}</td></tr>
            <tr><th>Rayiç Katsayısı (C7)</th><td>${TRY(b.C7)}</td></tr>
            <tr><th>KM Katsayısı (F9)</th><td>${TRY(b.F9)}</td></tr>
            <tr><th>Hasar Katsayısı (J3)</th><td>${TRY(b.J3)}</td></tr>
            <tr><th>Ticari/Kiralık (J5)</th><td>${TRY(b.J5)}</td></tr>
            <tr><th>SBM Geçmiş (J7)</th><td>${TRY(b.J7)}</td></tr>
            <tr><th>Düşük KM Bonusu (J9)</th><td>${TRY(b.J9)}</td></tr>
            <tr><th>Parça Toplamı</th><td>Değişen ${TRY(b.changeSum)} + Onarım ${TRY(b.repairSum)} + Boya ${TRY(b.paintSum)} = ${TRY(b.partsTotal)}</td></tr>
            <tr><th>Hasar Oranı Etkisi</th><td>${TRY(b.damageRatio)}</td></tr>
            ${b.motorcycleMultiplier > 1 ? `<tr><th>Motorsiklet Çarpanı</th><td>×${b.motorcycleMultiplier}</td></tr>` : ''}
          </table>
          <div class="dk-formula">
            <code>${b.C5} × ${TRY(b.C7)} × ${TRY(b.F9)} × (1 + (${TRY(b.J5)} + ${TRY(b.J7)} + ${TRY(b.J9)})) × ${TRY(b.J3)}${b.motorcycleMultiplier > 1 ? ` × ${b.motorcycleMultiplier}` : ''}</code>
          </div>
        </details>
      </div>
    `;
  }

  /**
   * Tam ekspertiz raporu HTML — KapakBilgileri şablonuna yakın.
   * Print + PDF için ortak kullanılır.
   */
  function renderReport(state, result) {
    const meta = state.meta || {};
    const today = new Date().toLocaleDateString('tr-TR');
    const b = result.breakdown;

    return `
      <div class="dk-report">
        <header class="dk-report-header">
          <h1>DEĞER KAYBI EKSPERTİZ RAPORU</h1>
          <div class="dk-report-meta">
            <div><strong>Rapor Tarihi:</strong> ${today}</div>
            <div><strong>Rapor No:</strong> ${meta.reportNo || '—'}</div>
            <div><strong>Düzenleyen:</strong> DRN.EKİN OTO Hasar Onarım Merkezi</div>
          </div>
        </header>

        <section class="dk-report-section">
          <h2>Araç Bilgileri</h2>
          <table>
            <tr><th>Araç Grubu</th><td>${state.group} — ${root.DKTables.VEHICLE_GROUPS[state.group] || ''}</td></tr>
            <tr><th>Plaka</th><td>${meta.plate || '—'}</td></tr>
            <tr><th>Marka / Tip</th><td>${meta.brand || '—'}</td></tr>
            <tr><th>Model Yılı</th><td>${meta.modelYear || '—'}</td></tr>
            <tr><th>Şasi No</th><td>${meta.chassisNo || '—'}</td></tr>
            <tr><th>Kilometre</th><td>${TRY(state.km)} km</td></tr>
            <tr><th>Rayiç Bedel</th><td>${CURR(state.price)}</td></tr>
            <tr><th>Hasar Bedeli</th><td>${CURR(state.damage)}</td></tr>
            <tr><th>Ticari/Kiralık</th><td>${state.isCommercial ? 'Evet' : 'Hayır'}</td></tr>
            <tr><th>SBM Geçmiş Hasar</th><td>${state.sbmCount} adet</td></tr>
          </table>
        </section>

        ${state.partSelections && state.partSelections.length ? `
        <section class="dk-report-section">
          <h2>Hasar Detayı (Parçalar)</h2>
          <table>
            <thead><tr><th>Parça</th><th>İşlem</th></tr></thead>
            <tbody>
              ${state.partSelections.map(s => {
                const part = root.DKTables.PARTS[state.group].find(p => p.id === s.partId);
                return `<tr><td>${part ? part.name : s.partId}</td><td>${labelMode(s.mode)}</td></tr>`;
              }).join('')}
            </tbody>
          </table>
        </section>
        ` : ''}

        <section class="dk-report-section">
          <h2>Hesap Özeti</h2>
          <table>
            <tr><th>Rayiç Bedel × Rayiç Katsayısı</th><td>${CURR(b.C5)} × ${TRY(b.C7)}</td></tr>
            <tr><th>Kilometre Katsayısı</th><td>${TRY(b.F9)}</td></tr>
            <tr><th>Genel Değerlendirme (1 + J5+J7+J9)</th><td>${TRY(1 + b.J5 + b.J7 + b.J9)}</td></tr>
            <tr><th>Hasar Katsayısı (J3)</th><td>${TRY(b.J3)}</td></tr>
            ${b.motorcycleMultiplier > 1 ? `<tr><th>Motorsiklet Çarpanı</th><td>×${b.motorcycleMultiplier}</td></tr>` : ''}
            <tr class="dk-total-row"><th>TOPLAM DEĞER KAYBI</th><td>${CURR(result.total)}</td></tr>
          </table>
        </section>

        <footer class="dk-report-footer">
          <p>Bu rapor SBM (Sigorta Bilgi ve Gözetim Merkezi) standart hesaplama yöntemine göre düzenlenmiştir.</p>
          <p>Tahmini değer kaybı, sigorta tazminat süreçlerinde referans olarak kullanılabilir; nihai tutar ekspertiz onayına tabidir.</p>
          <div class="dk-report-signature">
            <div>Eksper Adı / İmza</div>
            <div>DRN.EKİN OTO Hasar Onarım Merkezi</div>
          </div>
        </footer>
      </div>
    `;
  }

  function labelMode(mode) {
    return ({
      'change':        'DEĞİŞTİ',
      'repair_light':  'ONARILDI (Hafif)',
      'repair_mid':    'ONARILDI (Orta)',
      'repair_heavy':  'ONARILDI (Ağır)',
      'paint_full':    'BOYA (TAM)',
      'paint_local':   'BOYA (LOKAL)'
    })[mode] || mode;
  }

  /**
   * Yazdır — yeni pencere değil, body'ye geçici overlay + window.print()
   */
  function printReport(state, result) {
    const html = renderReport(state, result);
    const overlay = document.createElement('div');
    overlay.id = 'dk-print-overlay';
    overlay.innerHTML = html;
    document.body.appendChild(overlay);
    document.body.classList.add('dk-printing');

    const cleanup = () => {
      document.body.classList.remove('dk-printing');
      if (overlay.parentNode) overlay.parentNode.removeChild(overlay);
      window.removeEventListener('afterprint', cleanup);
    };
    window.addEventListener('afterprint', cleanup);

    setTimeout(() => window.print(), 100);
  }

  /**
   * PDF olarak indir — html2pdf.js gerekir (CDN'den lazy load).
   */
  function exportPdf(state, result) {
    const html = renderReport(state, result);
    const wrapper = document.createElement('div');
    wrapper.style.position = 'fixed';
    wrapper.style.top = '-99999px';
    wrapper.innerHTML = html;
    document.body.appendChild(wrapper);

    const fileName = `deger-kaybi-${(state.meta?.plate || 'rapor').replace(/\s+/g, '_')}-${Date.now()}.pdf`;

    const ensureLib = () => new Promise((resolve, reject) => {
      if (root.html2pdf) return resolve(root.html2pdf);
      const s = document.createElement('script');
      s.src = 'https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js';
      s.onload = () => resolve(root.html2pdf);
      s.onerror = () => reject(new Error('html2pdf.js yüklenemedi'));
      document.head.appendChild(s);
    });

    ensureLib().then(html2pdf => {
      html2pdf().set({
        margin: 10,
        filename: fileName,
        html2canvas: { scale: 2 },
        jsPDF: { unit: 'mm', format: 'a4', orientation: 'portrait' }
      }).from(wrapper.firstElementChild).save().then(() => {
        if (wrapper.parentNode) wrapper.parentNode.removeChild(wrapper);
      });
    }).catch(err => {
      alert('PDF oluşturulamadı: ' + err.message);
      if (wrapper.parentNode) wrapper.parentNode.removeChild(wrapper);
    });
  }

  root.DKOutput = { renderScreen, renderReport, printReport, exportPdf };
})(window);
