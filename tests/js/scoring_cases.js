// Sample dogs for tests/Unit/ScoringParityTest.php: scores each with public/quiz/scoring.js and
// prints the inputs and results as JSON, so the PHP copy (src/Quiz/Scoring.php) can be compared.
// Usage: node tests/js/scoring_cases.js <path to scoring.js> [count]
const S = require(require('path').resolve(process.argv[2]));
const count = +(process.argv[3] || 4000);
let seed = 20261124;
const rnd = () => ((seed = (seed * 1103515245 + 12345) % 2147483648) / 2147483648);
const pick = (xs) => xs[Math.floor(rnd() * xs.length)];
const types = Object.keys(S.CONFIG.typical);
const out = [];
for (let i = 0; i < count; i++) {
  const nFoods = 1 + Math.floor(rnd() * 3);
  const foods = [...new Set(Array.from({ length: nFoods }, () => pick(types)))];
  const foodMain = pick(foods);
  const label = rnd() < 0.3;
  const t = S.CONFIG.typical[foodMain];
  const diet = label
    ? (() => { const d = S.fromLabel({ p: 18 + rnd() * 20, f: 8 + rnd() * 14, fi: 1 + rnd() * 4, a: 4 + rnd() * 5, m: pick([8, 10, 70, 78]) }); return { pDm: d.pDm, fDm: d.fDm, cDm: d.cDm, kcalPer100g: d.kcalPer100g, fromLabel: true }; })()
    : { pDm: t.p, fDm: t.f, cDm: t.c, kcalPer100g: null, fromLabel: false };
  const stage = pick(['puppy', 'adult', 'adult', 'senior']);
  const weightKg = Math.round((stage === 'puppy' ? 0.5 + rnd() * 25 : 2 + rnd() * 60) * 10) / 10;
  const a = {
    diet, foodMain, foodTypes: foods, bcs: 1 + Math.floor(rnd() * 9), breedEnergy: 1 + Math.floor(rnd() * 5),
    level: 1 + Math.floor(rnd() * 5), stage, appetite: pick(['eats', 'good', 'picky', 'verypicky']),
    signs: Array.from({ length: Math.floor(rnd() * 5) }, (_, j) => 's' + j),
    weightKg, neutered: rnd() < 0.6, ageYears: stage === 'puppy' ? Math.round(rnd() * 10) / 10 + 0.1 : 1 + Math.round(rnd() * 140) / 10,
    adultKg: stage === 'puppy' ? pick([null, 4, 8, 18, 33, 55, weightKg * 2]) : null,
    feeds: foods.map((type) => ({ type, grams: rnd() < 0.1 ? null : Math.round(10 + rnd() * 600) })),
    meals: pick(['1', '2', '3', 'free']), treats: pick(['none', 'few', 'several', 'lots']),
  };
  out.push({ a, r: S.evaluate(a) });
}
process.stdout.write(JSON.stringify(out));
