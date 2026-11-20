#!/usr/bin/env node
// Builds config/quiz_codes.json from the quiz's content files: the score band titles and one
// "what to do first" line per sub-score, in every language. The site uses it to show a dog's stored
// result in the interface language when the quiz was taken in another one (test report N6).
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
  out[l] = {
    bands: Object.fromEntries(Object.entries(C.bands).map(([k, b]) => [k, b.title])),
    fixes,
  };
}
fs.writeFileSync(path.join(root, 'config/quiz_codes.json'), JSON.stringify(out, null, 1) + '\n');
console.log('config/quiz_codes.json written');
