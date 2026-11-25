// ZENOO "Is your dog eating properly?" quiz — prototype UI.
(function () {
  const C = window.ZQ_CONTENT;
  const S = window.ZQScoring;
  const CFG = window.ZQ_CONFIG;
  const app = document.getElementById('zq-app');
  const bar = document.getElementById('zq-progress');
  const STORE_KEY = 'zq-proto-v1';

  const blank = () => ({ foods: [], signs: [], label: {}, amounts: {} });
  const params = new URLSearchParams(location.search);
  // Site only: "+ Add another dog" (?new=1) starts a fresh quiz for a new dog.
  const fresh = params.has('new');
  // Site only: "Retake the quiz for Max" (?dog=<id>): Max's own saved answers, saved to Max.
  const retake = CFG.retake || null;
  // Site only: from the chat's "New chat" (?then=chat): open the new pet's chat once saved.
  const thenChat = params.get('then') === 'chat';
  // The browser keeps the quiz between visits, but never the email or the parent's name (F7), and a
  // member's quiz is only for that member: signed out, or someone else signed in, it's dropped.
  const owner = CFG.user ? CFG.user.id : null;
  let stored = load();
  if (stored && stored._owner && stored._owner !== owner) { stored = null; clearStore(); }
  let a = retake ? { ...blank(), ...(retake.answers || {}), name: retake.name, _dogId: retake.id, _step: 0 }
    : fresh ? { ...blank(), _new: true }
      : { ...blank(), ...(stored || {}) };
  delete a._rid; if (retake || fresh) delete a._saved;
  if (retake || fresh) history.replaceState(null, '', location.pathname);
  // Site only: a logged-in pet parent doesn't have to type their name and email again.
  const withUser = () => { if (CFG.user) { a.parentName = a.parentName || CFG.user.firstName || ''; a.email = a.email || CFG.user.email || ''; } };
  withUser();
  let step = a._step || 0;
  if (stored && stored._rid && !retake && !fresh) a._rid = stored._rid;
  let keep = true; // false once the result is saved to the account: nothing left in the browser

  function load() { try { return JSON.parse(localStorage.getItem(STORE_KEY)); } catch (e) { return null; } }
  function clearStore() { try { localStorage.removeItem(STORE_KEY); } catch (e) { /* blocked */ } }
  function save() {
    if (!keep) return;
    const { email, parentName, ...rest } = a;
    try { localStorage.setItem(STORE_KEY, JSON.stringify({ ...rest, _step: step, _owner: owner })); } catch (e) { /* storage blocked: quiz still works */ }
  }
  // The quiz's own answers sent with the result, so a retake can start from them.
  const answersToSave = () => { const { email, parentName, marketing, _step, _saved, _owner, _dogId, _new, _rid, labelScanTriesLeft, ...rest } = a; return rest; };

  const U = C.ui; // all screen copy lives in content.<locale>.js
  const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const name = () => esc(a.name || U.yourDog);
  // English possessive for {poss}; other languages phrase their copy so they don't need it.
  const poss = () => { const n = a.name || U.yourDog; return esc(/s$/i.test(n) ? n + '’' : n + '’s'); };
  const fill = (t, vars) => t.replace(/\{(\w+)\}/g, (_, k) => (vars[k] ?? ''));
  // fill() with the dog's name ready: {name}, {NAME}, {poss}, {POSS}
  const tx = (t, vars) => fill(t, { name: name(), NAME: name().toUpperCase(), poss: poss(), POSS: poss().toUpperCase(), ...vars });
  const listJoin = (xs) => (xs.length < 2 ? xs.join('') : xs.slice(0, -1).join(', ') + U.and + xs[xs.length - 1]);
  const num = (v) => (v === '' || v == null || isNaN(+v) ? null : +v);
  const dec = (n) => String(n).replace('.', U.decimalSep); // decimal separator for display
  const plurals = new Intl.PluralRules(CFG.locale || 'en');
  const plural = (n, forms) => fill(forms[plurals.select(n)] || forms.other, { n });
  const sortBreeds = (list) => list.sort((x, y) => x.name.localeCompare(y.name, CFG.locale || undefined));
  // Search ignores case, accents and the kind of apostrophe, so "cihua" finds "Čihuahua" and "тер'єр" finds "тер’єр".
  const fold = (s) => s.toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/[’ʼ`]/g, "'");
  const breedList = (q) => sortBreeds(C.breeds.filter((b) => fold(b.name).includes(fold(q))));
  const breedOptions = (q) => opt('breedId', 'mixed', U.breed.mixed, U.breed.mixedHint) + breedList(q).map((b) => opt('breedId', b.id, b.name)).join('');

  // ---------- derived data ----------
  function breed() {
    if (a.breedId === 'mixed') {
      const t = C.mixedTypes.find((x) => x.id === a.mixedType) || C.mixedTypes[4];
      return { name: U.mixedBreedName, energy: t.energy, kg: null, mixed: true };
    }
    return C.breeds.find((b) => b.id === a.breedId);
  }
  function ageYears() { return (num(a.ageY) || 0) + (num(a.ageM) || 0) / 12; }
  // Size from the breed's adult weight (or the mixed size picked), so the life stage is the same
  // on every screen and a puppy's size isn't taken from its weight today. Weight only as a fallback.
  function size() {
    if (a.breedId === 'mixed') return a.mixedSize || (a.weight && ageYears() >= 1 ? S.sizeClass(+a.weight) : 'medium');
    const b = breed(); if (b && b.kg) return S.sizeClass((b.kg[0] + b.kg[1]) / 2);
    return a.weight ? S.sizeClass(+a.weight) : 'medium';
  }
  // Expected adult weight, for a puppy's growth energy.
  function adultKg() {
    const b = breed();
    return b && b.kg ? (b.kg[0] + b.kg[1]) / 2 : S.CONFIG.adultKg[size()];
  }
  function stage() { return S.lifeStage(ageYears(), size()); }
  function diet() {
    if (!a.labelSkipped && a.label && ['p', 'f', 'fi', 'a', 'm'].every((k) => num(a.label[k]) != null)) {
      const L = Object.fromEntries(Object.entries(a.label).map(([k, v]) => [k, +v]));
      return { ...S.fromLabel(L), fromLabel: true };
    }
    const t = S.CONFIG.typical[a.foodMain || 'dry'];
    return { pDm: t.p, fDm: t.f, cDm: t.c, kcalPer100g: null, fromLabel: false };
  }
  function answers() {
    return {
      diet: diet(), foodMain: a.foodMain, foodTypes: a.foods, bcs: a.bcs, breedEnergy: breed().energy,
      level: S.activityLevel(a.actBand, a.actType), stage: stage(), appetite: a.appetite, signs: a.signs,
      weightKg: +a.weight, neutered: a.neutered === 'yes', ageYears: ageYears(), adultKg: adultKg(),
      feeds: a.foods.map((t) => ({ type: t, grams: gramsFor(t) })), meals: a.meals, treats: a.treats,
    };
  }
  function amount(t) { return (a.amounts[t] = a.amounts[t] || { v: '', u: 'g', ns: false }); }
  function gramsFor(t) {
    const x = amount(t);
    if (x.ns || num(x.v) == null) return null;
    const unit = C.units[t].find((u) => u[0] === x.u) || C.units[t][0];
    return num(x.v) * unit[2];
  }
  const amountsValid = () => a.foods.every((t) => { const x = amount(t); return x.ns || num(x.v) > 0; });

  // ---------- screens ----------
  // Shorter quiz (test report proposal 4): no intro screen (it's on the name screen), no info
  // screens (each fact sits under the next question: fact), and one tap per answer on
  // single-choice screens (auto: moves on once these answers are set; no Next button).
  // part: the section shown above the question (0 about the dog, 1 activity, 2 food, 3 health);
  // why: the reason shown when Next is tapped with something missing (U.why).
  const steps = [
    { id: 'name', part: 0, valid: () => (a.name || '').trim().length > 0 },
    { id: 'breed', part: 0, auto: ['breedId'], valid: () => !!a.breedId },
    { id: 'mixed', part: 0, auto: ['mixedSize', 'mixedType'], when: () => a.breedId === 'mixed', valid: () => a.mixedSize && a.mixedType },
    // Months alone are fine for a puppy (N4).
    { id: 'age', part: 0, why: 'age', fact: 'slideBreed', valid: () => { const y = ageYears(); return (num(a.ageY) != null || num(a.ageM) != null) && y > 0 && y < 25; } },
    { id: 'sex', part: 0, auto: ['sex', 'neutered'], fact: 'slideAge', valid: () => a.sex && a.neutered },
    { id: 'weight', part: 0, why: 'weight', valid: () => num(a.weight) >= 0.2 && num(a.weight) < 110 },
    { id: 'shape', part: 0, auto: ['bcs'], valid: () => !!a.bcs },
    { id: 'activity', part: 1, auto: ['actBand', 'actType'], fact: 'slideBody', valid: () => a.actBand && a.actType },
    { id: 'food', part: 2, fact: 'slideActivity', valid: () => a.foods.length > 0 },
    { id: 'foodMain', part: 2, auto: ['foodMain'], when: () => a.foods.length > 1, valid: () => a.foods.includes(a.foodMain) },
    { id: 'amounts', part: 2, why: 'amounts', valid: () => amountsValid() },
    { id: 'routine', part: 2, auto: ['meals', 'treats'], valid: () => a.meals && a.treats },
    { id: 'label', part: 2, why: 'label', valid: () => labelValid() },
    { id: 'appetite', part: 3, auto: ['appetite'], fact: 'slideBowl', valid: () => !!a.appetite },
    { id: 'signs', part: 3, valid: () => true },
    { id: 'gate', why: 'gate', fact: 'slideSigns', factWhen: () => a.signs.length > 0, when: () => !CFG.user, valid: () => (a.parentName || '').trim() && /^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(a.email || '') },
    { id: 'result' },
  ];
  const active = () => steps.filter((s) => !s.when || s.when());

  function labelValid() {
    const v = ['p', 'f', 'fi', 'a', 'm'].map((k) => num(a.label[k]));
    if (v.some((x) => x == null || x < 0)) return false;
    return v.reduce((s, x) => s + x, 0) <= 100 && v[4] < 90;
  }

  const opt = (key, v, label, hint, multi) => {
    const on = multi ? a[key].includes(v) : a[key] === v;
    return `<button type="button" class="zq-opt" aria-pressed="${on}" data-${multi ? 'multi' : 'set'}="${key}" data-val="${esc(v)}"><span>${label}${hint ? `<br><small>${hint}</small>` : ''}</span></button>`;
  };
  // file: name in assets/photos without extension
  const photo = (file, alt, round) => `<img class="zq-photo${round ? ' round' : ''}" src="assets/photos/${file}.jpg" alt="${esc(alt)}">`;
  const nav = (label = U.next) => `<div class="zq-nav"><button class="zq-back" data-action="back">${U.back}</button><button class="zq-btn" data-action="next">${label}</button></div>`;
  const qhead = (title, lead) => `<h2>${title}</h2>${lead ? `<p class="zq-lead">${lead}</p>` : ''}`;
  // An info card, shown under the next question instead of on a screen of its own.
  const slide = (tag, title, body, file, alt) => `<aside class="zq-fact">${file ? photo(file, alt, true) : ''}<div><span class="zq-tag">${tag}</span><h3>${title}</h3>${body}</div></aside>`;
  // Single-choice screens move on by themselves, so they only need Back.
  const backOnly = () => `<div class="zq-nav"><button class="zq-back" data-action="back">${U.back}</button><span></span></div>`;

  // Dog seen from above: snout, head, ears, four paws, body with chest (cw), waist (ww) and hip (hw) half-widths, tail.
  function shapeSvg(cw, ww, hw) {
    const L = (x) => 30 - x, Rt = (x) => 30 + x;
    const body = `M26,27 Q${L(cw)},28 ${L(cw)},38 Q${L(cw)},49 ${L(ww)},56 Q${L(hw)},62 ${L(hw)},72 Q${L(hw)},84 30,85 `
      + `Q${Rt(hw)},84 ${Rt(hw)},72 Q${Rt(hw)},62 ${Rt(ww)},56 Q${Rt(cw)},49 ${Rt(cw)},38 Q${Rt(cw)},28 34,27 Z`;
    const paw = (x, y) => `<rect x="${x - 2.5}" y="${y}" width="5" height="10" rx="2.5"/>`;
    return `<svg viewBox="0 0 60 100" aria-hidden="true"><g fill="currentColor">
      ${paw(L(cw) - 1, 31)}${paw(Rt(cw) + 1, 31)}${paw(L(hw) - 1, 70)}${paw(Rt(hw) + 1, 70)}
      <ellipse cx="30" cy="7" rx="4" ry="5"/><ellipse cx="30" cy="17" rx="8" ry="9"/>
      <ellipse cx="21.5" cy="18" rx="3.2" ry="6.5" transform="rotate(20 21.5 18)"/><ellipse cx="38.5" cy="18" rx="3.2" ry="6.5" transform="rotate(-20 38.5 18)"/>
      <path d="${body}"/></g>
      <path d="M30,84 Q34,91 31,98" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round"/></svg>`;
  }
  const SHAPES = [
    { bcs: 2, label: U.shapes[2], svg: shapeSvg(10, 3.5, 7) },
    { bcs: 4, label: U.shapes[4], svg: shapeSvg(11, 6, 8.5) },
    { bcs: 5, label: U.shapes[5], svg: shapeSvg(12, 8, 10) },
    { bcs: 7, label: U.shapes[7], svg: shapeSvg(13, 14, 12) },
    { bcs: 8, label: U.shapes[8], svg: shapeSvg(14, 18.5, 14.5) },
  ];

  const R = {
    // The first screen: what the check is, and the first question, in one.
    name: () => `${retake ? '' : `<div class="zq-intro">${photo('intro-hero', U.intro.alt)}<h1>${U.intro.title}</h1><p class="zq-lead">${U.intro.lead}</p></div>`}
      ${qhead(tx(U.name.title))}
      <input type="text" data-input="name" value="${esc(a.name)}" placeholder="${esc(U.name.placeholder)}" autocomplete="off" maxlength="30">
      <p class="zq-small" style="margin:8px 0 0">${U.intro.small}</p>${nav()}`,

    breed: () => `${qhead(tx(U.breed.title))}
        <input type="search" data-input="_breedQ" data-live="breed" value="${esc(a._breedQ)}" placeholder="${esc(U.breed.search)}" autocomplete="off">
        <div class="zq-breedlist" id="zq-breedlist">${breedOptions(a._breedQ || '')}</div>${backOnly()}`,

    mixed: () => `${qhead(tx(U.mixed.title))}
      <label class="zq-label">${U.mixed.size}</label>
      <div class="zq-options">${C.mixedSizes.map((s) => opt('mixedSize', s.id, s.label, s.hint)).join('')}</div>
      <label class="zq-label">${U.mixed.type}</label>
      <div class="zq-options">${C.mixedTypes.map((t) => opt('mixedType', t.id, t.label)).join('')}</div>${backOnly()}`,

    slideBreed: () => {
      const b = breed();
      return b.mixed
        ? slide(U.slideBreed.tag, U.slideBreed.mixedTitle, `<p>${C.mixedSlide[a.mixedSize]}</p>`, `mixed-${a.mixedSize}`, U.slideBreed.mixedAlt)
        : slide(U.slideBreed.tag, b.name.toUpperCase(), `<p>${b.slide}</p>`, `breed-${b.id}`, b.name);
    },

    age: () => `${qhead(tx(U.age.title))}
      <div class="zq-row">
        <div><label class="zq-label">${U.age.years}</label><input type="number" inputmode="numeric" min="0" max="25" data-input="ageY" value="${esc(a.ageY)}"></div>
        <div><label class="zq-label">${U.age.months}</label><input type="number" inputmode="numeric" min="0" max="11" data-input="ageM" value="${esc(a.ageM)}" placeholder="0"></div>
      </div>${nav()}`,

    slideAge: () => {
      // The pet parent's own breed (or mixed-size) photo, not a stock dog of another breed.
      const st = stage(); const copy = U.slideAge[st]; const b = breed();
      const file = b && !b.mixed ? `breed-${b.id}` : `mixed-${size()}`;
      return slide(U.slideAge.tag, copy.title, `<p>${tx(copy.text)}</p>`, file, b && !b.mixed ? b.name : copy.alt);
    },

    sex: () => `${qhead(tx(U.sex.title))}
      <label class="zq-label">${tx(U.sex.isA)}</label>
      <div class="zq-options two">${opt('sex', 'female', U.sex.girl)}${opt('sex', 'male', U.sex.boy)}</div>
      <label class="zq-label">${U.sex.neutered}</label>
      <div class="zq-options two">${opt('neutered', 'yes', U.sex.yes)}${opt('neutered', 'no', U.sex.no)}</div>
      <p class="zq-small">${U.sex.note}</p>${backOnly()}`,

    weight: () => {
      const b = breed(); const w = num(a.weight);
      const warn = b && b.kg && w && (w < b.kg[0] * 0.6 || w > b.kg[1] * 1.5)
        ? `<div class="zq-hint">${tx(U.weight.warn, { breed: esc(b.name), min: dec(b.kg[0]), max: dec(b.kg[1]) })}</div>` : '';
      return `${qhead(tx(U.weight.title), U.weight.lead)}
        <label class="zq-label">${U.weight.label}</label>
        <input type="number" inputmode="decimal" step="0.1" min="0" data-input="weight" data-live="weight" value="${esc(a.weight)}" placeholder="${esc(U.weight.placeholder)}">
        <div id="zq-weightwarn">${warn}</div>${nav()}`;
    },

    shape: () => `${qhead(tx(U.shape.title), U.shape.lead)}
      <div class="zq-shapes">${SHAPES.map((s) => `<button type="button" class="zq-shape" aria-pressed="${a.bcs === s.bcs}" data-set="bcs" data-val="${s.bcs}" data-num="1">${s.svg}${s.label}</button>`).join('')}</div>
${backOnly()}`,

    slideBody: () => {
      const iw = S.idealWeight(+a.weight, a.bcs);
      const small = ['toy', 'small'].includes(size());
      const body = stage() === 'puppy' ? tx(U.slideBody.puppy)
        : tx(a.bcs === 5 ? U.slideBody.ok : a.bcs > 5 ? (small ? U.slideBody.heavy : U.slideBody.heavyBig) : U.slideBody.thin, { ideal: kgText(iw) });
      return slide(U.slideBody.tag, U.slideBody.title, body, 'body-check', U.slideBody.alt);
    },

    activity: () => `${qhead(tx(U.activity.title))}
      <label class="zq-label">${U.activity.band}</label>
      <div class="zq-options">${C.activityBands.map((b) => opt('actBand', b.v, b.label)).join('')}</div>
      <label class="zq-label">${U.activity.type}</label>
      <div class="zq-options">${C.activityTypes.map((t) => opt('actType', t.v, t.label)).join('')}</div>${backOnly()}`,

    slideActivity: () => {
      const b = breed(); const act = S.activityScore(b.energy, stage(), S.activityLevel(a.actBand, a.actType));
      const need = C.activityNeed[act.recommended];
      const got = C.activityBands.find((x) => x.v === a.actBand).label.toLowerCase();
      const enough = act.score === 15; const T = U.slideActivity;
      return slide(T.tag, enough ? T.titleOk : T.titleMore,
        `<p>${tx(b.mixed ? T.textMixed : T.textBreed, { breed: esc(b.name), need, got })}</p><p>${enough ? T.ok : T.more}</p>`,
        'activity-generic', T.alt);
    },

    food: () => `${qhead(tx(U.food.title), U.food.lead)}
      <div class="zq-options">${C.foods.map((f) => opt('foods', f.v, f.label, f.hint, true)).join('')}</div>${nav()}`,

    foodMain: () => `${qhead(tx(U.foodMain.title))}
      <div class="zq-options">${C.foods.filter((f) => a.foods.includes(f.v)).map((f) => opt('foodMain', f.v, f.label)).join('')}</div>${backOnly()}`,

    amounts: () => {
      const rows = a.foods.map((t) => {
        const f = C.foods.find((x) => x.v === t); const x = amount(t);
        return `<div class="zq-amount">
          <label class="zq-label" for="amt-${t}">${f.label}</label>
          <div class="zq-amount-row">
            <input id="amt-${t}" type="number" inputmode="decimal" step="0.5" min="0" data-amount="${t}" data-field="v" value="${esc(x.v)}" ${x.ns ? 'disabled' : ''} placeholder="0">
            <select data-amount="${t}" data-field="u" ${x.ns ? 'disabled' : ''}>${C.units[t].map(([id, l]) => `<option value="${id}" ${x.u === id ? 'selected' : ''}>${l}</option>`).join('')}</select>
          </div>
          <label class="zq-check"><input type="checkbox" data-amount="${t}" data-field="ns" ${x.ns ? 'checked' : ''}><span>${U.amounts.notSure}</span></label>
        </div>`;
      }).join('');
      return `${qhead(tx(U.amounts.title), U.amounts.lead)}${rows}${nav()}`;
    },

    routine: () => `${qhead(tx(U.routine.title))}
      <label class="zq-label">${tx(U.routine.meals)}</label>
      <div class="zq-options">${C.meals.map((m) => opt('meals', m.v, m.label)).join('')}</div>
      <label class="zq-label">${U.routine.treats}</label>
      <div class="zq-options">${C.treats.map((t) => opt('treats', t.v, t.label)).join('')}</div>${backOnly()}`,

    label: () => {
      const T = U.label;
      // Typing first, the photo option second, skipping last (test report U11).
      return `${qhead(tx(T.title), T.lead)}
        <div class="zq-label-grid">${['p', 'f', 'fi', 'a', 'm'].map((k) => `<div><label>${T.fields[k]} %</label><input type="number" inputmode="decimal" step="0.1" min="0" max="100" data-input="label.${k}" value="${esc(a.label[k])}"></div>`).join('')}</div>
        <p class="zq-small">${T.moistureNote}</p>
        <div class="zq-nav"><button class="zq-back" data-action="back">${U.back}</button><button class="zq-btn" data-action="next">${T.submit}</button></div>
        <div style="margin-top:22px">${scanBox()}</div>
        <button class="zq-btn outline block" data-action="skipLabel" style="margin-top:18px">${T.skip}</button>
        <p class="zq-small" style="text-align:center;margin:8px 0 0">${tx(T.skipNote)}</p>`;
    },

    slideBowl: () => {
      const d = diet(); const T = U.slideBowl;
      const foodLabel = C.foods.find((f) => f.v === a.foodMain).label.toLowerCase();
      const food = { food: foodLabel, FOOD: foodLabel.toUpperCase() };
      const bars = [[T.bars.p, d.pDm, ''], [T.bars.f, d.fDm, ''], [T.bars.c, d.cDm, 'carbs']]
        .map(([l, v, cls]) => `<div class="zq-bar"><div class="zq-bar-top"><span>${l}</span><span>${Math.round(v)}%</span></div><div class="zq-bar-track ${cls}"><div style="width:${Math.min(100, v)}%"></div></div></div>`).join('');
      const intro = tx(d.fromLabel ? T.introLabel : T.introTypical, food);
      return `<aside class="zq-fact"><div><span class="zq-tag">${T.tag}</span>
        <h3>${tx(d.fromLabel ? T.titleLabel : T.titleTypical, food)}</h3>${intro}
        <div class="zq-bars">${bars}</div>
        <p>${T.outro}</p></div></aside>`;
    },

    appetite: () => `${qhead(tx(U.appetite.title))}
      <div class="zq-options">${C.appetite.map((x) => opt('appetite', x.v, x.label)).join('')}</div>${backOnly()}`,

    signs: () => `${qhead(tx(U.signs.title), U.signs.lead)}
      <div class="zq-options two">${C.signs.map((s) => opt('signs', s.v, s.label, '', true)).join('')}</div>${nav(a.signs.length ? U.next : U.signs.none)}`,

    slideSigns: () => {
      const top = a.signs.slice(0, 2);
      const body = top.map((s) => `<p><b>${C.signs.find((x) => x.v === s).label}.</b> ${C.signSlides[s]}</p>`).join('');
      return slide(U.slideSigns.tag, U.slideSigns.title, `${body}<p class="zq-small">${C.vetLine}</p>`, 'signs-generic', U.slideSigns.alt);
    },

    gate: () => {
      const T = U.gate;
      return `
      <div class="zq-slide"><div class="zq-slide-body">
        <span class="zq-tag">${tx(T.tag)}</span>
        <h2>${tx(T.title)}</h2>
        <p class="zq-lead">${tx(T.lead)}</p>
        <label class="zq-label">${T.firstName}</label>
        <input type="text" data-input="parentName" value="${esc(a.parentName)}" autocomplete="given-name">
        <label class="zq-label">${T.email}</label>
        <input type="email" data-input="email" value="${esc(a.email)}" autocomplete="email" inputmode="email">
        <label class="zq-optin"><input type="checkbox" data-check="marketing" ${a.marketing ? 'checked' : ''}> <span>${T.marketing}</span></label>
        <button class="zq-btn block" data-action="next" style="margin-top:16px">${T.submit}</button>
        <p class="zq-small" style="margin-top:12px">${tx(T.consent)} ${T.unsubscribe} <a href="${CFG.privacyUrl}" target="_blank" rel="noopener">${T.privacy}</a>.</p>
      </div></div>
      <div class="zq-nav"><button class="zq-back" data-action="back">${U.back}</button><span></span></div>`;
    },

    result: () => {
      const ans = answers(); const r = S.evaluate(ans);
      const { bandC, vars, text, mealsAdvice } = resultCopy(ans, r);
      const P = U.plan; const F = U.profile;
      const cards = Object.entries(r.subs).map(([k, v]) => {
        const full = v.score === v.max; const t = text(k);
        return `<div class="zq-card"><h3><span>${C.subLabels[k]}</span><span>${v.score}/${v.max}</span></h3>
          <div class="zq-bar-track"><div style="width:${(v.score / v.max) * 100}%"></div></div>
          <p style="margin-top:10px">${fill(t.why, vars)}</p>${full || !t.fix ? '' : `<div class="fix">${fill(t.fix, vars)}</div>`}</div>`;
      });
      const first = r.topFixes.map((k) => `<li>${fill(text(k).fix, vars)}</li>`).join('');
      const foodLabel = (t) => C.foods.find((f) => f.v === t).label;
      const feedRows = r.planFeeds
        ? r.planFeeds.map((x) => `<dt>${foodLabel(x.type)}</dt><dd>${fill(P.feedText, { grams: x.grams })}${r.keep ? '' : ` <span class="zq-small">${fill(P.feedNow, { now: S.roundGrams(x.now) })}</span>`}</dd>`).join('')
        : r.grams ? `<dt>${P.currentFood}</dt><dd>${fill(P.gramsText, { grams: r.grams })}</dd>` : '';
      const keepNote = r.keep ? `<p class="zq-plan-keep">${tx(P.keep[r.keep], { target: r.targetKcal, intake: r.intakeKcal })}</p>` : '';
      const vets = [];
      if (a.signs.length) vets.push(tx(U.vet.signs, { signs: vars.signs, vetLine: C.vetLine }));
      if (a.bcs <= 2 || a.bcs >= 8) vets.push(tx(U.vet.body));
      const plan = `<div class="zq-plan"><h2 style="font-size:20px">${tx(P.title)}</h2>
        <dl>
          <dt>${P.energy}</dt><dd>${fill(P.energyText, { kcal: r.kcal })}${ans.stage === 'puppy' ? P.puppyNote : ''}</dd>
          ${feedRows}
          ${keepNote ? `<dd class="zq-plan-keep-row">${keepNote}</dd>` : ''}
          <dt>${P.meals}</dt><dd>${mealsAdvice}${a.treats !== 'none' ? P.treatsCounted : ''}</dd>
          ${r.targets ? `<dt>${P.protein}</dt><dd>${gramsRange(r.targets.proteinG)}</dd>
          <dt>${P.fat}</dt><dd>${gramsRange(r.targets.fatG)}</dd>
          <dt>${P.carbs}</dt><dd>${P.carbsText}</dd>
          <dt>${P.fibre}</dt><dd>${P.fibreText}</dd>` : ''}
          <dt>${P.weight}</dt><dd>${planWeight(r)}</dd>
          <dt>${P.activity}</dt><dd>${vars.need[0].toUpperCase() + vars.need.slice(1)}</dd>
        </dl>
        <p class="zq-small" style="margin-top:12px">${tx(P.note)}</p></div>`;
      const zp = zenooPlan(r);
      const zenooCard = zp ? (() => {
        const Z = U.result.zenoo;
        return `<div class="zq-card zq-zenoo">${CFG.zenoo.image ? `<img src="${esc(CFG.zenoo.image)}" alt="" width="72" height="72">` : ''}
          <div><h3 style="margin:0 0 6px">${tx(Z.title)}</h3>
          <p style="margin:0 0 8px">${fill(Z.text, { product: `<a href="${esc(CFG.zenoo.url)}" target="_blank" rel="noopener">${esc(CFG.zenoo.title)}</a>`, option: Z.options[zp.option], grams: zp.grams, cost: dec(zp.cost.toFixed(2)) })}</p>
          <a class="zq-btn" href="${esc(zp.cartUrl)}" target="_blank" rel="noopener" data-track="plan_cart">${fill(Z.cta, { price: dec(zp.starterPrice.toFixed(2)) })}</a>
          ${CFG.zenoo.starterCode ? `<p class="zq-small" style="margin:8px 0 0">${fill(Z.code, { code: esc(CFG.zenoo.starterCode) })}</p>` : ''}
          <p class="zq-small" style="margin:8px 0 0">${Z.note}${CFG.user ? ` <a href="/dashboard/dog#zenoo-h">${Z.dashboard}</a>` : ''}</p></div></div>`;
      })() : '';
      const payload = klaviyoPayload(r);
      const b = breed(); const y = Math.floor(ageYears()); const m = Math.round((ageYears() - y) * 12);
      const age = [y ? plural(y, F.years) : '', m ? plural(m, F.months) : ''].filter(Boolean).join(' ') || F.underMonth;
      const female = a.sex === 'female'; const fixed = a.neutered === 'yes';
      const facts = [
        [F.breed, b.mixed ? fill(F.mixed, { size: C.mixedSizes.find((x) => x.id === size()).label.toLowerCase() }) : esc(b.name)],
        [F.age, `${age} · ${U.stages[stage()]}`],
        [F.weight, `${kgText(+a.weight)} kg`],
        [F.sex, `${female ? F.girl : F.boy} · ${female ? (fixed ? F.spayed : F.notSpayed) : (fixed ? F.neutered : F.notNeutered)}`],
      ];
      const profile = `<div class="zq-profile">${photo(b.mixed ? `mixed-${size()}` : `breed-${a.breedId}`, b.mixed ? U.slideBreed.mixedAlt : b.name)}
        <div class="zq-profile-body"><h1>${name().toUpperCase()}</h1>
          <dl>${facts.map(([k, v]) => `<div><dt>${k}</dt><dd>${v}</dd></div>`).join('')}</dl></div></div>`;
      const T = U.result;
      // Members don't stay here: the result is saved and the dashboard shows the new score (proposal 5).
      if (CFG.user) {
        return `<div class="zq-scorecard"><div class="zq-small">${tx(T.scoreTitle)}</div>
          <div class="zq-score-num">${r.total}<small>/100</small></div><h1>${bandC.title}</h1></div>
          ${CFG.accountSubmitUrl ? `<div class="zq-sent zq-account" id="zq-account">${accountBox()}</div>` : ''}`;
      }
      // The first screen (test report: shorter result): the score, then the one real next step,
      // confirming the email, then three things to do. The full plan and breakdown fold away.
      // The fixed button opens their webmail when we know it; else the ZENOO starter, if any.
      const domain = String(a.email || '').split('@')[1]?.toLowerCase() || '';
      const webmail = { 'gmail.com': 'https://mail.google.com/mail/u/0/#search/ZENOO', 'googlemail.com': 'https://mail.google.com/',
        'outlook.com': 'https://outlook.live.com/mail/', 'hotmail.com': 'https://outlook.live.com/mail/', 'live.com': 'https://outlook.live.com/mail/',
        'yahoo.com': 'https://mail.yahoo.com/', 'icloud.com': 'https://www.icloud.com/mail', 'me.com': 'https://www.icloud.com/mail',
        'gmx.com': 'https://www.gmx.com/', 'gmx.net': 'https://www.gmx.net/', 'wp.pl': 'https://poczta.wp.pl/', 'o2.pl': 'https://poczta.o2.pl/',
        'onet.pl': 'https://poczta.onet.pl/', 'interia.pl': 'https://poczta.interia.pl/', 'ukr.net': 'https://mail.ukr.net/', 'inbox.lt': 'https://www.inbox.lt/',
        'one.lt': 'https://www.one.lt/' }[domain];
      const main = webmail
        ? `<a class="zq-btn block" href="${esc(webmail)}" target="_blank" rel="noopener" data-track="open_email">${T.openEmail}</a>`
        : zp ? `<a class="zq-btn block" href="${esc(zp.cartUrl)}" target="_blank" rel="noopener" data-track="plan_cart">${fill(U.result.zenoo.cta, { price: dec(zp.starterPrice.toFixed(2)) })}</a>` : '';
      return `
        <div class="zq-scorecard"><div class="zq-small">${tx(T.scoreTitle)}</div>
          <div class="zq-score-num">${r.total}<small>/100</small></div>
          <h1>${bandC.title}</h1>
          ${r.capped ? `<p class="zq-small zq-capped">${tx(T.capped[r.capped], { max: r.total })}</p>` : ''}</div>
        ${CFG.accountSubmitUrl
          // One confirm card: the site sends the link that saves the result and the plan email follows.
          ? `<div class="zq-sent zq-account zq-confirm" id="zq-account">${accountBox()}</div>`
          : `<div class="zq-sent zq-confirm" id="zq-inbox"><h3>${T.inboxTitle}</h3>
          <p style="margin:0">${tx(T.inboxText, { email: `<b>${esc(a.email)}</b>` })}</p></div>`}
        <div class="zq-card zq-todo"><h3>${T.firstTitle}</h3><ol style="margin:0;padding-left:20px">${first || `<li>${T.keepGoing}</li>`}</ol></div>
        ${vets.map((v) => `<div class="zq-vet">${v}</div>`).join('')}
        ${zenooCard}
        <details class="zq-more"><summary>${T.more}</summary>
          <p>${fill(bandC.text, vars)}</p>
          ${plan}
          <h2 style="margin-top:20px">${T.breakdown}</h2>${cards.join('')}
          ${profile}
        </details>
        <p class="zq-quiet"><button class="zq-back" data-action="share">${T.share}</button> · <button class="zq-back" data-action="edit">${U.editAnswers}</button> · <button class="zq-back" data-action="restart">${T.restart}</button></p>
        ${main ? `<div class="zq-fixed">${main}</div>` : ''}
        ${CFG.debug ? `<details class="zq-demo"><summary>${CFG.sendToKlaviyo ? T.demoSent : T.demoUnsent}</summary><pre>${esc(JSON.stringify(payload, null, 2))}</pre></details>` : ''}`;
    },
  };

  // Plan lines shared by the result screen and the Klaviyo profile.
  const gramsRange = ([lo, hi]) => fill(U.plan.gramsRange, { min: Math.round(lo), max: Math.round(hi) });
  // Weights: two decimals below 2 kg, one above, no trailing zero.
  const kgText = (kg) => dec(String(S.roundKg(kg)));
  const planWeight = (r) => (r.idealKg == null
    ? tx(U.plan.puppyGrowth, { adult: kgText(r.adultKg || adultKg()) })
    : a.bcs === 5
      ? tx(U.plan.keepWeight, { kg: kgText(+a.weight) })
      : tx(U.plan.aimWeight, { kg: kgText(r.idealKg) }));

  // Copy shared by the result screen and the Klaviyo profile, so email #1 says what the screen said.
  function resultCopy(ans, r) {
    const b = breed(); const d = ans.diet;
    const bandC = C.bands[r.band];
    const vars = {
      name: name(), p: Math.round(d.pDm), c: Math.round(d.cDm), kcal: r.kcal,
      breed: b.mixed ? tx(U.vars.breedMixed) : esc(b.name),
      breeds: b.mixed ? tx(U.vars.breedMixed) : esc(b.name) + 's', need: C.activityNeed[r.subs.activity.recommended],
      poss: poss(), POSS: poss().toUpperCase(),
      got: C.activityBands.find((x) => x.v === a.actBand).label.toLowerCase(),
      signs: listJoin(a.signs.map((s) => C.signs.find((x) => x.v === s).label.toLowerCase())) || U.vars.noSigns,
    };
    vars.intake = r.intakeKcal; vars.target = r.targetKcal;
    vars.Breeds = vars.breeds.charAt(0).toUpperCase() + vars.breeds.slice(1);
    vars.ideal = r.idealKg == null ? '' : kgText(r.idealKg);
    const text = (k) => {
      const copy = C.subCopy[k]; const v = r.subs[k];
      if (k === 'routine') {
        const p = v.parts;
        if (r.ratio == null) return { why: copy.unknown, fix: copy.fixUnknown };
        if (p.portion < 6 && r.vetCheck) return { why: copy.vet, fix: copy.fixVet };
        if (p.portion < 6) return r.ratio > 1 ? { why: copy.over, fix: copy.fixOver } : { why: copy.under, fix: copy.fixUnder };
        if (p.mealPts < 2) {
          if (ans.stage === 'puppy') return { why: copy.puppy, fix: copy.fixPuppyMeals };
          return { why: a.meals === 'free' ? copy.free : copy.once, fix: copy.fixMeals };
        }
        if (p.treatPts < 2) return { why: copy.treats, fix: copy.fixTreats };
        return { why: copy.ok, fix: '' };
      }
      if (k === 'body' && ans.stage === 'puppy') return { why: v.score === v.max ? copy.puppyOk : copy.puppy, fix: copy.fixPuppy };
      if (k === 'body' && r.keep) return { why: a.bcs < 5 ? copy.thin : copy.why, fix: copy.fixKeep };
      const why = k === 'body' && a.bcs < 5 ? copy.thin : v.score === v.max ? copy.ok : copy.why;
      return { why, fix: copy.fix };
    };
    const mealsAdvice = ans.stage === 'puppy' ? U.plan.mealsPuppy : U.plan.mealsAdult;
    return { bandC, vars, text, mealsAdvice };
  }

  // Everything here lands on the Klaviyo profile, so email #1 (list-triggered) can show the plan.
  function klaviyoPayload(r) {
    const ans = answers(); const b = breed(); const d = ans.diet;
    const { bandC, vars, text, mealsAdvice } = resultCopy(ans, r);
    const foodLabel = (t) => C.foods.find((f) => f.v === t).label;
    const fixes = r.topFixes.map((k) => fill(text(k).fix, vars)).filter(Boolean);
    return {
      email: a.email, first_name: (a.parentName || '').trim(),
      consent_text_shown: CFG.user ? '' : tx(U.gate.consent) + ' [' + (a.marketing ? '✓' : ' ') + '] ' + U.gate.marketing,
      marketing: !CFG.user && !!a.marketing,
      properties: {
        quiz_dog_name: a.name, quiz_breed: b.mixed ? 'mixed' : b.name, quiz_breed_size: size(),
        quiz_breed_photo_url: CFG.emailPhotoBase + (b.mixed ? `mixed-${size()}` : `breed-${a.breedId}`) + '.jpg',
        quiz_age_years: Math.round(ageYears() * 10) / 10, quiz_life_stage: stage(), quiz_sex: a.sex, quiz_neutered: a.neutered === 'yes',
        quiz_weight_kg: +a.weight, quiz_bcs: a.bcs, quiz_ideal_weight_kg: r.idealKg, quiz_adult_weight_kg: ans.stage === 'puppy' ? S.roundKg(adultKg()) : null,
        quiz_plan_keep: r.keep, quiz_kcal_calculated: r.targetKcal,
        quiz_activity_band: S.activityLevel(a.actBand, a.actType), quiz_recommended_activity_band: r.subs.activity.recommended,
        quiz_food_main: a.foodMain, quiz_food_types: a.foods, quiz_label_given: d.fromLabel,
        ...(() => { const zp = zenooPlan(r); return zp ? { quiz_plan_product: CFG.zenoo.title, quiz_plan_option: zp.option, quiz_plan_grams: zp.grams, quiz_plan_cost_day: +zp.cost.toFixed(2), quiz_plan_cart_url: zp.cartUrl } : {}; })(),
        quiz_label_scanned: !!a.labelScan, quiz_food_brand: a.labelScan?.brand || null,
        quiz_food_product: a.labelScan?.product || null, quiz_food_format: a.labelScan?.format || null,
        quiz_carbs_dm: Math.round(d.cDm), quiz_protein_dm: Math.round(d.pDm), quiz_appetite: a.appetite, quiz_signs: a.signs,
        quiz_top_sign: a.signs[0] || null, quiz_bowl_score: r.total, quiz_score_band: r.band, quiz_score_capped: r.capped,
        quiz_sub_protein: r.subs.protein.score, quiz_sub_carbs: r.subs.carbs.score, quiz_sub_freshness: r.subs.freshness.score,
        quiz_sub_body: r.subs.body.score, quiz_sub_activity: r.subs.activity.score, quiz_sub_signals: r.subs.signals.score,
        quiz_sub_routine: r.subs.routine.score, quiz_meals_per_day: a.meals, quiz_treats: a.treats,
        quiz_intake_kcal: r.intakeKcal, quiz_portion_ratio: r.ratio,
        quiz_top_fix_1: r.topFixes[0] || null, quiz_top_fix_2: r.topFixes[1] || null, quiz_top_fix_3: r.topFixes[2] || null, quiz_kcal_target: r.kcal,
        // Ready-to-print lines for email #1
        quiz_score_title: bandC.title, quiz_score_text: fill(bandC.text, vars),
        quiz_fix_1_text: fixes[0] || U.result.keepGoing, quiz_fix_2_text: fixes[1] || '', quiz_fix_3_text: fixes[2] || '',
        quiz_plan_food: (r.planFeeds ? r.planFeeds.map((x) => fill(U.plan.emailFood, { food: foodLabel(x.type), grams: x.grams })).join(U.plan.emailFoodJoin)
          : r.grams ? fill(U.plan.gramsText, { grams: r.grams }) : U.plan.emailNoAmount)
          + (r.keep ? ' — ' + tx(U.plan.keep[r.keep], { target: r.targetKcal, intake: r.intakeKcal }) : ''),
        quiz_plan_meals: mealsAdvice + (a.treats !== 'none' ? U.plan.treatsCounted : ''),
        quiz_plan_protein_g: r.targets ? gramsRange(r.targets.proteinG) : null,
        quiz_plan_fat_g: r.targets ? gramsRange(r.targets.fatG) : null,
        quiz_plan_weight: planWeight(r),
        quiz_plan_activity: vars.need[0].toUpperCase() + vars.need.slice(1),
        quiz_completed_at: new Date().toISOString(), quiz_locale: CFG.locale, quiz_version: CFG.quizVersion, quiz_result_id: a._rid || null,
      },
    };
  }

  // ---------- rendering & events ----------
  function render() {
    const list = active();
    if (step >= list.length) step = list.length - 1;
    const s = list[step];
    if (a.foods.length === 1) a.foodMain = a.foods[0];
    else if (!a.foods.includes(a.foodMain)) a.foodMain = undefined;
    // Progress by part ("FOOD · PART 3 OF 4"): the total never changes as answers add or skip steps (M1).
    const head = s.part != null ? `<p class="zq-step">${tx(U.sections[s.part])} · ${fill(U.partOf, { n: s.part + 1, total: U.sections.length })}</p>` : '';
    let html = R[s.id]();
    // The info card for the answer just given sits under this question (no screen of its own).
    if (s.fact && (!s.factWhen || s.factWhen())) {
      let card = ''; try { card = R[s.fact](); } catch (e) { /* missing answer: no card */ }
      const at = html.lastIndexOf('<div class="zq-nav"');
      html = at >= 0 ? html.slice(0, at) + card + html.slice(at) : html + card;
    }
    app.innerHTML = `<section class="zq-screen${s.id === 'result' ? ' zq-result' : ''}">${head}${html}</section>`;
    bar.style.width = `${(step / (list.length - 1)) * 100}%`;
    refreshNext();
    save();
    const first = app.querySelector('input[type=text],input[type=number],input[type=email]');
    if (first && !first.value && s.id !== 'label') first.focus({ preventScroll: true });
    window.scrollTo({ top: 0 });
  }

  // Next is never greyed out (N4): tapped with something missing, it says what, beside the answer.
  function refreshNext() {
    const s = active()[step];
    if (!s.valid || s.valid()) app.querySelector('.zq-why')?.remove();
  }
  function showWhy(s) {
    let box = app.querySelector('.zq-why');
    if (!box) {
      box = document.createElement('p');
      box.className = 'zq-why'; box.setAttribute('role', 'alert');
      const nav = app.querySelector('.zq-nav');
      nav ? nav.before(box) : app.querySelector('.zq-screen').append(box);
    }
    box.textContent = tx(U.why[s.why] || U.why.generic);
    const empty = [...app.querySelectorAll('input:not([type=hidden]):not([disabled])')].find((x) => x.type !== 'checkbox' && x.value === '');
    (empty || box).scrollIntoView({ block: 'center', behavior: 'smooth' });
    if (empty) empty.focus({ preventScroll: true });
  }

  // One free try: the AI reads a photo of the food label and fills the numbers in (QuizLabelController).
  // The photo is only read, never stored; brand and product go on the profile so the coach knows the food.
  let scan = { state: 'idle' };
  function scanBox() {
    if (!CFG.labelScanUrl) return '';
    const T = U.label.scan; const r = a.labelScan;
    const out = {
      idle: '', reading: `<p class="zq-scan-msg">${T.reading}</p>`,
      error: `<p class="zq-scan-msg err">${esc(scan.error || T.error)}</p>`,
    }[scan.state] ?? '';
    const found = r && scan.state !== 'reading' ? `<div class="zq-scan-result">
        ${r.brand ? `<p><b>${esc([r.brand, r.product].filter(Boolean).join(' – '))}</b></p>` : ''}
        <p>${r.readable ? T.filled : T.unreadable}</p>${r.take ? `<p class="zq-small">${esc(r.take)}</p>` : ''}</div>` : '';
    const canTry = (a.labelScanTriesLeft ?? 1) > 0 && !(r && r.readable);
    // Secondary (outline): "Show me" is the step's one orange button (test report: two orange buttons).
    const btn = canTry ? `<button type="button" class="zq-btn outline block" data-action="scanLabel" ${scan.state === 'reading' ? 'disabled' : ''}>${scan.state === 'reading' ? T.reading : T.button}</button>
        <p class="zq-small" style="text-align:center;margin:6px 0 0">${T.note}</p>
        <input type="file" id="zq-scanfile" accept="image/*" hidden>` : '';
    return `<div class="zq-scan" id="zq-scan">${btn}${out}${found}</div>`; // typing comes first now: no "or type it in" line
  }
  function refreshScan() {
    if (active()[step].id !== 'label') return;
    render();
  }
  // Big phone photos are slow to upload: shrink to 1568 px JPEG first (what the AI reads anyway).
  async function shrinkPhoto(file) {
    try {
      const bmp = await createImageBitmap(file, { imageOrientation: 'from-image' });
      const k = Math.min(1, 1568 / Math.max(bmp.width, bmp.height));
      const c = document.createElement('canvas');
      c.width = Math.round(bmp.width * k); c.height = Math.round(bmp.height * k);
      c.getContext('2d').drawImage(bmp, 0, 0, c.width, c.height);
      const blob = await new Promise((res) => c.toBlob(res, 'image/jpeg', 0.85));
      return blob || file;
    } catch (e) { return file; }
  }
  async function scanLabel(file) {
    scan = { state: 'reading' }; refreshScan();
    try {
      const body = new FormData();
      body.append('photo', await shrinkPhoto(file), 'label.jpg');
      body.append('locale', CFG.locale || 'en');
      const res = await fetch(CFG.labelScanUrl, { method: 'POST', headers: { 'X-CSRF-Token': CFG.accountToken }, body });
      const data = await res.json().catch(() => ({}));
      if (data.limit) a.labelScanTriesLeft = 0;
      if (!res.ok || !data.ok) throw new Error(data.error || '');
      a.labelScanTriesLeft = data.triesLeft;
      a.labelScan = { readable: data.readable, brand: data.brand, product: data.product, format: data.format, take: data.take };
      if (data.readable) {
        Object.entries(data.values || {}).forEach(([k, v]) => { a.label[k] = String(v); });
        a.labelSkipped = false;
      }
      scan = { state: 'idle' };
    } catch (err) {
      console.error('[ZENOO quiz] label scan', err);
      scan = { state: 'error', error: err.message || '' };
    }
    refreshScan();
  }

  // The personal ZENOO plan, as PlanBuilder works it out: the biggest share of the bowl (whole,
  // half, topper) that stays under the daily budget, grams from the pack's energy, a 2-week starter.
  function zenooPlan(r) {
    const Z = CFG.zenoo;
    if (!Z || !r || !r.kcal) return null;
    const foodKcal = r.kcal * 0.95;
    const perGram = Z.variant.price / Z.variant.packGrams;
    const opt = (share) => {
      const g = foodKcal * share / Z.kcal100 * 100;
      return { grams: g >= 100 ? Math.round(g / 5) * 5 : Math.round(g), cost: g * perGram, need: g * Z.starterDays };
    };
    // The cheapest way to buy two weeks' worth in one pack size (as PlanBuilder::starter()).
    const starter = (need) => (Z.packs || [Z.variant]).map((v) => ({ v, qty: Math.max(1, Math.ceil(need / v.packGrams)) }))
      .sort((x, y) => x.qty * x.v.price - y.qty * y.v.price || x.qty * x.v.packGrams - y.qty * y.v.packGrams)[0];
    const shares = { full: 1, half: 0.5, topper: 0.25 };
    const option = ['full', 'half'].find((k) => opt(shares[k]).cost <= Z.budget) || 'topper';
    const o = opt(shares[option]);
    const s = starter(o.need);
    const cartUrl = `${Z.cartBase}${s.v.id}:${s.qty}?${Z.starterCode ? `discount=${encodeURIComponent(Z.starterCode)}&` : ''}${Z.utm || ''}`.replace(/[?&]$/, '');
    return { option, grams: o.grams, cost: o.cost, starterPrice: s.qty * s.v.price, cartUrl };
  }

  let autoTimer = null;
  function goNext() {
    step = Math.min(step + 1, active().length - 1);
    if (step === 1) track('quiz_start');
    if (active()[step].id === 'result') track('quiz_result', { value: r0()?.total });
    if (active()[step].id === 'result' && !submitted) { submitted = true; a._rid = a._rid || newId(); submit(); }
    render();
  }

  function setPath(key, val) {
    if (key.startsWith('label.')) { a.label[key.slice(6)] = val; a.labelSkipped = false; } else a[key] = val;
  }

  app.addEventListener('click', (e) => {
    const t = e.target.closest('button');
    if (!t) return;
    if (t.dataset.set) {
      a[t.dataset.set] = t.dataset.num || ['actBand'].includes(t.dataset.set) ? +t.dataset.val : t.dataset.val;
      render();
      // One tap per answer: once this screen's answers are all set, move on after a short pause.
      const cur = active()[step];
      if (cur.auto && cur.auto.includes(t.dataset.set) && cur.valid()) {
        const at = step; clearTimeout(autoTimer);
        autoTimer = setTimeout(() => { if (step === at) goNext(); }, 350);
      }
    } else if (t.dataset.multi) {
      const arr = a[t.dataset.multi]; const v = t.dataset.val;
      a[t.dataset.multi] = arr.includes(v) ? arr.filter((x) => x !== v) : [...arr, v];
      render();
    } else if (t.dataset.action === 'next') {
      const cur = active()[step];
      if (cur.valid && !cur.valid()) { showWhy(cur); return; }
      goNext();
    } else if (t.dataset.action === 'back') { clearTimeout(autoTimer); step = Math.max(0, step - 1); render(); }
    else if (t.dataset.action === 'scanLabel') {
      // The shared picker: the camera straight away on a phone; a file, the webcam or the phone
      // (QR code) on a computer (public/photo-picker.js). Without it, the plain file input.
      if (window.ZenooPhoto) window.ZenooPhoto.pick({ max: 1, direct: true, title: U.label.scan.button }).then(([f]) => { if (f) scanLabel(f); });
      else document.getElementById('zq-scanfile')?.click();
    }
    else if (t.dataset.action === 'skipLabel') { a.labelSkipped = true; step += 1; render(); }
    else if (t.dataset.action === 'restart') {
      // Start again, same dog or not: keep who's signed in and which dog this was for (N3).
      a = { ...blank(), _dogId: a._dogId, _new: a._new }; withUser(); step = 0; submitted = false; keep = true; account = { state: 'idle' }; render();
    }
    // "Edit answers" (M4): back to the last question with every answer kept; a new result is saved to the same dog.
    else if (t.dataset.action === 'edit') {
      const list = active();
      step = list.map((x, i) => (!x.slide && x.part != null ? i : -1)).filter((i) => i >= 0).pop();
      submitted = false; delete a._rid; account = { state: 'idle' }; keep = true; render();
    }
    else if (t.dataset.action === 'chooseUpdate') { a._dogId = account.dog.id; a._new = false; submitAccount(klaviyoPayload(S.evaluate(answers()))); }
    else if (t.dataset.action === 'chooseNew') { a._new = true; a._dogId = undefined; submitAccount(klaviyoPayload(S.evaluate(answers()))); }
    else if (t.dataset.action === 'share') {
      const url = location.origin + location.pathname;
      if (navigator.share) navigator.share({ title: U.result.shareTitle, url }).catch(() => {});
      else navigator.clipboard?.writeText(url).then(() => { t.textContent = U.result.linkCopied; });
    }
  });

  app.addEventListener('input', (e) => {
    const el = e.target;
    if (el.dataset.amount) {
      const x = amount(el.dataset.amount);
      x[el.dataset.field] = el.type === 'checkbox' ? el.checked : el.value;
      if (el.dataset.field === 'ns') {
        el.closest('.zq-amount').querySelectorAll('input[type=number],select').forEach((f) => { f.disabled = el.checked; });
      }
      refreshNext(); save();
      return;
    }
    if (el.dataset.input) {
      setPath(el.dataset.input, el.value);
      if (el.dataset.live === 'breed') {
        // re-render list only, keep focus in the search box
        document.getElementById('zq-breedlist').innerHTML = breedOptions(el.value);
      }
      if (el.dataset.live === 'weight') {
        const tmp = document.createElement('div'); tmp.innerHTML = R.weight();
        document.getElementById('zq-weightwarn').innerHTML = tmp.querySelector('#zq-weightwarn').innerHTML;
      }
      refreshNext(); save();
    }
  });

  // Links that count as a funnel step (the result's "order a starter" link).
  app.addEventListener('click', (e) => { const a = e.target.closest && e.target.closest('a[data-track]'); if (a) track(a.dataset.track); });

  app.addEventListener('change', (e) => {
    if (e.target.id === 'zq-scanfile' && e.target.files[0]) scanLabel(e.target.files[0]);
    if (e.target.dataset.check) { a[e.target.dataset.check] = e.target.checked; save(); }
  });

  app.addEventListener('keydown', (e) => {
    if (e.key === 'Enter' && e.target.matches('input')) {
      const btn = app.querySelector('[data-action="next"]');
      if (btn && !btn.disabled) btn.click();
    }
  });

  // ZENOO account (site only, not in the handoff prototype): the result is posted to the site,
  // which emails a confirmation link that creates the pet parent's account and dog dashboard.
  // When they're already logged in it's saved to their dashboard straight away.
  let account = { state: 'idle' };
  function accountBox() {
    const T = C.account;
    const msg = {
      idle: '', sending: tx(T.sending),
      sent: tx(T.sent, { email: `<b>${esc(a.email)}</b>` }),
      saved: tx(T.saved), error: account.error ? esc(account.error) : T.error,
      choose: T.choose,
    }[account.state];
    if (account.state === 'choose') {
      const n = esc(account.dog.name).toUpperCase();
      return `<h3 style="font-family:var(--font-display);margin:0 0 6px">${fill(T.chooseTitle, { name: n })}</h3><p style="margin:0 0 10px">${msg}</p>
        <div class="zq-choose"><button class="zq-btn" data-action="chooseUpdate">${fill(T.chooseUpdate, { name: n })}</button><button class="zq-btn forest" data-action="chooseNew">${fill(T.chooseNew, { name: n })}</button></div>`;
    }
    const btn = account.state === 'saved' ? `<p style="margin:10px 0 0"><a class="zq-btn" href="${esc(account.dashboard)}">${T.open}</a></p>` : '';
    const title = account.state === 'saved' ? T.savedTitle : (account.state === 'sent' || account.state === 'sending') && !CFG.user ? U.result.inboxTitle : T.title;
    return `<h3 style="font-family:var(--font-display);margin:0 0 6px">${title}</h3><p style="margin:0">${msg}</p>${btn}`;
  }
  function setAccount(next) {
    account = { ...account, ...next };
    const box = document.getElementById('zq-account');
    if (box) box.innerHTML = accountBox();
  }
  function submitAccount(p) {
    if (!CFG.accountSubmitUrl) return;
    setAccount({ state: 'sending' });
    fetch(CFG.accountSubmitUrl, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CFG.accountToken },
      body: JSON.stringify({
        email: p.email, first_name: p.first_name, locale: CFG.locale, properties: { ...p.properties, quiz_marketing_consent: p.marketing },
        // _inputs: what the score was worked out from, so the site can re-score after a new weight or body shape.
        answers: { ...answersToSave(), _inputs: answers() },
        ...(CFG.user && a._dogId ? { dog_id: a._dogId } : {}), ...(CFG.user && a._new ? { new: true } : {}),
        ...(thenChat && CFG.user ? { then: 'chat' } : {}),
      }),
    }).then((res) => res.json().then((data) => ({ ok: res.ok, data })))
      .then(({ ok, data }) => {
        if (!ok) throw new Error(data.error || '');
        if (data.status === 'choose') { setAccount({ state: 'choose', dog: data.dog }); return; }
        setAccount(data.status === 'saved' ? { state: 'saved', dashboard: data.dashboard } : { state: 'sent' });
        // Saved to the account: nothing about it stays in this browser (F7).
        if (data.status === 'saved') { keep = false; clearStore(); if (data.dog_id) { a._dogId = data.dog_id; a._new = false; } }
        else { a._saved = 'sent'; save(); }
        if (data.status === 'saved' && data.dashboard) location.assign(data.dashboard);
        if (data.status !== 'saved') track('quiz_submit');
      })
      .catch((err) => { console.error('[ZENOO quiz] account', err); setAccount({ state: 'error', error: err.message || '' }); });
  }

  // Klaviyo Client API (public company ID only, safe in the browser). The subscription carries the
  // profile properties and adds the profile to the list, which triggers the welcome flow; the event is for reporting.
  function klaviyo(path, body) {
    return fetch(`https://a.klaviyo.com/client/${path}/?company_id=${CFG.klaviyoCompanyId}`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/vnd.api+json', accept: 'application/vnd.api+json', revision: '2025-01-15' },
      body: JSON.stringify({ data: body }),
    }).then((res) => { if (!res.ok) throw new Error(`Klaviyo ${path}: ${res.status}`); });
  }

  // One id per finished quiz, so the same result is never saved twice (sign-in and email link).
  const newId = () => (crypto.randomUUID ? crypto.randomUUID() : String(Date.now()) + Math.random().toString(16).slice(2));

  // Funnel steps and, with consent, GA4 / the Meta pixel (public/consent.js).
  const track = (step, params) => { try { window.znTrack && window.znTrack(step, params); } catch (e) { /* never break the quiz */ } };
  const r0 = () => { try { return S.evaluate(answers()); } catch (e) { return null; } };
  let submitted = false;
  function submit() {
    const r = S.evaluate(answers());
    const p = klaviyoPayload(r);
    submitAccount(p);
    if (!CFG.sendToKlaviyo) { console.info('[ZENOO quiz] Klaviyo payload (not sent):', p); return; }
    const profile = { email: p.email, first_name: p.first_name, locale: CFG.locale, properties: { ...p.properties, quiz_marketing_consent: p.marketing } };
    Promise.all([
      p.marketing && klaviyo('subscriptions', {
        type: 'subscription',
        attributes: {
          custom_source: `Dog Quiz (${CFG.locale})`,
          profile: { data: { type: 'profile', attributes: { ...profile, subscriptions: { email: { marketing: { consent: 'SUBSCRIBED' } } } } } },
        },
        relationships: { list: { data: { type: 'list', id: CFG.klaviyoListId } } },
      }),
      klaviyo('events', {
        type: 'event',
        attributes: {
          properties: { ...p.properties, consent_text_shown: p.consent_text_shown, marketing_consent: p.marketing },
          metric: { data: { type: 'metric', attributes: { name: 'Completed Dog Quiz' } } },
          profile: { data: { type: 'profile', attributes: profile } },
        },
      }),
    ]).catch((err) => {
      // Only a visitor waiting for our email hears about it; a member's result is saved either way (N3).
      console.error('[ZENOO quiz]', err);
      const box = !CFG.user && document.querySelector('#zq-inbox p');
      if (box) box.textContent = U.result.sendError;
    });
  }

  render();

  // A result finished before signing in, still only in this browser: save it to the account now (N1).
  if (CFG.user && active()[step].id === 'result' && a._saved !== 'account' && stored && !stored._owner) {
    submitted = true; a._rid = a._rid || newId(); submitAccount(klaviyoPayload(S.evaluate(answers())));
  }
})();
