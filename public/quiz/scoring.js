// ZENOO quiz scoring. Pure functions, no DOM. Numbers mirror §7 of docs/2026-10-29-zenoo-quiz-design.md.
(function (root) {
  const CONFIG = {
    // Typical values when the parent skips the label (§7.2): dry-matter %, freshness tier,
    // and energy as fed (kcal per 100 g). Calibrate against real EU labels before launch.
    typical: {
      dry:         { p: 28, f: 16, c: 45, tier: 1, kcal100: 370 },
      wet:         { p: 40, f: 25, c: 25, tier: 2, kcal100: 95 },
      fresh:       { p: 45, f: 20, c: 25, tier: 3, kcal100: 130 },
      home:        { p: 40, f: 20, c: 30, tier: 3, kcal100: 140 },
      freezedried: { p: 45, f: 35, c: 8,  tier: 4, kcal100: 450 },
      raw:         { p: 50, f: 35, c: 5,  tier: 4, kcal100: 160 },
    },
    // kcal per kg^0.75 by activity level 1–5 (NRC-style, §7.5)
    k: { 1: 95, 2: 110, 3: 130, 4: 160, 5: 200 },
    neuteredFactor: 0.9,
    seniorAge: { toy: 10, small: 10, medium: 8, large: 7, giant: 6 },
    // Share of the daily energy target that treats are assumed to add
    treatShare: { none: 0, few: 0.05, several: 0.1, lots: 0.2 },
    // Points per sub-score (total 100)
    weights: { protein: 18, carbs: 18, freshness: 14, body: 16, activity: 14, signals: 10, routine: 10 },
    bands: [ [80, 'great'], [60, 'good'], [40, 'look'], [0, 'wins'] ],
  };

  function sizeClass(kg) {
    if (kg < 5) return 'toy';
    if (kg < 10) return 'small';
    if (kg < 25) return 'medium';
    if (kg < 45) return 'large';
    return 'giant';
  }

  function lifeStage(ageYears, size) {
    if (ageYears < 1) return 'puppy';
    return ageYears >= CONFIG.seniorAge[size] ? 'senior' : 'adult';
  }

  // Label maths: carbs by difference, then dry matter so wet and dry compare fairly.
  function fromLabel({ p, f, fi, a, m }) {
    const carbsAsFed = Math.max(0, 100 - p - f - fi - a - m);
    const dm = 100 - m;
    const toDm = (x) => (dm > 0 ? (x / dm) * 100 : 0);
    return {
      carbsAsFed,
      pDm: toDm(p), fDm: toDm(f), cDm: toDm(carbsAsFed), fiDm: toDm(fi),
      kcalPer100g: 3.5 * p + 8.5 * f + 3.5 * carbsAsFed, // modified Atwater
    };
  }

  function idealWeight(kg, bcs) {
    return kg * (100 / (100 + 10 * (bcs - 5)));
  }

  function activityLevel(minutesBand, type) {
    return Math.min(5, minutesBand + (type === 'sport' ? 1 : 0));
  }

  function dailyKcal({ weightKg, bcs, level, neutered, stage, ageYears }) {
    const iw = idealWeight(weightKg, bcs);
    if (stage === 'puppy') {
      const rer = 70 * Math.pow(weightKg, 0.75);
      return { kcal: rer * (ageYears < 0.34 ? 3 : 2), idealKg: weightKg };
    }
    let lvl = level;
    if (bcs >= 7) lvl = Math.max(1, lvl - 1); // weight-loss goal
    if (stage === 'senior') lvl = Math.max(1, lvl - 1);
    let kcal = CONFIG.k[lvl] * Math.pow(iw, 0.75);
    if (neutered) kcal *= CONFIG.neuteredFactor;
    return { kcal, idealKg: iw };
  }

  // Puppies and seniors get a recommendation one level lower.
  function activityScore(breedEnergy, stage, level) {
    const recommended = stage === 'adult' ? breedEnergy : Math.max(1, breedEnergy - 1);
    const gap = recommended - level;
    return { score: gap <= 0 ? 15 : gap === 1 ? 10 : gap === 2 ? 6 : 3, recommended };
  }

  // feeds: [{ type, grams|null }]; mainKcal100 overrides the typical density for the main food (from its label).
  // Returns null when any amount is unknown.
  function intake(feeds, mainType, mainKcal100, treats, targetKcal) {
    if (!feeds.length || feeds.some((x) => x.grams == null)) return null;
    const perFood = feeds.map((x) => {
      const k100 = x.type === mainType && mainKcal100 ? mainKcal100 : CONFIG.typical[x.type].kcal100;
      return { ...x, kcal100: k100, kcal: (x.grams * k100) / 100 };
    });
    const foodKcal = perFood.reduce((s, x) => s + x.kcal, 0);
    const treatKcal = targetKcal * CONFIG.treatShare[treats];
    return { perFood, foodKcal, treatKcal, total: foodKcal + treatKcal, ratio: (foodKcal + treatKcal) / targetKcal };
  }

  // Portions & routine, raw 0–10: portion fit 6, meal pattern 2, treats 2.
  function routineScore({ ratio, meals, treats, stage, bcs }) {
    let portion = 3; // unknown amounts: neutral
    if (ratio != null) {
      const off = Math.abs(ratio - 1);
      portion = off <= 0.1 ? 6 : off <= 0.2 ? 4 : off <= 0.3 ? 2 : 0;
    }
    let mealPts;
    if (stage === 'puppy') mealPts = meals === '3' ? 2 : meals === '2' ? 1 : 0;
    else mealPts = meals === '2' || meals === '3' ? 2 : meals === '1' ? 1 : bcs <= 5 ? 1 : 0; // 'free' = bowl always full
    const treatPts = { none: 2, few: 2, several: 1, lots: 0 }[treats] ?? 1;
    return { score: portion + mealPts + treatPts, portion, mealPts, treatPts };
  }

  const tiersFor = (types) => types.map((t) => CONFIG.typical[t].tier);
  const W = CONFIG.weights;
  const scale = (raw, rawMax, key) => ({ score: Math.round((raw / rawMax) * W[key]), max: W[key] });

  function subScores(a) {
    const prot = a.diet.pDm >= 35 ? 20 : a.diet.pDm >= 30 ? 16 : a.diet.pDm >= 25 ? 10 : 5;
    const c = a.diet.cDm;
    const carbs = c <= 15 ? 20 : c <= 25 ? 15 : c <= 35 ? 10 : c <= 45 ? 6 : 3;

    const mainTier = CONFIG.typical[a.foodMain].tier;
    let fresh = { 4: 15, 3: 12, 2: 8, 1: 5 }[mainTier];
    if (Math.max(...tiersFor(a.foodTypes)) > mainTier) fresh = Math.min(15, fresh + 2);

    const body = { 5: 20, 4: 15, 6: 15, 3: 10, 7: 10, 2: 5, 8: 5 }[a.bcs] ?? 5;
    const act = activityScore(a.breedEnergy, a.stage, a.level);

    let signals = 10;
    if (a.appetite === 'picky') signals -= 3;
    if (a.appetite === 'verypicky') signals -= 5;
    signals = Math.max(0, signals - a.signs.length);

    const routine = routineScore({ ratio: a.ratio ?? null, meals: a.meals, treats: a.treats, stage: a.stage, bcs: a.bcs });

    return {
      protein: scale(prot, 20, 'protein'),
      carbs: scale(carbs, 20, 'carbs'),
      freshness: scale(fresh, 15, 'freshness'),
      body: scale(body, 20, 'body'),
      activity: { ...scale(act.score, 15, 'activity'), recommended: act.recommended },
      signals: scale(signals, 10, 'signals'),
      routine: { ...scale(routine.score, 10, 'routine'), parts: routine },
    };
  }

  function band(total) {
    return CONFIG.bands.find(([min]) => total >= min)[1];
  }

  function nutrientTargets(kcal, stage) {
    if (stage === 'puppy') return null;
    const pLow = stage === 'senior' ? 0.35 : 0.30;
    return {
      proteinG: [kcal * pLow / 3.5, kcal * 0.45 / 3.5],
      fatG: [kcal * 0.35 / 8.5, kcal * 0.55 / 8.5],
    };
  }

  // a: normalised answers (see app.js answers()); a.feeds = [{ type, grams|null }]
  function evaluate(a) {
    const energy = dailyKcal(a);
    const eat = intake(a.feeds || [], a.foodMain, a.diet.kcalPer100g, a.treats || 'none', energy.kcal);
    const subs = subScores({ ...a, ratio: eat ? eat.ratio : null });
    const total = Object.values(subs).reduce((s, x) => s + x.score, 0);

    // Plan: scale today's amounts so food + treats land on the target.
    let planFeeds = null;
    if (eat && eat.foodKcal > 0) {
      const factor = (energy.kcal - eat.treatKcal) / eat.foodKcal;
      planFeeds = eat.perFood.map((x) => ({ type: x.type, grams: Math.round((x.grams * factor) / 5) * 5, now: x.grams }));
    }
    const grams = !eat && a.diet.kcalPer100g ? Math.round(((energy.kcal / a.diet.kcalPer100g) * 100) / 5) * 5 : null;

    // Portions lead when the amount clearly works against body shape: heavy and overfed, or thin and underfed.
    const portionsFirst = eat && ((a.bcs >= 7 && eat.ratio > 1.1) || (a.bcs <= 4 && eat.ratio < 0.9));
    const fixes = Object.entries(subs)
      .map(([key, v]) => ({ key, gain: v.max - v.score }))
      .filter((x) => x.gain > 0)
      .sort((x, y) => (portionsFirst ? (y.key === 'routine') - (x.key === 'routine') : 0) || y.gain - x.gain)
      .slice(0, 2)
      .map((x) => x.key);
    return {
      total, band: band(total), subs,
      kcal: Math.round(energy.kcal / 10) * 10,
      idealKg: Math.round(energy.idealKg * 10) / 10,
      intakeKcal: eat ? Math.round(eat.total / 10) * 10 : null,
      ratio: eat ? Math.round(eat.ratio * 100) / 100 : null,
      planFeeds, grams,
      targets: nutrientTargets(energy.kcal, a.stage),
      topFixes: fixes,
    };
  }

  const api = { CONFIG, sizeClass, lifeStage, fromLabel, idealWeight, activityLevel, activityScore, dailyKcal, intake, routineScore, subScores, band, nutrientTargets, evaluate };
  if (typeof module !== 'undefined') module.exports = api;
  else root.ZQScoring = api;
})(this);
