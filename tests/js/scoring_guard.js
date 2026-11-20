// Every body shape × weight × intake × age: the plan never goes against body shape (F2), changes by
// at most about 20% (more goes to the vet), and puppies get no target weight (F3). Prints problems.
const S = require(process.argv[2]);
const base = { diet: { pDm: 28, fDm: 16, cDm: 45, kcalPer100g: null }, foodMain: 'dry', foodTypes: ['dry'], breedEnergy: 3, appetite: 'good', signs: [], meals: '2' };
const problems = [];
let n = 0;
for (const bcs of [1, 2, 3, 4, 5, 6, 7, 8, 9]) for (const w of [0.6, 3, 10, 30, 60]) for (const g of [20, 80, 150, 300, 500, 800, 1200])
  for (const age of [0.2, 0.6, 3, 12]) for (const level of [1, 3, 5]) for (const treats of ['none', 'lots']) for (const neutered of [true, false]) {
    const stage = S.lifeStage(age, S.sizeClass(w));
    const r = S.evaluate({ ...base, bcs, level, treats, neutered, weightKg: w, ageYears: age, stage, adultKg: w * 3, feeds: [{ type: 'dry', grams: g }] });
    n++;
    const plan = r.planFeeds[0].grams;
    const tol = g < 100 ? 0.5 : 2.5; // rounding
    if (bcs >= 6 && plan > g + tol) problems.push(['more for a heavy dog', bcs, w, g, plan]);
    if (bcs <= 4 && plan < g - tol) problems.push(['less for a thin dog', bcs, w, g, plan]);
    if (Math.abs(plan - g) > g * 0.2 + tol) problems.push(['change over 20%', bcs, w, g, plan]);
    if (stage === 'puppy' && r.idealKg !== null) problems.push(['puppy target weight', w, age]);
  }
console.log(JSON.stringify({ cases: n, problems: problems.slice(0, 5), count: problems.length }));
