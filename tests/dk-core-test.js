/* Değer Kaybı Core — Node test
 * Çalıştır: node tests/dk-core-test.js
 */
const path = require('path');
const fs = require('fs');

// window shim
global.window = global;

// Modülleri yükle
function load(p) {
  const code = fs.readFileSync(path.resolve(__dirname, '..', p), 'utf8');
  eval(code);
}
load('js/widgets/deger-kaybi-tables.js');
load('js/widgets/deger-kaybi-core.js');

const tests = [
  {
    name: 'Boş parça, düşük hasar',
    input: { group: 'A', price: 200000, damage: 30000, km: 50000, isCommercial: false, sbmCount: 0, partSelections: [] },
    // C7=0.80, F9=0.90, J3=(0+1.5)/100=0.015, total=200000*0.8*0.9*1*0.015 = 2160
    expectTotal: 2160
  },
  {
    name: 'Tek parça değişen + boya, 1 SBM hasarı',
    input: {
      group: 'A', price: 500000, damage: 80000, km: 30000, isCommercial: false, sbmCount: 1,
      partSelections: [
        { partId: 'kaput', mode: 'change' },     // change=1
        { partId: 'bagaj', mode: 'paint_full' }  // paint=1
      ]
    },
    // C7=0.95, F9=0.95, changeSum=1, paintSum=1, partsTotal=2, damageRatio=1.6
    // J3 = (2+1.6)/100 = 0.036, J7=-0.03
    // total = 500000*0.95*0.95*0.97*0.036 = 15757.2
    expectTotal: 15757
  },
  {
    name: 'Ticari + 3 SBM hasarı + ağır onarım',
    input: {
      group: 'A', price: 350000, damage: 50000, km: 120000, isCommercial: true, sbmCount: 3,
      partSelections: [
        { partId: 'sag_on_camur', mode: 'repair_heavy' }, // repair_heavy=1
        { partId: 'kaput', mode: 'paint_full' }           // paint=1
      ]
    },
    // C7=0.85 (350000-399999), F9=0.85 (100000-149999), J5=-0.05, J7=-0.09 (3*-0.03)
    // partsTotal=2, damageRatio=50000/350000*100*0.1 = 1.42857..., J3=(2+1.42857)/100=0.0342857
    // (1+(-0.05+-0.09+0)) = 0.86
    // total = 350000*0.85*0.85*0.86*0.0342857
    //       = 350000*0.85=297500, *0.85=252875, *0.86=217472.5, *0.0342857 = 7456.45
    expectTotal: 7456
  },
  {
    name: 'Düşük KM bonusu (km<=1000)',
    input: { group: 'A', price: 600000, damage: 25000, km: 800, isCommercial: false, sbmCount: 0, partSelections: [] },
    // C7=0.95 (500000-749999), F9=1.00 (0-19999), J9=+0.05
    // J3 = (0 + 25000/600000*100*0.1)/100 = (0.41667)/100 = 0.0041667
    // total = 600000*0.95*1.00*1.05*0.0041667 = 2493.75
    expectTotal: 2494
  },
  {
    name: 'V1 kapsamı dışı grup (B) → hata döner',
    input: { group: 'B', price: 200000, damage: 30000, km: 50000, isCommercial: false, sbmCount: 0, partSelections: [] },
    expectError: true
  }
];

let pass = 0, fail = 0;
console.log('\n=== Değer Kaybı Core Test ===\n');

for (const t of tests) {
  const r = DKCore.calculate(t.input);
  if (t.expectError) {
    if (!r.ok) { console.log(`✓ ${t.name}\n   Beklenen hata: "${r.error}"`); pass++; }
    else { console.log(`❌ ${t.name}\n   Hata bekleniyordu, ${r.total} ₺ döndü`); fail++; }
    continue;
  }
  if (!r.ok) {
    console.log(`❌ ${t.name}\n   HATA: ${r.error}`);
    fail++;
    continue;
  }
  const diff = Math.abs(r.total - t.expectTotal);
  const tolerance = Math.max(2, t.expectTotal * 0.001); // ±%0.1 veya ±2 ₺
  if (diff <= tolerance) {
    console.log(`✓ ${t.name}\n   Beklenen: ${t.expectTotal} ₺, Sonuç: ${r.total} ₺ (Δ=${diff})`);
    pass++;
  } else {
    console.log(`❌ ${t.name}\n   Beklenen: ${t.expectTotal} ₺, Sonuç: ${r.total} ₺ (Δ=${diff})`);
    console.log('   Breakdown:', JSON.stringify(r.breakdown, null, 2));
    fail++;
  }
}

console.log(`\n=== ${pass} GEÇTİ, ${fail} BAŞARISIZ ===\n`);
process.exit(fail > 0 ? 1 : 0);
