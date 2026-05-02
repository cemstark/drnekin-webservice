/* DRN.EKİN OTO — Değer Kaybı Hesaplama Tabloları
 * Kaynak: DEĞER KAYBI HESAPLAMA GÜNCELLEME V3.xlsx (SBM standardı)
 * V1: Sadece A-Otomobil grubu. Diğer gruplar için PARTS.B/C/Ç/D/E/F yapısı korunur.
 */
(function (root) {
  'use strict';

  // Araç grubu kodları (Excel: Tablolar!B3:C16)
  const VEHICLE_GROUPS = {
    A: 'Otomobil / Taksi',
    B: 'Minibüs / Otobüs',
    C: 'Kamyonet / Kamyon / Çekici',
    'Ç': 'Özel Amaçlı / Tanker',
    D: 'İş Makinesi / Traktör / Tarım',
    E: 'Römork',
    F: 'Motorsiklet'
  };

  // Rayiç bedel katsayı matrisi (Tablolar!E3:R9)
  // Sütun aralıkları (₺): kolon index 0-12
  const PRICE_RANGES = [
    [0, 49999], [50000, 99999], [100000, 199999], [200000, 249999],
    [250000, 299999], [300000, 349999], [350000, 399999], [400000, 499999],
    [500000, 749999], [750000, 999999], [1000000, 1249999],
    [1250000, 1499999], [1500000, Infinity]
  ];

  const PRICE_COEFF = {
    A: [0.65, 0.70, 0.75, 0.80, 0.80, 0.85, 0.85, 0.90, 0.95, 1.00, 1.00, 1.00, 1.00],
    B: [0.65, 0.65, 0.65, 0.65, 0.70, 0.70, 0.75, 0.75, 0.80, 0.85, 0.90, 0.95, 1.00],
    C: [0.65, 0.65, 0.65, 0.65, 0.70, 0.70, 0.75, 0.75, 0.80, 0.85, 0.90, 0.95, 1.00],
    'Ç': [0.65, 0.65, 0.65, 0.65, 0.70, 0.70, 0.75, 0.75, 0.80, 0.85, 0.90, 0.95, 1.00],
    D: [0.65, 0.65, 0.65, 0.65, 0.70, 0.70, 0.75, 0.75, 0.80, 0.85, 0.90, 0.95, 1.00],
    E: [0.65, 0.65, 0.65, 0.65, 0.70, 0.70, 0.75, 0.75, 0.80, 0.85, 0.90, 0.95, 1.00],
    F: [0.65, 0.70, 0.75, 0.80, 0.80, 0.85, 0.85, 0.90, 0.95, 1.00, 1.00, 1.00, 1.00]
  };

  // Kilometre katsayı matrisi (Tablolar!E13:R20)
  const KM_RANGES = [
    [0, 19999], [20000, 49999], [50000, 99999], [100000, 149999],
    [150000, 199999], [200000, 299999], [300000, 499999],
    [500000, 749999], [750000, 999999], [1000000, Infinity]
  ];

  const KM_COEFF = {
    A: [1.00, 0.95, 0.90, 0.85, 0.80, 0.75, 0.70, 0.70, 0.70, 0.70],
    B: [1.00, 1.00, 0.95, 0.95, 0.90, 0.90, 0.85, 0.80, 0.75, 0.70],
    C: [1.00, 1.00, 0.95, 0.95, 0.90, 0.90, 0.85, 0.80, 0.75, 0.70],
    'Ç': [1.00, 1.00, 0.95, 0.95, 0.90, 0.90, 0.85, 0.80, 0.75, 0.70],
    E: [1.00, 1.00, 0.95, 0.95, 0.90, 0.90, 0.85, 0.80, 0.75, 0.70],
    F: [1.00, 0.95, 0.90, 0.85, 0.80, 0.75, 0.70, 0.70, 0.70, 0.70]
  };

  // D (iş makinesi/traktör) için çalışma saati matrisi
  const HOUR_RANGES = [
    [0, 500], [501, 1000], [1001, 2000], [2001, 3000],
    [3001, 4000], [4001, 5000], [5001, Infinity]
  ];
  const HOUR_COEFF = {
    D: [1.00, 0.95, 0.90, 0.85, 0.80, 0.75, 0.70]
  };

  // Genel değerlendirme katsayıları (Tablolar!E22:F26)
  const GENERAL_COEFF = {
    commercial: -0.05, // G1: ticari/kiralık ise
    sbmPerIncident: -0.03, // G2: her SBM hasarı için
    sbmMaxNegative: -0.15, // 5 hasar üstü maksimum
    lowKmBonus: 0.05 // G3: km <= 1000 ise (uygun gruplarda)
  };

  // Motorsiklet (F) için 2.5x özel çarpan
  const MOTORCYCLE_MULTIPLIER = 2.5;

  // A-Otomobil parça tablosu (Tablolar!B34:K65, 32 parça)
  // Her parça: { id, name, change (değişen kat), repair: {light, mid, heavy}, paint }
  const PARTS_A = [
    { id: 'tavan',          name: 'TAVAN SACI',                 change: 5, repair: { light: 1.0, mid: 1.5, heavy: 2.0 }, paint: 3.0 },
    { id: 'on_panel',       name: 'ÖN PANEL (SAC)',             change: 1, repair: { light: 0.5, mid: 1.0, heavy: 1.5 }, paint: 0.5 },
    { id: 'sag_on_camur',   name: 'SAĞ ÖN ÇAMURLUK (SAC)',      change: 1, repair: { light: 0.5, mid: 0.75, heavy: 1.0 }, paint: 1.0 },
    { id: 'sol_on_camur',   name: 'SOL ÖN ÇAMURLUK (SAC)',      change: 1, repair: { light: 0.5, mid: 0.75, heavy: 1.0 }, paint: 1.0 },
    { id: 'sag_on_podya',   name: 'SAĞ ÖN PODYA SACI',          change: 2, repair: { light: 0.5, mid: 0.75, heavy: 1.0 }, paint: 0.5 },
    { id: 'sol_on_podya',   name: 'SOL ÖN PODYA SACI',          change: 2, repair: { light: 0.5, mid: 0.75, heavy: 1.0 }, paint: 0.5 },
    { id: 'sag_sase_on',    name: 'SAĞ ŞASE ÖN',                change: 3, repair: { light: 1.0, mid: 1.5, heavy: 2.0 }, paint: 0.5 },
    { id: 'sol_sase_on',    name: 'SOL ŞASE ÖN',                change: 3, repair: { light: 1.0, mid: 1.5, heavy: 2.0 }, paint: 0.5 },
    { id: 'gogus',          name: 'GÖĞÜS SACI',                 change: 4, repair: { light: 1.0, mid: 1.5, heavy: 2.0 }, paint: 0.5 },
    { id: 'kaput',          name: 'MOTOR KAPUTU',               change: 1, repair: { light: 0.5, mid: 0.75, heavy: 1.0 }, paint: 1.0 },
    { id: 'sag_on_kapi',    name: 'SAĞ ÖN KAPI (KAPI SACI)',    change: 1, repair: { light: 0.5, mid: 0.75, heavy: 1.0 }, paint: 1.0 },
    { id: 'sol_on_kapi',    name: 'SOL ÖN KAPI (KAPI SACI)',    change: 1, repair: { light: 0.5, mid: 0.75, heavy: 1.0 }, paint: 1.0 },
    { id: 'sag_arka_kapi',  name: 'SAĞ ARKA KAPI (KAPI SACI)',  change: 1, repair: { light: 0.5, mid: 0.75, heavy: 1.0 }, paint: 1.0 },
    { id: 'sol_arka_kapi',  name: 'SOL ARKA KAPI (KAPI SACI)',  change: 1, repair: { light: 0.5, mid: 0.75, heavy: 1.0 }, paint: 1.0 },
    { id: 'sag_marspiyel',  name: 'SAĞ MARŞPİYEL (SAC)',        change: 2, repair: { light: 0.5, mid: 0.75, heavy: 1.0 }, paint: 0.5 },
    { id: 'sol_marspiyel',  name: 'SOL MARŞPİYEL (SAC)',        change: 2, repair: { light: 0.5, mid: 0.75, heavy: 1.0 }, paint: 0.5 },
    { id: 'a_sag',          name: 'A DİREĞİ SAĞ',               change: 1, repair: { light: 0.5, mid: 0.75, heavy: 1.0 }, paint: 0.5 },
    { id: 'b_sag',          name: 'B DİREĞİ SAĞ',               change: 2, repair: { light: 0.5, mid: 0.75, heavy: 1.0 }, paint: 0.5 },
    { id: 'a_sol',          name: 'A DİREĞİ SOL',               change: 1, repair: { light: 0.5, mid: 0.75, heavy: 1.0 }, paint: 0.5 },
    { id: 'b_sol',          name: 'B DİREĞİ SOL',               change: 2, repair: { light: 0.5, mid: 0.75, heavy: 1.0 }, paint: 0.5 },
    { id: 'bagaj',          name: 'BAGAJ KAPAĞI',               change: 1, repair: { light: 0.5, mid: 1.0, heavy: 1.5 }, paint: 1.0 },
    { id: 'arka_panel',     name: 'ARKA PANEL',                 change: 2, repair: { light: 0.5, mid: 1.0, heavy: 1.5 }, paint: 1.0 },
    { id: 'sag_arka_camur', name: 'SAĞ ARKA ÇAMURLUK',          change: 4, repair: { light: 0.5, mid: 1.0, heavy: 1.5 }, paint: 1.0 },
    { id: 'sol_arka_camur', name: 'SOL ARKA ÇAMURLUK',          change: 4, repair: { light: 0.5, mid: 1.0, heavy: 1.5 }, paint: 1.0 },
    { id: 'havuz',          name: 'HAVUZ SACI',                 change: 3, repair: { light: 0.5, mid: 1.0, heavy: 1.5 }, paint: 0.5 },
    { id: 'sag_sase_arka',  name: 'SAĞ ŞASE ARKA',              change: 3, repair: { light: 1.0, mid: 1.5, heavy: 2.0 }, paint: 0.5 },
    { id: 'sol_sase_arka',  name: 'SOL ŞASE ARKA',              change: 3, repair: { light: 1.0, mid: 1.5, heavy: 2.0 }, paint: 0.5 },
    { id: 'travers',        name: 'MOTOR TRAVERSİ / DİNGİL',    change: 1, repair: { light: 1.0, mid: 1.5, heavy: 2.0 }, paint: 0   },
    { id: 'yolcu_airbag',   name: 'YOLCU HAVA YASTIĞI',         change: 2, repair: null, paint: 0 },
    { id: 'surucu_airbag',  name: 'SÜRÜCÜ HAVA YASTIĞI',        change: 2, repair: null, paint: 0 },
    { id: 'sag_yan_airbag', name: 'SAĞ YAN HAVA YASTIĞI',       change: 2, repair: null, paint: 0 },
    { id: 'sol_yan_airbag', name: 'SOL YAN HAVA YASTIĞI',       change: 2, repair: null, paint: 0 }
  ];

  // Public API (diğer gruplar sonra eklenecek — yapı korundu)
  const TABLES = {
    VEHICLE_GROUPS,
    PRICE_RANGES, PRICE_COEFF,
    KM_RANGES, KM_COEFF,
    HOUR_RANGES, HOUR_COEFF,
    GENERAL_COEFF,
    MOTORCYCLE_MULTIPLIER,
    PARTS: { A: PARTS_A }
  };

  root.DKTables = TABLES;
})(window);
