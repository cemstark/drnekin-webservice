/* DRN.EKİN OTO — Değer Kaybı Hesaplama Çekirdeği
 * Excel formülü (Hesaplama!H1):
 *   ToplamDK = C5 × C7 × F9 × (1 + (J5+J7+J9)) × J3 × (F=motorsiklet ? 2.5 : 1)
 *
 * math.js opsiyonel — varsa formül evaluasyonunda kullanılır,
 * yoksa native JS ile hesaplanır (graceful degradation).
 */
(function (root) {
  'use strict';

  const T = root.DKTables;
  if (!T) throw new Error('DKTables yüklenmedi — deger-kaybi-tables.js önce yüklenmeli.');

  /**
   * Bir aralık matrisinde değere karşılık gelen kolon index'ini bulur.
   * @param {Array<[number,number]>} ranges
   * @param {number} value
   */
  function rangeIndex(ranges, value) {
    for (let i = 0; i < ranges.length; i++) {
      const [lo, hi] = ranges[i];
      if (value >= lo && value <= hi) return i;
    }
    return ranges.length - 1;
  }

  /**
   * Rayiç bedel katsayısı (C7).
   */
  function priceCoeff(group, price) {
    const row = T.PRICE_COEFF[group];
    if (!row || price <= 0) return 0;
    return row[rangeIndex(T.PRICE_RANGES, price)];
  }

  /**
   * Kilometre / çalışma saati katsayısı (F9).
   */
  function kmCoeff(group, km) {
    if (group === 'D') {
      const row = T.HOUR_COEFF.D;
      return row[rangeIndex(T.HOUR_RANGES, km)];
    }
    const row = T.KM_COEFF[group];
    if (!row) return 1;
    return row[rangeIndex(T.KM_RANGES, km)];
  }

  /**
   * J5: Ticari/kiralık katsayısı.
   */
  function commercialCoeff(isCommercial) {
    return isCommercial ? T.GENERAL_COEFF.commercial : 0;
  }

  /**
   * J7: SBM hasar geçmişi katsayısı (max -0.15).
   */
  function sbmCoeff(count) {
    if (!count || count <= 0) return 0;
    if (count <= 5) return count * T.GENERAL_COEFF.sbmPerIncident;
    return T.GENERAL_COEFF.sbmMaxNegative;
  }

  /**
   * J9: Düşük km bonusu — A,F,C,B,Ç,E gruplarında km<=1000 → +0.05
   */
  function lowKmBonus(group, km) {
    const eligibleGroups = ['A', 'B', 'C', 'Ç', 'E', 'F'];
    if (eligibleGroups.includes(group) && km <= 1000) {
      return T.GENERAL_COEFF.lowKmBonus;
    }
    return 0;
  }

  /**
   * Parça katsayıları toplamı (C46+H46+K46 toplamı).
   * @param {Array} selections - [{ partId, mode: 'change'|'repair_light'|'repair_mid'|'repair_heavy'|'paint_full'|'paint_local' }]
   * @param {string} group
   */
  function partsSum(selections, group) {
    const parts = T.PARTS[group];
    if (!parts || !selections || !selections.length) return { changeSum: 0, repairSum: 0, paintSum: 0 };

    let changeSum = 0, repairSum = 0, paintSum = 0;

    for (const sel of selections) {
      const part = parts.find(p => p.id === sel.partId);
      if (!part) continue;

      switch (sel.mode) {
        case 'change':
          changeSum += part.change || 0;
          break;
        case 'repair_light':
          if (part.repair) repairSum += part.repair.light || 0;
          break;
        case 'repair_mid':
          if (part.repair) repairSum += part.repair.mid || 0;
          break;
        case 'repair_heavy':
          if (part.repair) repairSum += part.repair.heavy || 0;
          break;
        case 'paint_full':
          paintSum += part.paint || 0;
          break;
        case 'paint_local':
          // Lokal boya katsayısı ≈ TAM'ın %50'si (Excel formülünde TAM/LOKAL ayrımı için K kolonu TAM, lokal hafif/orta/ağır onarımdan gelir)
          paintSum += (part.paint || 0) * 0.5;
          break;
      }
    }

    return { changeSum, repairSum, paintSum };
  }

  /**
   * J3: Hasar katsayısı.
   * J3 = (parça_toplamları + ((hasar/rayiç)*100*0.1)) / 100
   */
  function damageCoeff(parts, damageAmount, price) {
    const partsTotal = (parts.changeSum || 0) + (parts.repairSum || 0) + (parts.paintSum || 0);
    const damageRatio = price > 0 ? (damageAmount / price) * 100 * 0.1 : 0;
    return (partsTotal + damageRatio) / 100;
  }

  /**
   * Ana hesaplama fonksiyonu.
   * @param {Object} input
   * @returns {Object} sonuç + tüm ara katsayılar
   */
  function calculate(input) {
    const {
      group = 'A',
      price = 0,
      damage = 0,
      km = 0,
      isCommercial = false,
      sbmCount = 0,
      partSelections = []
    } = input;

    if (!T.PARTS[group]) {
      return {
        ok: false,
        error: `${group} grubu henüz desteklenmiyor (V1: sadece A-Otomobil)`,
        total: 0
      };
    }

    if (price <= 0) {
      return { ok: false, error: 'Rayiç bedel 0\'dan büyük olmalı', total: 0 };
    }

    const C5 = price;
    const F3 = damage;
    const C7 = priceCoeff(group, price);
    const F9 = kmCoeff(group, km);
    const J5 = commercialCoeff(isCommercial);
    const J7 = sbmCoeff(sbmCount);
    const J9 = lowKmBonus(group, km);

    const parts = partsSum(partSelections, group);
    const J3 = damageCoeff(parts, F3, C5);

    let total = C5 * C7 * F9 * (1 + (J5 + J7 + J9)) * J3;
    if (group === 'F') total *= T.MOTORCYCLE_MULTIPLIER;

    // math.js varsa precision validation yap
    if (root.math && typeof root.math.evaluate === 'function') {
      try {
        const expr = `${C5} * ${C7} * ${F9} * (1 + (${J5} + ${J7} + ${J9})) * ${J3}` +
                     (group === 'F' ? ` * ${T.MOTORCYCLE_MULTIPLIER}` : '');
        const mjsResult = root.math.evaluate(expr);
        // math.js ile aynı sonucu vermeli — sapma %0.001 üzerinde uyarı verir
        if (Math.abs(mjsResult - total) / Math.max(Math.abs(total), 1) > 0.00001) {
          console.warn('[DK] math.js / native sapma:', mjsResult, 'vs', total);
        }
        total = mjsResult; // math.js sonucunu kullan (precision)
      } catch (e) {
        console.warn('[DK] math.js eval hata:', e);
      }
    }

    return {
      ok: true,
      total: Math.round(total),
      breakdown: {
        C5, C7, F9, J3, J5, J7, J9,
        partsTotal: parts.changeSum + parts.repairSum + parts.paintSum,
        changeSum: parts.changeSum,
        repairSum: parts.repairSum,
        paintSum: parts.paintSum,
        damageRatio: C5 > 0 ? (F3 / C5) * 100 * 0.1 : 0,
        motorcycleMultiplier: group === 'F' ? T.MOTORCYCLE_MULTIPLIER : 1
      }
    };
  }

  root.DKCore = { calculate, priceCoeff, kmCoeff, partsSum };
})(window);
