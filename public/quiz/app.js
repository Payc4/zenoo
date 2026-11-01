// ZENOO "Is your dog eating properly?" quiz — prototype UI.
(function () {
  const C = window.ZQ_CONTENT;
  const S = window.ZQScoring;
  const CFG = window.ZQ_CONFIG;
  const app = document.getElementById('zq-app');
  const bar = document.getElementById('zq-progress');
  const STORE_KEY = 'zq-proto-v1';

  const blank = () => ({ foods: [], signs: [], label: {}, amounts: {} });
  let a = { ...blank(), ...(load() || {}) };
  let step = a._step || 0;

  function load() { try { return JSON.parse(localStorage.getItem(STORE_KEY)); } catch (e) { return null; } }
  function save() { try { localStorage.setItem(STORE_KEY, JSON.stringify({ ...a, _step: step })); } catch (e) { /* storage blocked: quiz still works */ } }

  const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const name = () => esc(a.name || 'your dog');
  const poss = () => { const n = a.name || 'your dog'; return esc(/s$/i.test(n) ? n + '’' : n + '’s'); };
  const fill = (t, vars) => t.replace(/\{(\w+)\}/g, (_, k) => (vars[k] ?? ''));
  const listJoin = (xs) => (xs.length < 2 ? xs.join('') : xs.slice(0, -1).join(', ') + ' and ' + xs[xs.length - 1]);
  const num = (v) => (v === '' || v == null || isNaN(+v) ? null : +v);

  // ---------- derived data ----------
  function breed() {
    if (a.breedId === 'mixed') {
      const t = C.mixedTypes.find((x) => x.id === a.mixedType) || C.mixedTypes[4];
      return { name: 'mixed-breed dog', energy: t.energy, kg: null, mixed: true };
    }
    return C.breeds.find((b) => b.id === a.breedId);
  }
  function ageYears() { return (num(a.ageY) || 0) + (num(a.ageM) || 0) / 12; }
  function size() {
    if (a.weight) return S.sizeClass(+a.weight);
    if (a.breedId === 'mixed') return a.mixedSize || 'medium';
    const b = breed(); return b ? S.sizeClass((b.kg[0] + b.kg[1]) / 2) : 'medium';
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
      weightKg: +a.weight, neutered: a.neutered === 'yes', ageYears: ageYears(),
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
  const steps = [
    { id: 'intro' },
    { id: 'name', valid: () => (a.name || '').trim().length > 0 },
    { id: 'breed', valid: () => !!a.breedId },
    { id: 'mixed', when: () => a.breedId === 'mixed', valid: () => a.mixedSize && a.mixedType },
    { id: 'slideBreed', slide: true },
    { id: 'age', valid: () => { const y = ageYears(); return (a.ageY !== undefined && a.ageY !== '') && y > 0 && y < 25; } },
    { id: 'slideAge', slide: true },
    { id: 'sex', valid: () => a.sex && a.neutered },
    { id: 'weight', valid: () => num(a.weight) > 0.5 && num(a.weight) < 110 },
    { id: 'shape', valid: () => !!a.bcs },
    { id: 'slideBody', slide: true },
    { id: 'activity', valid: () => a.actBand && a.actType },
    { id: 'slideActivity', slide: true },
    { id: 'food', valid: () => a.foods.length > 0 },
    { id: 'foodMain', when: () => a.foods.length > 1, valid: () => a.foods.includes(a.foodMain) },
    { id: 'amounts', valid: () => amountsValid() },
    { id: 'routine', valid: () => a.meals && a.treats },
    { id: 'label', valid: () => labelValid() },
    { id: 'slideBowl', slide: true },
    { id: 'appetite', valid: () => !!a.appetite },
    { id: 'signs', valid: () => true },
    { id: 'slideSigns', slide: true, when: () => a.signs.length > 0 },
    { id: 'gate', valid: () => (a.parentName || '').trim() && /^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(a.email || '') },
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
  const nav = (label = 'NEXT') => `<div class="zq-nav"><button class="zq-back" data-action="back">Back</button><button class="zq-btn" data-action="next">${label}</button></div>`;
  const qhead = (title, lead) => `<h2>${title}</h2>${lead ? `<p class="zq-lead">${lead}</p>` : ''}`;
  const slide = (tag, title, body, file, alt) => `<div class="zq-slide">${photo(file, alt)}<div class="zq-slide-body"><span class="zq-tag">${tag}</span><h2>${title}</h2>${body}</div></div>${nav('GOT IT')}`;

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
    { bcs: 2, label: 'Very thin', svg: shapeSvg(10, 3.5, 7) },
    { bcs: 4, label: 'Slim', svg: shapeSvg(11, 6, 8.5) },
    { bcs: 5, label: 'Just right', svg: shapeSvg(12, 8, 10) },
    { bcs: 7, label: 'A bit heavy', svg: shapeSvg(13, 14, 12) },
    { bcs: 8, label: 'Heavy', svg: shapeSvg(14, 18.5, 14.5) },
  ];

  const R = {
    intro: () => `
      <div class="zq-slide">${photo('intro-hero', 'A curly-coated dog looking up at a bowl of food')}
      <div class="zq-slide-body">
        <h1>IS YOUR DOG EATING PROPERLY?</h1>
        <p class="zq-lead">A free 3-minute check of your dog’s bowl, body and day. You’ll get a Bowl Score and a personal daily plan.</p>
        <button class="zq-btn block" data-action="next">START THE CHECK</button>
        <p class="zq-small" style="margin-top:12px">No right or wrong answers. Just tell us about your dog.</p>
      </div></div>`,

    name: () => `${qhead('WHAT’S YOUR DOG’S NAME?')}
      <input type="text" data-input="name" value="${esc(a.name)}" placeholder="e.g. Bella" autocomplete="off" maxlength="30">${nav()}`,

    breed: () => {
      const q = (a._breedQ || '').toLowerCase();
      const list = C.breeds.filter((b) => b.name.toLowerCase().includes(q)).sort((x, y) => x.name.localeCompare(y.name));
      return `${qhead(`WHAT BREED IS ${name().toUpperCase()}?`)}
        <input type="search" data-input="_breedQ" data-live="breed" value="${esc(a._breedQ)}" placeholder="Search breeds" autocomplete="off">
        <div class="zq-breedlist" id="zq-breedlist">${opt('breedId', 'mixed', 'Mixed breed or not listed', 'we’ll go by size and type')}${list.map((b) => opt('breedId', b.id, b.name)).join('')}</div>${nav()}`;
    },

    mixed: () => `${qhead(`TELL US A BIT MORE ABOUT ${name().toUpperCase()}`)}
      <label class="zq-label">Size when fully grown</label>
      <div class="zq-options">${C.mixedSizes.map((s) => opt('mixedSize', s.id, s.label, s.hint)).join('')}</div>
      <label class="zq-label">Closest type</label>
      <div class="zq-options">${C.mixedTypes.map((t) => opt('mixedType', t.id, t.label)).join('')}</div>${nav()}`,

    slideBreed: () => {
      const b = breed();
      return b.mixed
        ? slide('DID YOU KNOW?', 'EVERY MIX IS ONE OF A KIND', `<p>${C.mixedSlide[a.mixedSize]}</p>`, `mixed-${a.mixedSize}`, 'A mixed-breed dog')
        : slide('DID YOU KNOW?', b.name.toUpperCase(), `<p>${b.slide}</p>`, `breed-${b.id}`, b.name);
    },

    age: () => `${qhead(`HOW OLD IS ${name().toUpperCase()}?`)}
      <div class="zq-row">
        <div><label class="zq-label">Years</label><input type="number" inputmode="numeric" min="0" max="25" data-input="ageY" value="${esc(a.ageY)}"></div>
        <div><label class="zq-label">Months</label><input type="number" inputmode="numeric" min="0" max="11" data-input="ageM" value="${esc(a.ageM)}" placeholder="0"></div>
      </div>${nav()}`,

    slideAge: () => {
      const st = stage();
      const copy = {
        puppy: ['GROWING UP FAST', `Puppies need more energy and protein per kilo than adult dogs, to build bones and muscle. Growth needs are special, so follow your vet’s guidance on portions while ${name()} grows.`],
        adult: ['IN THE PRIME OF LIFE', `Adult dogs need food that matches how active they are. Too much energy in the bowl shows up on the waistline long before you notice it on the scales.`],
        senior: ['THE GOLDEN YEARS', `Older dogs usually need fewer calories as they slow down, but protein still matters: it supports the muscle that keeps ${name()} moving.`],
      }[st];
      return slide('LIFE STAGE', copy[0], `<p>${copy[1]}</p>`, `stage-${st}`, `A ${st} dog`);
    },

    sex: () => `${qhead(`A COUPLE OF QUICK ONES ABOUT ${name().toUpperCase()}`)}
      <label class="zq-label">${name()} is a…</label>
      <div class="zq-options two">${opt('sex', 'female', 'Girl')}${opt('sex', 'male', 'Boy')}</div>
      <label class="zq-label">Neutered or spayed?</label>
      <div class="zq-options two">${opt('neutered', 'yes', 'Yes')}${opt('neutered', 'no', 'No')}</div>
      <p class="zq-small">Neutered dogs usually need a little less energy, so this helps us get the portions right.</p>${nav()}`,

    weight: () => {
      const b = breed(); const w = num(a.weight);
      const warn = b && b.kg && w && (w < b.kg[0] * 0.6 || w > b.kg[1] * 1.5)
        ? `<div class="zq-hint">Most adult ${esc(b.name)}s weigh ${b.kg[0]}–${b.kg[1]} kg. Worth a quick double-check? If it’s right, carry on.</div>` : '';
      return `${qhead(`HOW MUCH DOES ${name().toUpperCase()} WEIGH?`, 'Your best guess is fine. A recent vet visit or bathroom scales (you holding them, minus you) works well.')}
        <label class="zq-label">Weight in kg</label>
        <input type="number" inputmode="decimal" step="0.1" min="0" data-input="weight" data-live="weight" value="${esc(a.weight)}" placeholder="e.g. 6.5">
        <div id="zq-weightwarn">${warn}</div>${nav()}`;
    },

    shape: () => `${qhead(`WHICH SHAPE LOOKS MOST LIKE ${name().toUpperCase()}?`, 'Look from above. You should see a waist behind the ribs, and feel the ribs easily without pressing.')}
      <div class="zq-shapes">${SHAPES.map((s) => `<button type="button" class="zq-shape" aria-pressed="${a.bcs === s.bcs}" data-set="bcs" data-val="${s.bcs}" data-num="1">${s.svg}${s.label}</button>`).join('')}</div>
${nav()}`,

    slideBody: () => {
      const iw = Math.round(S.idealWeight(+a.weight, a.bcs) * 10) / 10;
      const body = a.bcs === 5
        ? `<p>${name()} sounds just right. Keep checking monthly: run your hands along the ribs and look for the waist from above.</p>`
        : a.bcs > 5
          ? `<p>Extra weight is really common, especially in small dogs, where a few treats a day can be a big share of their energy. From the shape you picked, ${poss()} ideal weight is around <b>${iw} kg</b>.</p><p>Every point on the scale matters for joints and energy, and it’s one of the easiest things to change.</p>`
          : `<p>From the shape you picked, ${name()} may be on the lean side. Ideal is around <b>${iw} kg</b>. Some dogs are naturally slim, so a quick check with your vet is a good idea if you’re unsure.</p>`;
      return slide('BODY CHECK', 'THE RIB AND WAIST TEST', body, 'body-check', 'Hands gently feeling a dog’s ribs');
    },

    activity: () => `${qhead(`HOW ACTIVE IS ${name().toUpperCase()} ON A TYPICAL DAY?`)}
      <label class="zq-label">Walks and play, all added up</label>
      <div class="zq-options">${C.activityBands.map((b) => opt('actBand', b.v, b.label)).join('')}</div>
      <label class="zq-label">Mostly…</label>
      <div class="zq-options">${C.activityTypes.map((t) => opt('actType', t.v, t.label)).join('')}</div>${nav()}`,

    slideActivity: () => {
      const b = breed(); const act = S.activityScore(b.energy, stage(), S.activityLevel(a.actBand, a.actType));
      const need = C.activityNeed[act.recommended];
      const got = C.activityBands.find((x) => x.v === a.actBand).label.toLowerCase();
      const who = b.mixed ? 'Dogs like ' + name() : esc(b.name) + 's';
      const enough = act.score === 15;
      return slide('ACTIVITY', enough ? 'A WELL-EXERCISED DOG' : 'ROOM FOR A LITTLE MORE',
        `<p>${who} typically do best on <b>${need}</b>. ${name()} gets <b>${got}</b> a day.</p><p>${enough
          ? 'That’s right on track. Activity also sets how much energy belongs in the bowl, so we’ll use it for the daily plan.'
          : 'Even 15 extra minutes of sniffing and exploring helps. It also changes how much food belongs in the bowl, so we’ll factor it into the plan.'}</p>`,
        'activity-generic', 'A dog trotting along a park path');
    },

    food: () => `${qhead(`WHAT DOES ${name().toUpperCase()} EAT NOW?`, 'Pick everything that goes in the bowl regularly.')}
      <div class="zq-options">${C.foods.map((f) => opt('foods', f.v, f.label, f.hint, true)).join('')}</div>${nav()}`,

    foodMain: () => `${qhead('WHICH ONE IS THE MAIN MEAL?')}
      <div class="zq-options">${C.foods.filter((f) => a.foods.includes(f.v)).map((f) => opt('foodMain', f.v, f.label)).join('')}</div>${nav()}`,

    amounts: () => {
      const rows = a.foods.map((t) => {
        const f = C.foods.find((x) => x.v === t); const x = amount(t);
        return `<div class="zq-amount">
          <label class="zq-label" for="amt-${t}">${f.label}</label>
          <div class="zq-amount-row">
            <input id="amt-${t}" type="number" inputmode="decimal" step="0.5" min="0" data-amount="${t}" data-field="v" value="${esc(x.v)}" ${x.ns ? 'disabled' : ''} placeholder="0">
            <select data-amount="${t}" data-field="u" ${x.ns ? 'disabled' : ''}>${C.units[t].map(([id, l]) => `<option value="${id}" ${x.u === id ? 'selected' : ''}>${l}</option>`).join('')}</select>
          </div>
          <label class="zq-check"><input type="checkbox" data-amount="${t}" data-field="ns" ${x.ns ? 'checked' : ''}><span>Not sure</span></label>
        </div>`;
      }).join('');
      return `${qhead(`HOW MUCH DOES ${name().toUpperCase()} EAT IN A DAY?`, 'Add up all meals for the whole day. A rough guess is fine.')}${rows}${nav()}`;
    },

    routine: () => `${qhead(`${poss().toUpperCase()} EATING ROUTINE`)}
      <label class="zq-label">How often does ${name()} eat?</label>
      <div class="zq-options">${C.meals.map((m) => opt('meals', m.v, m.label)).join('')}</div>
      <label class="zq-label">Treats, chews and bites from the table</label>
      <div class="zq-options">${C.treats.map((t) => opt('treats', t.v, t.label)).join('')}</div>${nav()}`,

    label: () => {
      const fields = [['p', 'Protein'], ['f', 'Fat'], ['fi', 'Fibre'], ['a', 'Ash'], ['m', 'Moisture']];
      return `${qhead(`WHAT’S IN ${poss().toUpperCase()} MAIN FOOD?`, 'Grab the bag or can and find “analytical constituents”. Type in the percentages and we’ll show you what’s really in the bowl.')}
        <button class="zq-btn outline block" data-action="skipLabel">I DON’T HAVE THE LABEL INFO</button>
        <p class="zq-small" style="text-align:center;margin:8px 0 18px">No problem. We’ll use typical values for ${name()}’s type of food.</p>
        ${photo('label-closeup', 'Pointing at the nutrition panel on a pet food bag', true)}
        <div class="zq-label-grid" style="margin-top:14px">${fields.map(([k, l]) => `<div><label>${l} %</label><input type="number" inputmode="decimal" step="0.1" min="0" max="100" data-input="label.${k}" value="${esc(a.label[k])}"></div>`).join('')}</div>
        <p class="zq-small">No moisture listed? Dry food is usually around 8–10%.</p>
        <div class="zq-nav"><button class="zq-back" data-action="back">Back</button><button class="zq-btn" data-action="next">SHOW ME</button></div>`;
    },

    slideBowl: () => {
      const d = diet();
      const foodLabel = C.foods.find((f) => f.v === a.foodMain).label.toLowerCase();
      const bars = [['Protein', d.pDm, ''], ['Fat', d.fDm, ''], ['Carbohydrate (mostly starch)', d.cDm, 'carbs']]
        .map(([l, v, cls]) => `<div class="zq-bar"><div class="zq-bar-top"><span>${l}</span><span>${Math.round(v)}%</span></div><div class="zq-bar-track ${cls}"><div style="width:${Math.min(100, v)}%"></div></div></div>`).join('');
      const intro = d.fromLabel
        ? `<p>Labels list everything “as fed”, water included. Carbs aren’t listed at all, so we worked them out: 100 minus protein, fat, fibre, ash and moisture. Take the water out, and this is ${poss()} bowl:</p>`
        : `<p>Without the label we’ve used typical values for ${foodLabel}, with the water taken out so all foods compare fairly. Your own label could look different.</p>`;
      return `<div class="zq-slide"><div class="zq-slide-body"><span class="zq-tag">WHAT’S IN THE BOWL</span>
        <h2>${d.fromLabel ? `HERE’S WHAT’S REALLY IN ${poss().toUpperCase()} BOWL` : `A TYPICAL ${foodLabel.toUpperCase()} BOWL`}</h2>${intro}
        <div class="zq-bars">${bars}</div>
        <p>Dogs evolved eating mostly protein and fat, with very little starch. The closer the bowl is to that, the closer it is to what their bodies are built for.</p>
        </div></div>${nav('GOT IT')}`;
    },

    appetite: () => `${qhead(`HOW DOES ${name().toUpperCase()} USUALLY EAT?`)}
      <div class="zq-options">${C.appetite.map((x) => opt('appetite', x.v, x.label)).join('')}</div>${nav()}`,

    signs: () => `${qhead(`HAVE YOU NOTICED ANY OF THESE IN ${name().toUpperCase()}?`, 'Tick any that apply, or skip if none do.')}
      <div class="zq-options two">${C.signs.map((s) => opt('signs', s.v, s.label, '', true)).join('')}</div>${nav(a.signs.length ? 'NEXT' : 'NONE OF THESE')}`,

    slideSigns: () => {
      const top = a.signs.slice(0, 2);
      const body = top.map((s) => `<p><b>${C.signs.find((x) => x.v === s).label}.</b> ${C.signSlides[s]}</p>`).join('');
      return slide('GOOD TO KNOW', 'WHAT YOU’VE NOTICED', `${body}<p class="zq-small">${C.vetLine}</p>`, 'signs-generic', 'A dog relaxing on a blanket');
    },

    gate: () => `
      <div class="zq-slide"><div class="zq-slide-body">
        <span class="zq-tag">${poss().toUpperCase()} PLAN IS READY</span>
        <h2>WHERE SHOULD WE SEND IT?</h2>
        <p class="zq-lead">We’ll email you ${poss()} Bowl Score and daily plan, plus our <b>free ZENOO feeding guide</b>.</p>
        <label class="zq-label">Your first name</label>
        <input type="text" data-input="parentName" value="${esc(a.parentName)}" autocomplete="given-name">
        <label class="zq-label">Your email</label>
        <input type="email" data-input="email" value="${esc(a.email)}" autocomplete="email" inputmode="email">
        <button class="zq-btn block" data-action="next" style="margin-top:20px">SEND MY PLAN</button>
        <p class="zq-small" style="margin-top:12px">By submitting your email, you agree to receive ${poss()} plan, the feeding guide and tips from ZENOO. Unsubscribe anytime. <a href="${CFG.privacyUrl}" target="_blank" rel="noopener">Privacy policy</a>.</p>
      </div></div>
      <div class="zq-nav"><button class="zq-back" data-action="back">Back</button><span></span></div>`,

    result: () => {
      const ans = answers(); const r = S.evaluate(ans);
      const { bandC, vars, text, mealsAdvice } = resultCopy(ans, r);
      const cards = Object.entries(r.subs).map(([k, v]) => {
        const full = v.score === v.max; const t = text(k);
        return `<div class="zq-card"><h3><span>${C.subLabels[k]}</span><span>${v.score}/${v.max}</span></h3>
          <div class="zq-bar-track"><div style="width:${(v.score / v.max) * 100}%"></div></div>
          <p style="margin-top:10px">${fill(t.why, vars)}</p>${full || !t.fix ? '' : `<div class="fix">${fill(t.fix, vars)}</div>`}</div>`;
      });
      const first = r.topFixes.map((k) => `<li>${fill(text(k).fix, vars)}</li>`).join('');
      const foodLabel = (t) => C.foods.find((f) => f.v === t).label;
      const feedRows = r.planFeeds
        ? r.planFeeds.map((x) => `<dt>${foodLabel(x.type)}</dt><dd>About ${x.grams} g a day <span class="zq-small">(now ~${Math.round(x.now)} g)</span></dd>`).join('')
        : r.grams ? `<dt>Current food</dt><dd>About ${r.grams} g a day of the food you entered</dd>` : '';
      const vets = [];
      if (a.signs.length) vets.push(`You mentioned ${vars.signs}. ${C.vetLine}`);
      if (a.bcs <= 2 || a.bcs >= 8) vets.push(`${poss()} body shape is worth a check-in with your vet before changing portions a lot.`);
      const plan = `<div class="zq-plan"><h2 style="font-size:20px">${poss().toUpperCase()} DAILY PLAN</h2>
        <dl>
          <dt>Energy</dt><dd>About ${r.kcal} kcal a day${ans.stage === 'puppy' ? ' (puppy estimate: follow your vet’s growth guidance)' : ''}</dd>
          ${feedRows}
          <dt>Meals</dt><dd>${mealsAdvice}${a.treats !== 'none' ? ', treats counted in' : ''}</dd>
          ${r.targets ? `<dt>Protein</dt><dd>${Math.round(r.targets.proteinG[0])}–${Math.round(r.targets.proteinG[1])} g a day</dd>
          <dt>Fat</dt><dd>${Math.round(r.targets.fatG[0])}–${Math.round(r.targets.fatG[1])} g a day</dd>
          <dt>Carbs</dt><dd>As low as practical. Dogs evolved on very little.</dd>
          <dt>Fibre</dt><dd>A little (about 2–5% of the dry food) supports digestion.</dd>` : ''}
          <dt>Weight</dt><dd>${a.bcs === 5 ? `Keep ${name()} around ${(+a.weight).toFixed(1)} kg` : `Aim for about ${r.idealKg} kg, slowly`}</dd>
          <dt>Activity</dt><dd>${vars.need[0].toUpperCase() + vars.need.slice(1)}</dd>
        </dl>
        <p class="zq-small" style="margin-top:12px">A starting point, not a prescription. Adjust by watching ${poss()} body shape every couple of weeks.</p></div>`;
      const payload = klaviyoPayload(r);
      const b = breed(); const y = Math.floor(ageYears()); const m = Math.round((ageYears() - y) * 12);
      const plural = (n, w) => `${n} ${w}${n === 1 ? '' : 's'}`;
      const age = [y ? plural(y, 'year') : '', m ? plural(m, 'month') : ''].filter(Boolean).join(' ') || 'Under a month';
      const fixed = a.sex === 'female' ? 'spayed' : 'neutered';
      const facts = [
        ['Breed', b.mixed ? `Mixed breed, ${C.mixedSizes.find((x) => x.id === size()).label.toLowerCase()}` : esc(b.name)],
        ['Age', `${age} · ${stage()}`],
        ['Weight', `${(+a.weight).toFixed(1).replace(/\.0$/, '')} kg`],
        ['Sex', `${a.sex === 'female' ? 'Girl' : 'Boy'} · ${a.neutered === 'yes' ? fixed : 'not ' + fixed}`],
      ];
      const profile = `<div class="zq-profile">${photo(b.mixed ? `mixed-${size()}` : `breed-${a.breedId}`, b.mixed ? 'A mixed-breed dog' : b.name)}
        <div class="zq-profile-body"><h1>${name().toUpperCase()}</h1>
          <dl>${facts.map(([k, v]) => `<div><dt>${k}</dt><dd>${v}</dd></div>`).join('')}</dl></div></div>`;
      return `
        ${profile}
        <div class="zq-scorecard"><div class="zq-small">${poss().toUpperCase()} BOWL SCORE</div>
          <div class="zq-score-num">${r.total}<small>/100</small></div>
          <h1>${bandC.title}</h1><p>${fill(bandC.text, vars)}</p></div>
        <div class="zq-card"><h3>WHAT WE’D DO FIRST</h3><ol style="margin:0;padding-left:20px">${first || '<li>Keep doing what you’re doing!</li>'}</ol></div>
        ${plan}
        ${vets.map((v) => `<div class="zq-vet">${v}</div>`).join('')}
        <h2 style="margin-top:20px">THE BREAKDOWN</h2>${cards.join('')}
        <div class="zq-sent"><h3 style="font-family:var(--font-display);margin:0 0 6px">CHECK YOUR INBOX</h3>
          <p style="margin:0">We’ve sent ${poss()} plan and your free feeding guide to <b>${esc(a.email)}</b>.</p></div>
        <div class="zq-nav"><button class="zq-btn forest" data-action="share">SHARE THE CHECK</button><button class="zq-back" data-action="restart">Start again</button></div>
        <details class="zq-demo"><summary>Prototype: data ${CFG.sendToKlaviyo ? 'sent' : 'that would go'} to Klaviyo</summary><pre>${esc(JSON.stringify(payload, null, 2))}</pre></details>`;
    },
  };

  // Copy shared by the result screen and the Klaviyo profile, so email #1 says what the screen said.
  function resultCopy(ans, r) {
    const b = breed(); const d = ans.diet;
    const bandC = C.bands[r.band];
    const vars = {
      name: name(), p: Math.round(d.pDm), c: Math.round(d.cDm), ideal: r.idealKg, kcal: r.kcal,
      breed: b.mixed ? 'dog like ' + name() : esc(b.name), need: C.activityNeed[r.subs.activity.recommended],
      got: C.activityBands.find((x) => x.v === a.actBand).label.toLowerCase(),
      signs: listJoin(a.signs.map((s) => C.signs.find((x) => x.v === s).label.toLowerCase())) || 'a fussy appetite',
    };
    vars.intake = r.intakeKcal;
    const text = (k) => {
      const copy = C.subCopy[k]; const v = r.subs[k];
      if (k === 'routine') {
        const p = v.parts;
        if (r.ratio == null) return { why: copy.unknown, fix: copy.fixUnknown };
        if (p.portion < 6) return r.ratio > 1 ? { why: copy.over, fix: copy.fixOver } : { why: copy.under, fix: copy.fixUnder };
        if (p.mealPts < 2) {
          if (ans.stage === 'puppy') return { why: copy.puppy, fix: copy.fixPuppyMeals };
          return { why: a.meals === 'free' ? copy.free : copy.once, fix: copy.fixMeals };
        }
        if (p.treatPts < 2) return { why: copy.treats, fix: copy.fixTreats };
        return { why: copy.ok, fix: '' };
      }
      const why = k === 'body' && a.bcs < 5 ? copy.thin : v.score === v.max ? copy.ok : copy.why;
      return { why, fix: copy.fix };
    };
    const mealsAdvice = ans.stage === 'puppy' ? '3–4 small meals a day' : '2 set meals a day';
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
      consent_text_shown: `By submitting your email, you agree to receive ${poss()} plan, the feeding guide and tips from ZENOO.`,
      properties: {
        quiz_dog_name: a.name, quiz_breed: b.mixed ? 'mixed' : b.name, quiz_breed_size: size(),
        quiz_breed_photo_url: CFG.emailPhotoBase + (b.mixed ? `mixed-${size()}` : `breed-${a.breedId}`) + '.jpg',
        quiz_age_years: Math.round(ageYears() * 10) / 10, quiz_life_stage: stage(), quiz_sex: a.sex, quiz_neutered: a.neutered === 'yes',
        quiz_weight_kg: +a.weight, quiz_bcs: a.bcs, quiz_ideal_weight_kg: r.idealKg,
        quiz_activity_band: S.activityLevel(a.actBand, a.actType), quiz_recommended_activity_band: r.subs.activity.recommended,
        quiz_food_main: a.foodMain, quiz_food_types: a.foods, quiz_label_given: d.fromLabel,
        quiz_carbs_dm: Math.round(d.cDm), quiz_protein_dm: Math.round(d.pDm), quiz_appetite: a.appetite, quiz_signs: a.signs,
        quiz_top_sign: a.signs[0] || null, quiz_bowl_score: r.total, quiz_score_band: r.band,
        quiz_sub_protein: r.subs.protein.score, quiz_sub_carbs: r.subs.carbs.score, quiz_sub_freshness: r.subs.freshness.score,
        quiz_sub_body: r.subs.body.score, quiz_sub_activity: r.subs.activity.score, quiz_sub_signals: r.subs.signals.score,
        quiz_sub_routine: r.subs.routine.score, quiz_meals_per_day: a.meals, quiz_treats: a.treats,
        quiz_intake_kcal: r.intakeKcal, quiz_portion_ratio: r.ratio,
        quiz_top_fix_1: r.topFixes[0] || null, quiz_top_fix_2: r.topFixes[1] || null, quiz_kcal_target: r.kcal,
        // Ready-to-print lines for email #1
        quiz_score_title: bandC.title, quiz_score_text: fill(bandC.text, vars),
        quiz_fix_1_text: fixes[0] || 'Keep doing what you’re doing!', quiz_fix_2_text: fixes[1] || '',
        quiz_plan_food: r.planFeeds ? r.planFeeds.map((x) => `${foodLabel(x.type)}: about ${x.grams} g a day`).join(' · ')
          : r.grams ? `About ${r.grams} g a day of the food you entered` : 'Weigh a normal day’s food for a week, then match it to the energy target.',
        quiz_plan_meals: mealsAdvice + (a.treats !== 'none' ? ', treats counted in' : ''),
        quiz_plan_protein_g: r.targets ? `${Math.round(r.targets.proteinG[0])}–${Math.round(r.targets.proteinG[1])} g a day` : null,
        quiz_plan_fat_g: r.targets ? `${Math.round(r.targets.fatG[0])}–${Math.round(r.targets.fatG[1])} g a day` : null,
        quiz_plan_weight: a.bcs === 5 ? `Keep ${name()} around ${(+a.weight).toFixed(1)} kg` : `Aim for about ${r.idealKg} kg, slowly`,
        quiz_plan_activity: vars.need[0].toUpperCase() + vars.need.slice(1),
        quiz_completed_at: new Date().toISOString(), quiz_locale: CFG.locale, quiz_version: CFG.quizVersion,
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
    const qNum = list.slice(0, step + 1).filter((x) => !x.slide && x.id !== 'intro' && x.id !== 'result').length;
    const qTotal = list.filter((x) => !x.slide && x.id !== 'intro' && x.id !== 'result').length;
    const head = !s.slide && !['intro', 'result', 'gate'].includes(s.id) ? `<p class="zq-step">QUESTION ${qNum} OF ${qTotal}</p>` : '';
    app.innerHTML = `<section class="zq-screen">${head}${R[s.id]()}</section>`;
    bar.style.width = `${(step / (list.length - 1)) * 100}%`;
    refreshNext();
    save();
    const first = app.querySelector('input[type=text],input[type=number],input[type=email]');
    if (first && !first.value && s.id !== 'label') first.focus({ preventScroll: true });
    window.scrollTo({ top: 0 });
  }

  function refreshNext() {
    const s = active()[step];
    const btn = app.querySelector('[data-action="next"]');
    if (btn && s.valid) btn.disabled = !s.valid();
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
    } else if (t.dataset.multi) {
      const arr = a[t.dataset.multi]; const v = t.dataset.val;
      a[t.dataset.multi] = arr.includes(v) ? arr.filter((x) => x !== v) : [...arr, v];
      render();
    } else if (t.dataset.action === 'next') {
      if (t.disabled) return;
      if (active()[step].id === 'gate') submit();
      step = Math.min(step + 1, active().length - 1); render();
    } else if (t.dataset.action === 'back') { step = Math.max(0, step - 1); render(); }
    else if (t.dataset.action === 'skipLabel') { a.labelSkipped = true; step += 1; render(); }
    else if (t.dataset.action === 'restart') { a = blank(); step = 0; render(); }
    else if (t.dataset.action === 'share') {
      const url = location.origin + location.pathname;
      if (navigator.share) navigator.share({ title: 'Is your dog eating properly?', url }).catch(() => {});
      else navigator.clipboard?.writeText(url).then(() => { t.textContent = 'LINK COPIED'; });
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
        const q = el.value.toLowerCase();
        const list = C.breeds.filter((b) => b.name.toLowerCase().includes(q)).sort((x, y) => x.name.localeCompare(y.name));
        document.getElementById('zq-breedlist').innerHTML = opt('breedId', 'mixed', 'Mixed breed or not listed', 'we’ll go by size and type') + list.map((b) => opt('breedId', b.id, b.name)).join('');
      }
      if (el.dataset.live === 'weight') {
        const tmp = document.createElement('div'); tmp.innerHTML = R.weight();
        document.getElementById('zq-weightwarn').innerHTML = tmp.querySelector('#zq-weightwarn').innerHTML;
      }
      refreshNext(); save();
    }
  });

  app.addEventListener('keydown', (e) => {
    if (e.key === 'Enter' && e.target.matches('input')) {
      const btn = app.querySelector('[data-action="next"]');
      if (btn && !btn.disabled) btn.click();
    }
  });

  // Klaviyo Client API (public company ID only, safe in the browser). The subscription carries the
  // profile properties and adds the profile to the list, which triggers the welcome flow; the event is for reporting.
  function klaviyo(path, body) {
    return fetch(`https://a.klaviyo.com/client/${path}/?company_id=${CFG.klaviyoCompanyId}`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/vnd.api+json', accept: 'application/vnd.api+json', revision: '2025-01-15' },
      body: JSON.stringify({ data: body }),
    }).then((res) => { if (!res.ok) throw new Error(`Klaviyo ${path}: ${res.status}`); });
  }

  function submit() {
    const r = S.evaluate(answers());
    const p = klaviyoPayload(r);
    if (!CFG.sendToKlaviyo) { console.info('[ZENOO quiz] Klaviyo payload (not sent):', p); return; }
    const profile = { email: p.email, first_name: p.first_name, locale: CFG.locale, properties: p.properties };
    Promise.all([
      klaviyo('subscriptions', {
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
          properties: { ...p.properties, consent_text_shown: p.consent_text_shown },
          metric: { data: { type: 'metric', attributes: { name: 'Completed Dog Quiz' } } },
          profile: { data: { type: 'profile', attributes: { email: p.email } } },
        },
      }),
    ]).catch((err) => {
      console.error('[ZENOO quiz]', err);
      const box = document.querySelector('.zq-sent p');
      if (box) box.textContent = 'We couldn’t send the email just now. Your plan is all here, so take a screenshot to keep it.';
    });
  }

  render();
})();
