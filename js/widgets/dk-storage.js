/* DRN.EKİN OTO — Değer Kaybı Kayıt Storage (localStorage CRUD)
 * Kayıt = bir değer kaybı dosyası (form state + meta + hesap sonucu).
 */
(function (root) {
  'use strict';

  const KEY = 'drnDKRecords';

  function readAll() {
    try { return JSON.parse(localStorage.getItem(KEY)) || []; }
    catch { return []; }
  }
  function writeAll(arr) {
    try { localStorage.setItem(KEY, JSON.stringify(arr)); return true; }
    catch (e) { console.error('[DKStorage] yazma hatası:', e); return false; }
  }

  function uid() {
    return 'dk_' + Date.now().toString(36) + '_' + Math.random().toString(36).slice(2, 7);
  }

  /**
   * Tüm kayıtları döndür (en yeniden eskiye).
   */
  function list() {
    return readAll().sort((a, b) => (b.updatedAt || 0) - (a.updatedAt || 0));
  }

  function get(id) {
    return readAll().find(r => r.id === id) || null;
  }

  /**
   * Kaydet — id varsa update, yoksa yeni.
   * @returns {string} kayıt ID
   */
  function save(record) {
    const all = readAll();
    const now = Date.now();
    if (record.id) {
      const idx = all.findIndex(r => r.id === record.id);
      if (idx >= 0) {
        all[idx] = { ...all[idx], ...record, updatedAt: now };
      } else {
        all.push({ ...record, createdAt: now, updatedAt: now });
      }
    } else {
      record.id = uid();
      record.createdAt = now;
      record.updatedAt = now;
      all.push(record);
    }
    writeAll(all);
    return record.id;
  }

  function remove(id) {
    const all = readAll().filter(r => r.id !== id);
    return writeAll(all);
  }

  function clear() {
    return writeAll([]);
  }

  /**
   * Listede gösterilecek özet alanlar.
   */
  function summarize(record) {
    return {
      id: record.id,
      date: record.updatedAt ? new Date(record.updatedAt).toLocaleDateString('tr-TR') : '—',
      time: record.updatedAt ? new Date(record.updatedAt).toLocaleTimeString('tr-TR', {hour:'2-digit',minute:'2-digit'}) : '—',
      plate: record.meta?.plate || '—',
      brand: record.meta?.brand || '—',
      modelYear: record.meta?.modelYear || '—',
      reportNo: record.meta?.reportNo || '—',
      group: record.group || 'A',
      price: record.price || 0,
      damage: record.damage || 0,
      km: record.km || 0,
      total: record.calculatedTotal || 0,
      partsCount: record.partSelections?.length || 0,
      status: record.status || 'active' // 'active' | 'delivered'
    };
  }

  /**
   * Durum güncelle (aktif ↔ teslim).
   */
  function setStatus(id, status) {
    const all = readAll();
    const idx = all.findIndex(r => r.id === id);
    if (idx < 0) return false;
    all[idx].status = status;
    all[idx].updatedAt = Date.now();
    if (status === 'delivered') all[idx].deliveredAt = Date.now();
    return writeAll(all);
  }

  /**
   * Metric istatistikleri.
   */
  function stats() {
    const all = readAll();
    return {
      total: all.length,
      active: all.filter(r => (r.status || 'active') === 'active').length,
      delivered: all.filter(r => r.status === 'delivered').length
    };
  }

  root.DKStorage = { list, get, save, remove, clear, summarize, setStatus, stats };
})(window);
