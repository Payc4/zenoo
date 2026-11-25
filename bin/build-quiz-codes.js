#!/usr/bin/env node
// Builds config/quiz_codes.json from the quiz's content files, in every language: the score band
// titles, the "what to do first" lines (one per sub-score, plus every variant), and the plan lines.
// The site uses it to show a stored result in another language (test report N6) and to write the
// texts again when the score is worked out anew after a weight or body-shape change (src/Quiz).
// Run after changing public/quiz/content.*.js:  node bin/build-quiz-codes.js
const fs = require('fs');
const path = require('path');
const root = path.join(__dirname, '..');
const out = {};
for (const l of ['en', 'lt', 'uk', 'pl']) {
  global.window = {};
  delete require.cache[require.resolve(path.join(root, `public/quiz/content.${l}.js`))];
  require(path.join(root, `public/quiz/content.${l}.js`));
  const C = global.window.ZQ_CONTENT;
  const fixes = {};
  for (const [k, copy] of Object.entries(C.subCopy)) {
    fixes[k] = copy.fix || copy.fixUnder || copy.fixOver || copy.fixMeals || '';
  }
  const variants = {};
  for (const [k, copy] of Object.entries(C.subCopy)) {
    variants[k] = Object.fromEntries(Object.entries(copy).filter(([v]) => /^fix/.test(v)));
  }
  const P = C.ui.plan;
  out[l] = {
    bands: Object.fromEntries(Object.entries(C.bands).map(([k, b]) => [k, b.title])),
    fixes,
    variants,
    keepGoing: C.ui.result.keepGoing,
    decimalSep: C.ui.decimalSep,
    foods: Object.fromEntries(C.foods.map((f) => [f.v, f.label])),
    plan: {
      emailFood: P.emailFood, emailFoodJoin: P.emailFoodJoin, gramsText: P.gramsText, emailNoAmount: P.emailNoAmount,
      keep: P.keep, keepWeight: P.keepWeight, aimWeight: P.aimWeight, puppyGrowth: P.puppyGrowth, gramsRange: P.gramsRange,
      mealsPuppy: P.mealsPuppy, mealsAdult: P.mealsAdult, treatsCounted: P.treatsCounted,
    },
  };
}
fs.writeFileSync(path.join(root, 'config/quiz_codes.json'), JSON.stringify(out, null, 1) + '\n');
console.log('config/quiz_codes.json written');
