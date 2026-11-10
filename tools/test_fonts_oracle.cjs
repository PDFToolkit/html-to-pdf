'use strict';

// Positive and negative controls of the font comparison: falsified observations must fail, without any browser.
const assert = require('node:assert/strict');
const { ABSOLUTE_PX, BOLDER, LIGHTER, KNOWN_DEVIATIONS, NORM, RIVALS, RULES, SCALE_CLASSES, WEIGHTS, compare, context, design, expectations, pdfTargets, rebind, shape, weightOrder } = require('./validate_fonts.cjs');

const BROWSERS = ['chromium', 'firefox'];
const [REGULAR, BOLD, ITALIC, BOLD_ITALIC, NOTO] = ['Lato-Regular', 'Lato-Bold', 'Lato-Italic', 'Lato-BoldItalic', 'NotoSans-Regular'];
const sum = values => values.reduce((total, value) => total + value, 0);

// Lay the clusters of a sample out from a start position.
function place(element, x, letters) {
  let cursor = x;
  element.characters = letters.map(({ text, width }) => { const letter = { text, x: cursor, width }; cursor += width; return letter; });
  Object.assign(element, { x, width: sum(letters.map(letter => letter.width)) });
}

// A faultless observation, as the model predicts it for one browser, or what a browser following other rules shows.
function positive(browser, rules = RULES[browser]) {
  const expected = expectations(browser, rules), elements = {}, { programs } = context();
  const css = lineHeight => lineHeight === undefined ? '24px' : lineHeight === 'normal' ? 'normal' : lineHeight + 'px';
  let y = 100;
  for (const [id, row] of Object.entries(expected.rows)) {
    // A repeated block starts one used line height below the first; 'normal' is the hhea line spacing of the face.
    y += !row.repeats ? 60 : row.lineHeight !== 'normal' ? row.lineHeight : programs[expected.samples[row.samples[0]].segments[0].face].lineSpacing * row.sizePx;
    elements[id] = { kind: 'row', row: null, computed: { fontSizePx: row.sizePx, textIndentPx: row.indentPx, lineHeight: css(row.lineHeight) }, families: ['Lato', 'Noto Sans'], x: 0, y, boxes: 1 };
  }
  for (const [id, item] of Object.entries(expected.samples)) {
    elements[id] = { kind: 'sample', row: item.row, text: item.text, families: item.families, boxes: 1,
      computed: { fontSizePx: item.sizePx, fontWeight: item.weight, fontStyle: item.style, fontVariant: item.variant, lineHeight: css(expected.rows[item.row].lineHeight) } };
    place(elements[id], item.xPx, item.characters.map(letter => ({ text: letter.text, width: letter.widthPx })));
  }
  return { order: [...expected.order], elements };
}

// Give one sample other advances and move the following samples of its row, as a real line would.
function reshape(observed, id, letters) {
  const element = observed.elements[id], delta = sum(letters.map(letter => letter.width)) - element.width;
  place(element, element.x, letters);
  const row = observed.order.filter(member => observed.elements[member].row === element.row);
  for (const later of row.slice(row.indexOf(id) + 1)) place(observed.elements[later], observed.elements[later].x + delta, observed.elements[later].characters);
}
// Clusters of a sample as one program, or another rule set, would draw them.
const advances = (browser, id, face = null, rules = RULES[browser]) => shape(expectations(browser).samples[id], rules, face).characters.map(letter => ({ text: letter.text, width: letter.widthPx }));
const redraw = (id, face) => (elements, observed, browser) => reshape(observed, id, advances(browser, id, face));
const following = (id, rules) => (elements, observed, browser) => reshape(observed, id, advances(browser, id, null, typeof rules === 'function' ? rules(browser) : rules));
const widened = (id, change) => (elements, observed) => reshape(observed, id, elements[id].characters.map((letter, index) => ({ text: letter.text, width: change(letter.width, index) })));
const scaled = (id, factor) => widened(id, width => width * factor);
const computed = (id, property, value) => elements => { elements[id].computed[property] = value; };
const stepped = (id, first, step) => elements => { elements[id].y = elements[first].y + step; };

let positives = 0, negatives = 0, executions = 0;

// The model itself: literal values from CSS1 5.2, CSS 2.1 15.6 and the font tables.
{
  const engine = expectations('engine'), chromium = expectations('chromium'), firefox = expectations('firefox'), { programs } = context();
  const faces = (prefix, target = engine) => Object.keys(target.samples).filter(id => id.startsWith(prefix)).map(id => target.samples[id].face);
  const weights = prefix => Object.keys(engine.samples).filter(id => id.startsWith(prefix)).map(id => engine.samples[id].weight);
  const drawn = (id, target = engine) => target.samples[id].segments.map(segment => [segment.drawn, segment.sizePx, segment.face]);
  assert.equal(engine.order.length, 161);
  assert.equal(Object.keys(engine.samples).length, 130);
  assert.deepEqual(Object.values(ABSOLUTE_PX), [9, 10, 13, 16, 18, 24, 32]);
  assert.deepEqual(WEIGHTS.map(weight => [BOLDER[weight], LIGHTER[weight]]),
    [[400, 100], [400, 100], [400, 100], [700, 100], [700, 100], [900, 400], [900, 400], [900, 700], [900, 700]]);
  assert.deepEqual(weights('bolder-from-'), [400, 400, 400, 700, 700, 900, 900, 900, 900]);
  assert.deepEqual(weights('lighter-from-'), [100, 100, 100, 100, 100, 400, 400, 700, 700]);
  assert.deepEqual(['strong', 'b-in-strong', 'b-from-300', 'strong-in-b-from-300', 'b-in-strong-in-b-from-300', 'inherited-bolder', 'keyword-bold', 'keyword-normal'].map(id => engine.samples[id].weight),
    [700, 900, 400, 700, 900, 700, 700, 400]);
  for (const [desired, available, chosen] of [[300, [100, 400, 900], 100], [600, [100, 400, 900], 900], [500, [100, 400, 900], 400], [400, [300, 500], 500],
    [500, [300, 600], 300], [400, [300, 600], 300], [800, [400, 700], 700], [100, [400, 700], 400], [900, [400], 400]]) {
    assert.equal(weightOrder(desired).find(weight => available.includes(weight)), chosen);
  }
  // Scale gives every result of the chart its own program; Lato, Ladder and Slant carry the matching rules.
  assert.deepEqual(SCALE_CLASSES, [[100, 200, 300], [400], [500], [600, 700], [800, 900]]);
  assert.deepEqual(faces('bolder-from-'), [REGULAR, REGULAR, REGULAR, BOLD, BOLD, BOLD_ITALIC, BOLD_ITALIC, BOLD_ITALIC, BOLD_ITALIC]);
  assert.deepEqual(faces('lighter-from-'), [NOTO, NOTO, NOTO, NOTO, NOTO, REGULAR, REGULAR, BOLD, BOLD]);
  assert.deepEqual(['strong', 'b-in-strong', 'b-from-300', 'strong-in-b-from-300', 'b-in-strong-in-b-from-300', 'inherited-bolder', 'keyword-bold', 'keyword-normal'].map(id => engine.samples[id].face),
    [BOLD, BOLD_ITALIC, REGULAR, BOLD, BOLD_ITALIC, BOLD, BOLD, REGULAR]);
  assert.deepEqual(faces('match-lato-'), [REGULAR, REGULAR, REGULAR, REGULAR, REGULAR, BOLD, BOLD, BOLD, BOLD]);
  assert.deepEqual(WEIGHTS.map(weight => engine.samples['match-ladder-' + weight].face), [NOTO, NOTO, NOTO, REGULAR, REGULAR, BOLD, BOLD, BOLD, BOLD]);
  assert.deepEqual(faces('match-ladder-italic-'), [ITALIC, ITALIC, ITALIC, BOLD_ITALIC, BOLD_ITALIC]);
  assert.deepEqual(faces('match-ladder-oblique-'), [ITALIC, ITALIC, ITALIC, ITALIC, ITALIC, BOLD_ITALIC, BOLD_ITALIC, BOLD_ITALIC, BOLD_ITALIC]);
  assert.deepEqual(faces('style-'), [ITALIC, ITALIC, BOLD_ITALIC, REGULAR, BOLD_ITALIC, BOLD_ITALIC]);
  assert.deepEqual(['oblique-skips-italic', 'italic-skips-upright', 'match-single-900', 'fallback-default', 'fallback-default-style'].map(id => engine.samples[id].face), [BOLD_ITALIC, ITALIC, NOTO, NOTO, NOTO]);
  assert.deepEqual(faces('font-'), [NOTO, NOTO, NOTO, NOTO, REGULAR, REGULAR, BOLD_ITALIC, BOLD_ITALIC, REGULAR, REGULAR]);
  assert.deepEqual(['larger-from-medium', 'smaller-from-medium', 'nested-larger', 'smaller-from-small'].map(id => +engine.samples[id].sizePx.toFixed(4)), [19.2, 13.3333, 20.16, 10.8333]);
  assert.deepEqual(['font-1', 'font-2', 'font-3', 'font-4', 'font-5'].map(id => [engine.samples[id].sizePx, +(engine.rows['row-' + id].lineHeight.toFixed?.(4) ?? NaN) || engine.rows['row-' + id].lineHeight]),
    [[16, 18.6667], [20, 'normal'], [24, 26.4], [18, 'normal'], [30, 36]]);
  assert.deepEqual([engine.rows['row-font-1'].repeats, engine.rows['row-font-1-again'].repeats, engine.rows['row-font-1-again'].lineHeight], [undefined, 'row-font-1', engine.rows['row-font-1'].lineHeight]);
  // hmtx: 'minimum' is 8126/2000 em in Lato Regular and 4557/1000 em in Noto Sans; the Lato space is 512/2000 em.
  assert(Math.abs(engine.samples['reference-lato-regular'].widthPx - 65.008) < 1e-9 && Math.abs(engine.samples['reference-noto-sans'].widthPx - 72.912) < 1e-9);
  assert(Math.abs(engine.samples['reference-lato-bold'].xPx - (65.008 + 4.096)) < 1e-9);
  // hhea: ascender, descender and line gap are 1069, -293, 0 of 1000 in Noto Sans and 1974, -426, 0 of 2000 in Lato.
  assert.deepEqual([programs[NOTO].lineSpacing, programs[REGULAR].lineSpacing, programs[BOLD_ITALIC].lineSpacing], [1.362, 1.2, 1.2]);
  // Small capitals: synthesized at 0.8 and 0.7, a labelled face as written, and the smcp glyphs of Noto Sans (4009/1000 em).
  assert.deepEqual(drawn('caps-mixed'), [['N', 20, REGULAR], ['OMINAL', 16, REGULAR]]);
  assert.deepEqual(drawn('caps-mixed', chromium), [['N', 20, REGULAR], ['OMINAL', 14, REGULAR]]);
  assert.deepEqual(drawn('caps-sharp-s'), [['MASS', 16, REGULAR]]);
  assert.deepEqual(drawn('caps-digits'), [['2008', 20, REGULAR]]);
  assert.deepEqual(drawn('caps-punctuation'), [['N', 20, REGULAR], ['O', 16, REGULAR], ['.3', 20, REGULAR]]);
  assert.deepEqual(['caps-labelled', 'caps-labelled-bold', 'caps-labelled-normal', 'caps-feature'].map(id => drawn(id)),
    [[['minimum', 20, NOTO]], [['minimum', 20, NOTO]], [['minimum', 20, REGULAR]], [['MINIMUM', 16, NOTO]]]);
  assert.deepEqual(['caps-labelled', 'caps-labelled-bold', 'caps-labelled-normal', 'caps-feature'].map(id => drawn(id, chromium)),
    [[['MINIMUM', 14, REGULAR]], [['MINIMUM', 14, BOLD]], [['minimum', 20, REGULAR]], [['minimum', 20, NOTO]]]);
  assert.deepEqual(['caps-labelled', 'caps-labelled-bold', 'caps-feature'].map(id => drawn(id, firefox)), [[['MINIMUM', 16, REGULAR]], [['MINIMUM', 16, BOLD]], [['minimum', 20, NOTO]]]);
  assert(chromium.samples['caps-feature'].feature && !engine.samples['caps-feature'].feature && Math.abs(chromium.samples['caps-feature'].widthPx - 80.18) < 1e-9);
  // Fallback by cluster: the accent stays with its arrow in Lato although Noto Sans, listed first, has the accent.
  assert.deepEqual(engine.samples['fallback-lato-noto'].characters.map(letter => letter.face), [REGULAR, NOTO, REGULAR, NOTO, REGULAR]);
  assert.deepEqual(engine.samples['fallback-noto-lato'].characters.map(letter => letter.face), [NOTO, REGULAR, NOTO, REGULAR, NOTO, REGULAR, NOTO]);
  assert.deepEqual(engine.samples['fallback-cluster'].characters.map(letter => [letter.text, letter.face]), [['n', NOTO], ['\u2192\u0301', REGULAR], ['n', NOTO]]);
  assert.deepEqual(engine.samples['fallback-character-default'].characters.map(letter => letter.face), [REGULAR, NOTO, REGULAR]);
  // Browsers: Chromium merges italic and oblique labels, and no browser skips a family for its style.
  const deviating = ['match-ladder-oblique-400', 'match-ladder-oblique-500', 'oblique-skips-italic', 'italic-skips-upright'];
  assert.deepEqual(deviating.map(id => chromium.samples[id].face), [BOLD_ITALIC, BOLD_ITALIC, ITALIC, NOTO]);
  assert.deepEqual(deviating.map(id => firefox.samples[id].face), [ITALIC, ITALIC, REGULAR, NOTO]);

  const facts = design(), reduced = ['caps-lowercase', 'caps-mixed', 'caps-punctuation', 'caps-sharp-s', 'caps-bold', 'caps-inherited', 'font-5', 'font-5-again'];
  assert.deepEqual(facts.deviations.firefox, { 'oblique-skips-italic': 'style-family-skip', 'italic-skips-upright': 'style-family-skip',
    'caps-labelled': 'labelled-small-caps-face', 'caps-labelled-bold': 'labelled-small-caps-face', 'caps-feature': 'opentype-small-caps-feature' });
  assert.deepEqual(facts.deviations.chromium, { ...facts.deviations.firefox, 'match-ladder-oblique-400': 'chromium-slanted-faces-merged', 'match-ladder-oblique-500': 'chromium-slanted-faces-merged',
    ...Object.fromEntries(reduced.map(id => [id, 'chromium-small-caps-scale'])) });
  assert.deepEqual(Object.keys(facts.separations), Object.keys(RIVALS));
  assert(Object.keys(RIVALS).length >= 33 && Object.values(facts.separations).every(ids => ids.length > 0));
  const targets = pdfTargets();
  assert.deepEqual(targets.intendedDiagnostics.map(note => [note.sample, note.code]), [['match-single-900', 'font.weight-fallback'], ['caps-labelled-bold', 'font.weight-fallback'],
    ['fallback-default', 'font.default-family'], ['fallback-default-style', 'font.default-family'], ['fallback-default-style', 'font.style-fallback'], ['fallback-default-style', 'font.weight-fallback']]);
  assert.deepEqual(['strong', 'caps-lowercase', 'match-ladder-oblique-500', 'oblique-skips-italic', 'caps-labelled', 'caps-feature', 'fallback-cluster'].map(id => targets.samples[id].witnessedBy),
    [BROWSERS, ['firefox'], ['firefox'], [], [], [], BROWSERS]);
  assert.deepEqual(targets.samples['caps-lowercase'].knownDeviations, { chromium: 'chromium-small-caps-scale' });
  assert.deepEqual(targets.rows['row-font-2-again'], { sizePx: 20, indentPx: 0, lineHeight: 'normal', repeats: 'row-font-2', samples: ['font-2-again'] });
  assert.deepEqual(Object.keys(KNOWN_DEVIATIONS), ['relative-size-ratio', 'chromium-small-caps-scale', 'chromium-slanted-faces-merged', 'style-family-skip',
    'labelled-small-caps-face', 'opentype-small-caps-feature', 'normal-line-height']);
  ++positives;
}

for (const browser of BROWSERS) {
  const result = compare(positive(browser), browser), asserted = new Set(result.checks.filter(item => item.knownDeviation).map(item => item.knownDeviation));
  assert(result.passed, 'A faultless observation must pass: ' + JSON.stringify(result.checks.filter(item => !item.passed).slice(0, 3)));
  assert(result.comparisons >= 1800, 'Complete comparison set required');
  assert.deepEqual([...asserted].sort(), Object.keys(KNOWN_DEVIATIONS).filter(name => browser === 'chromium' || !name.startsWith('chromium-')).sort(), 'Every known deviation must be asserted');
  ++positives;
}

// An incomplete or foreign inventory is refused outright.
function refuses(description, falsify, browsers = BROWSERS) {
  for (const browser of browsers) {
    const observed = positive(browser), replaced = falsify(observed.elements, observed, browser);
    assert.throws(() => compare(replaced === undefined ? observed : replaced, browser), /Complete fixture inventory|Unknown browser|Observations required/, description);
    ++executions;
  }
  ++negatives;
}
// A falsified observation fails, and fails on the falsified property.
function rejects(description, falsify, marker, browsers = BROWSERS) {
  for (const browser of browsers) {
    const observed = positive(browser);
    falsify(observed.elements, observed, browser);
    const result = compare(observed, browser), failed = result.checks.filter(item => !item.passed).map(item => item.case + ':' + item.property);
    assert(!result.passed && failed.includes(marker), description + ' must fail on ' + marker + ' in ' + browser + '; failed: ' + failed.join(', '));
    ++executions;
  }
  ++negatives;
}

refuses('Missing sample', (elements, observed) => { delete elements['caps-digits']; observed.order.splice(observed.order.indexOf('caps-digits'), 1); });
refuses('Missing row', (elements, observed) => { delete elements['row-fallback']; observed.order.splice(observed.order.indexOf('row-fallback'), 1); });
refuses('Missing repeated block', (elements, observed) => { for (const id of ['row-font-3-again', 'font-3-again']) { delete elements[id]; observed.order.splice(observed.order.indexOf(id), 1); } });
refuses('Listed but unobserved sample', elements => { delete elements['font-5']; });
refuses('Unknown extra element', (elements, observed) => { observed.order.push('extra'); elements.extra = structuredClone(elements.strong); });
refuses('Reordered inventory', (elements, observed) => { observed.order.reverse(); });
refuses('Empty observation', () => ({ order: [], elements: {} }));
refuses('Absent observation', () => null);
assert.throws(() => compare(positive('firefox'), 'webkit'), /Unknown browser/); ++negatives; ++executions;

// Sizes: table, ratio, inheritance and em.
rejects('Absolute size depending on its parent', computed('absolute-30-small', 'fontSizePx', 14), 'absolute-30-small:fontSizePx');
rejects('Absolute size one table step off', computed('absolute-12-x-large', 'fontSizePx', 18), 'absolute-12-x-large:fontSizePx');
rejects('Rendered size different from the computed size', scaled('absolute-12-xx-large', 0.5), 'absolute-12-xx-large:widthPx');
rejects('CSS1 example reproduced: larger from medium is large', (elements, observed) => {
  elements['larger-from-medium'].computed.fontSizePx = 18; scaled('larger-from-medium', 18 / 19.2)(elements, observed);
}, 'larger-from-medium:differenceFromTableStepPx');
rejects('Smaller from medium is small', (elements, observed) => {
  elements['smaller-from-medium'].computed.fontSizePx = 13; scaled('smaller-from-medium', 13 * 1.2 / 16)(elements, observed);
}, 'smaller-from-medium:differenceFromTableStepPx');
rejects('Smaller from small is x-small', computed('smaller-from-small', 'fontSizePx', 10), 'smaller-from-small:differenceFromTableStepPx');
rejects('Relative size with the ratio 1.5', computed('larger-from-30', 'fontSizePx', 45), 'larger-from-30:fontSizePx');
rejects('Keyword inherited instead of the computed size', computed('inherited-larger', 'fontSizePx', 20.16), 'inherited-larger:fontSizePx');
rejects('Nested larger applied once', computed('nested-larger', 'fontSizePx', 16.8), 'nested-larger:fontSizePx');
rejects('Percentage of the wrong parent', computed('percentage-of-x-large', 'fontSizePx', 7), 'percentage-of-x-large:fontSizePx');
rejects('Font-size em of the element instead of its parent', computed('em-of-x-large', 'fontSizePx', 10.5), 'em-of-x-large:fontSizePx');
rejects('Indent em resolved against the parent size', elements => {
  elements['row-indent-x-large'].computed.textIndentPx = 28; place(elements['indent-x-large'], 28, elements['indent-x-large'].characters);
}, 'indent-x-large:xPx');
rejects('Indent declared but not applied', elements => place(elements['indent-larger'], 0, elements['indent-larger'].characters), 'indent-larger:xPx');

// Weights: CSS 2.1 15.6 table and inheritance of the result, in computed values and in the Scale program drawn.
rejects('Bolder as one step', computed('bolder-from-100', 'fontWeight', 200), 'bolder-from-100:fontWeight');
rejects('Bolder from 600 stopping at 700', computed('bolder-from-600', 'fontWeight', 700), 'bolder-from-600:fontWeight');
rejects('Lighter as one step', computed('lighter-from-600', 'fontWeight', 500), 'lighter-from-600:fontWeight');
rejects('Lighter from 900 reaching 400', computed('lighter-from-900', 'fontWeight', 400), 'lighter-from-900:fontWeight');
rejects('Keyword bolder inherited', computed('inherited-bolder', 'fontWeight', 900), 'inherited-bolder:fontWeight');
rejects('b as bold instead of bolder', computed('b-from-300', 'fontWeight', 700), 'b-from-300:fontWeight');
rejects('Nested strong and b not accumulating', computed('b-in-strong', 'fontWeight', 700), 'b-in-strong:fontWeight');
rejects('Normal not restoring 400', computed('keyword-normal', 'fontWeight', 700), 'keyword-normal:fontWeight');
rejects('Computed 900 drawn with the regular face', redraw('bolder-from-900', REGULAR), 'bolder-from-900:faceByFontTables');
rejects('Computed 900 drawn with the 700 face', redraw('bolder-from-600', BOLD), 'bolder-from-600:faceByFontTables');
rejects('Computed 100 drawn with the 400 face', redraw('lighter-from-400', REGULAR), 'lighter-from-400:faceByFontTables');
rejects('Computed 400 drawn with the 500 face', redraw('lighter-from-600', ITALIC), 'lighter-from-600:faceByReferenceFamily');
rejects('Nested 900 drawn with the 700 face', redraw('b-in-strong', BOLD), 'b-in-strong:faceByFontTables');
// Every single entry of the two charts: a browser computing another weight fails on that sample.
for (const [name, table] of [['bolder', BOLDER], ['lighter', LIGHTER]]) {
  for (const from of WEIGHTS) {
    for (const to of WEIGHTS.filter(weight => weight !== table[from])) {
      rejects(name + ' from ' + from + ' giving ' + to, computed(name + '-from-' + from, 'fontWeight', to), name + '-from-' + from + ':fontWeight', ['firefox']);
    }
  }
}

// Weight matching: CSS 2.1 15.6 against a nearest-weight rule, and against synthesis.
rejects('Nearest weight for 300', redraw('match-ladder-300', REGULAR), 'match-ladder-300:faceByFontTables');
rejects('Nearest weight for 600', redraw('match-ladder-600', REGULAR), 'match-ladder-600:faceByReferenceFamily');
rejects('500 treated as bold', redraw('match-lato-500', BOLD), 'match-lato-500:faceByFontTables');
rejects('600 treated as regular', redraw('match-lato-600', REGULAR), 'match-lato-600:faceByFontTables');
rejects('Italic 400 choosing 300 before 500', redraw('match-ladder-italic-400', ITALIC), 'match-ladder-italic-400:faceByFontTables');
rejects('Oblique 500 choosing the nearer 600', redraw('match-ladder-oblique-500', BOLD_ITALIC), 'match-ladder-oblique-500:faceByFontTables', ['firefox']);
rejects('Oblique 400 answered by the italic 500 face outside Chromium', redraw('match-ladder-oblique-400', BOLD_ITALIC), 'match-ladder-oblique-400:faceByFontTables', ['firefox']);
rejects('Chromium keeping italic and oblique labels apart', redraw('match-ladder-oblique-500', ITALIC), 'match-ladder-oblique-500:differsFromConverterPx', ['chromium']);
rejects('Synthetic emboldening of the only face', widened('match-single-900', width => width + 0.2), 'match-single-900:widthPx');
rejects('Reference family bound to another program', redraw('reference-lato-bold', REGULAR), 'reference-lato-bold:faceByFontTables');
rejects('Sample following a reference bound to another program', redraw('reference-lato-bold', REGULAR), 'strong:faceByReferenceFamily');
assert(BROWSERS.every(browser => !compare(positive(browser), browser).checks.some(item => item.case.startsWith('reference-') && item.property === 'faceByReferenceFamily')), 'A reference is not compared with itself');

// Styles.
rejects('Italic drawn upright', redraw('style-i', REGULAR), 'style-i:faceByFontTables');
rejects('Bold italic drawn with the regular italic', redraw('style-i-in-b', ITALIC), 'style-i-in-b:faceByFontTables');
rejects('Italic refusing an oblique face', redraw('style-italic-from-oblique-face', REGULAR), 'style-italic-from-oblique-face:faceByFontTables');
rejects('Oblique computed as italic', computed('style-oblique', 'fontStyle', 'italic'), 'style-oblique:fontStyle');
rejects('Normal not restored inside i', computed('style-normal-in-i', 'fontStyle', 'italic'), 'style-normal-in-i:fontStyle');
rejects('Browser skipping the italic-only family', redraw('oblique-skips-italic', BOLD_ITALIC), 'oblique-skips-italic:differsFromConverterPx');
rejects('Firefox answering oblique with the italic face', redraw('oblique-skips-italic', ITALIC), 'oblique-skips-italic:faceByFontTables', ['firefox']);
rejects('Chromium answering oblique with the upright face', redraw('oblique-skips-italic', REGULAR), 'oblique-skips-italic:faceByFontTables', ['chromium']);
rejects('Browser skipping the upright-only family', redraw('italic-skips-upright', ITALIC), 'italic-skips-upright:differsFromConverterPx');

// Small capitals: 0.8 in Firefox, 0.7 in Chromium, capitals, digits and punctuation unchanged, sharp s as SS.
const capitals = Object.values(expectations('chromium').samples).filter(item => item.characters.some(letter => letter.sizePx !== item.sizePx)).map(item => item.id);
assert.equal(capitals.length, 10);
rejects('Chromium synthesizing at 0.8', (elements, observed) => { for (const id of capitals) reshape(observed, id, advances('chromium', id, null, { ...RULES.chromium, smallCapsScale: 0.8 })); }, 'caps-lowercase:smallCapsScale', ['chromium']);
rejects('Firefox synthesizing at 0.7', (elements, observed) => { for (const id of capitals) reshape(observed, id, advances('firefox', id, null, { ...RULES.firefox, smallCapsScale: 0.7 })); }, 'caps-mixed:smallCapsScale', ['firefox']);
rejects('Shorthand small capitals at the other scale', following('font-5', browser => RULES[BROWSERS.find(name => name !== browser)]), 'font-5:smallCapsScale');
rejects('Digits reduced', scaled('caps-digits', 0.8), 'caps-digits:widthPx');
rejects('Punctuation and digit reduced with the lowercase letter', widened('caps-punctuation', (width, index) => width * (index < 2 ? 1 : 0.8)), 'caps-punctuation:characterAdvancesPx');
rejects('Capital reduced with the lowercase letters', widened('caps-mixed', (width, index) => width * (index ? 1 : 0.8)), 'caps-mixed:characterAdvancesPx');
rejects('Sharp s drawn as one S', widened('caps-sharp-s', (width, index) => width * (index < 2 ? 1 : 0.5)), 'caps-sharp-s:smallCapsScale');
rejects('font-variant ignored', (elements, observed, browser) => reshape(observed, 'caps-lowercase', advances(browser, 'caps-restored')), 'caps-lowercase:smallCapsScale');
rejects('Small capitals computed as normal', computed('caps-lowercase', 'fontVariant', 'normal'), 'caps-lowercase:fontVariant');
rejects('Small capitals not inherited', computed('caps-inherited', 'fontVariant', 'normal'), 'caps-inherited:fontVariant');
rejects('Normal not restored inside small-caps', (elements, observed, browser) => {
  elements['caps-restored'].computed.fontVariant = 'small-caps'; reshape(observed, 'caps-restored', advances(browser, 'caps-lowercase'));
}, 'caps-restored:fontVariant');
rejects('Bold small capitals drawn with the regular face', (elements, observed, browser) => reshape(observed, 'caps-bold', advances(browser, 'caps-lowercase')), 'caps-bold:faceByFontTables');
// A small-caps label has no browser counterpart and the smcp feature has no converter counterpart: both must persist.
rejects('Browser honouring a small-caps label', following('caps-labelled', NORM), 'caps-labelled:differsFromConverterPx');
rejects('Browser choosing the weight before a small-caps label', following('caps-labelled-bold', NORM), 'caps-labelled-bold:differsFromConverterPx');
rejects('Browser synthesizing the labelled family at the other scale', following('caps-labelled', browser => RULES[BROWSERS.find(name => name !== browser)]), 'caps-labelled:smallCapsScale');
rejects('Labelled face drawn for normal text', redraw('caps-labelled-normal', NOTO), 'caps-labelled-normal:faceByFontTables');
rejects('Browser synthesizing small capitals of a font that has them', following('caps-feature', NORM), 'caps-feature:differsFromConverterPx');
rejects('Browser synthesizing small capitals of a font that has them, at its own scale', following('caps-feature', browser => ({ ...RULES[browser], smallCapsFeature: false })), 'caps-feature:widthPx');
rejects('Small capitals of the font left as lowercase', (elements, observed, browser) => reshape(observed, 'caps-feature', advances(browser, 'caps-restored', NOTO)), 'caps-feature:widthPx');

// Per-character fallback, clusters and the default family.
rejects('Whole run moved to the second family', redraw('fallback-lato-noto', NOTO), 'fallback-lato-noto:characterAdvancesPx');
rejects('No return to the first family', (elements, observed, browser) => {
  const letters = advances(browser, 'fallback-lato-noto'); letters[4] = advances(browser, 'fallback-lato-noto', NOTO)[4]; reshape(observed, 'fallback-lato-noto', letters);
}, 'fallback-lato-noto:characterAdvancesPx');
rejects('Missing glyph drawn with no advance', widened('fallback-noto-lato', (width, index) => index === 1 ? 0 : width), 'fallback-noto-lato:characterAdvancesPx');
rejects('Characters merged in one box', elements => { elements['fallback-noto-lato'].characters.pop(); }, 'fallback-noto-lato:characterAdvancesPx');
rejects('Another character measured', elements => { elements['fallback-lato-noto'].characters[1].text = 'R'; }, 'fallback-lato-noto:characterAdvancesPx');
rejects('Combining accent measured apart from its arrow', following('fallback-cluster', browser => ({ ...RULES[browser], fallbackUnit: 'codepoint' })), 'fallback-cluster:characterAdvancesPx');
rejects('Arrow of the cluster drawn with no advance', widened('fallback-cluster', (width, index) => index === 1 ? 0 : width), 'fallback-cluster:characterAdvancesPx');
rejects('Unlisted family drawn with the first bound family', redraw('fallback-default', REGULAR), 'fallback-default:faceByFontTables');
rejects('Character missing from the only listed family not drawn', widened('fallback-character-default', (width, index) => index === 1 ? 0 : width), 'fallback-character-default:characterAdvancesPx');
rejects('Default family emboldened for a bold italic request', widened('fallback-default-style', width => width + 0.2), 'fallback-default-style:widthPx');
rejects('Default family computed without its italic request', computed('fallback-default-style', 'fontStyle', 'normal'), 'fallback-default-style:fontStyle');
rejects('Family list parsed differently', elements => { elements['fallback-noto-lato'].families = ['Noto', 'Sans', 'Lato']; }, 'fallback-noto-lato:fontFamily');
rejects('Body family list on a sample set in another family', elements => { elements['bolder-from-600'].families = ['Lato', 'Noto Sans']; }, 'bolder-from-600:fontFamily');

// The font shorthand resets what it omits, the line height included.
rejects('Shorthand keeping the inherited style', computed('font-1', 'fontStyle', 'italic'), 'font-1:fontStyle');
rejects('Shorthand keeping the inherited variant', computed('font-1', 'fontVariant', 'small-caps'), 'font-1:fontVariant');
rejects('Shorthand keeping the inherited weight', computed('font-2', 'fontWeight', 700), 'font-2:fontWeight');
rejects('Shorthand keeping the inherited line height', computed('font-2', 'lineHeight', '50px'), 'font-2:lineHeight');
rejects('Shorthand block keeping the inherited line height', computed('row-font-4', 'lineHeight', '50px'), 'row-font-4:lineHeight');
rejects('Shorthand line height in points misread', computed('font-1', 'lineHeight', '14px'), 'font-1:lineHeight');
rejects('Shorthand percentage of the wrong size', (elements, observed) => { elements['font-2'].computed.fontSizePx = 12.8; scaled('font-2', 12.8 / 20)(elements, observed); }, 'font-2:fontSizePx');
rejects('Shorthand size keyword ignored', computed('font-3', 'fontSizePx', 25), 'font-3:fontSizePx');
rejects('Shorthand percentage line height not computed', computed('font-3', 'lineHeight', 'normal'), 'font-3:lineHeight');
rejects('Shorthand family list truncated', elements => { elements['font-3'].families = [{ generic: 'serif' }]; }, 'font-3:fontFamily');
rejects('Shorthand bold ignored', computed('font-4', 'fontWeight', 400), 'font-4:fontWeight');
rejects('Shorthand italic drawn upright', redraw('font-4', BOLD), 'font-4:faceByFontTables');
rejects('Shorthand keeping small capitals', computed('font-4', 'fontVariant', 'small-caps'), 'font-4:fontVariant');
rejects('Shorthand small capitals lost', computed('font-5', 'fontVariant', 'normal'), 'font-5:fontVariant');
rejects('Shorthand normal not resetting the weight', computed('font-5', 'fontWeight', 700), 'font-5:fontWeight');
rejects('Generic family bound to another program', redraw('font-3', NOTO), 'font-3:faceByReferenceFamily');
rejects('Line laid out with the inherited 50px', stepped('row-font-2-again', 'row-font-2', 50), 'row-font-2-again:lineStepPx');
rejects('Line laid out with the points of the line height read as pixels', stepped('row-font-1-again', 'row-font-1', 14), 'row-font-1-again:lineStepPx');
rejects('Percentage line height taken from the parent size', stepped('row-font-3-again', 'row-font-3', 1.1 * 25), 'row-font-3-again:lineStepPx');
rejects('Percentage line height of 120% taken once', stepped('row-font-5-again', 'row-font-5', 30), 'row-font-5-again:lineStepPx');
rejects('Normal line height far from the font metrics', stepped('row-font-4-again', 'row-font-4', 1.5 * 18), 'row-font-4-again:lineStepPx');
rejects('Repeated block set on the same line', stepped('row-font-5-again', 'row-font-5', 0), 'row-font-5-again:lineStepPx');
rejects('Line step that is not a number', elements => { delete elements['row-font-1-again'].y; }, 'row-font-1-again:lineStepPx');

// Geometry and structure.
rejects('Sample broken over two lines', elements => { elements['bolder-from-900'].boxes = 2; }, 'bolder-from-900:lineBoxes');
rejects('Sample shifted by one pixel', elements => { elements['match-lato-900'].x += 1; }, 'match-lato-900:xPx');
rejects('Wider inter-sample space', (elements, observed) => {
  observed.order.filter(id => elements[id].row === 'row-style').forEach((id, index) => place(elements[id], elements[id].x + index, elements[id].characters));
}, 'style-oblique:xPx');
rejects('Width that is not a number', elements => { elements.strong.width = NaN; }, 'strong:widthPx');
rejects('Missing character boxes', elements => { delete elements['style-em'].characters; }, 'style-em:characterAdvancesPx');
rejects('Sample attributed to another row', elements => { elements.strong.row = 'row-bolder'; }, 'row-nesting:samples');
rejects('Row not starting at the content edge', elements => { elements['row-style'].x = 12; }, 'row-style:blockStartPx');
rejects('Row in another family', elements => { elements['row-style'].families = ['Slant']; }, 'row-style:fontFamily');
rejects('Row at another size', computed('row-absolute-30', 'fontSizePx', 12), 'row-absolute-30:fontSizePx');
rejects('Another text measured', elements => { elements['style-em'].text = 'maximum'; }, 'style-em:text');
rejects('Row observed as a sample', elements => { elements['row-reference'].kind = 'sample'; }, 'row-reference:kind');

// A browser following a rival rule fails on every sample that tells the rule from its own. Three rivals change nothing
// in a browser, which has no small-caps label and uses OpenType small capitals: their opposites are the controls above.
const view = (expected, id) => id in expected.rows ? [expected.rows[id].sizePx, expected.rows[id].indentPx, expected.rows[id].lineHeight]
  : [expected.samples[id].sizePx, expected.samples[id].weight, expected.samples[id].style, expected.samples[id].variant, expected.samples[id].characters.map(letter => [letter.text, letter.widthPx])];
const withoutEffect = [];
for (const [name, rival] of Object.entries(RIVALS)) {
  let effective = 0;
  for (const browser of BROWSERS) {
    const rules = { ...RULES[browser], ...rival.rules }, drawn = expectations(browser, rules), expected = expectations(browser);
    const changed = rival.separates.filter(id => JSON.stringify(view(drawn, id)) !== JSON.stringify(view(expected, id)));
    if (changed.length === 0) continue;
    const result = compare(positive(browser, rules), browser), failed = new Set(result.checks.filter(item => !item.passed).map(item => item.case));
    assert(!result.passed && changed.every(id => failed.has(id)), 'A browser following "' + name + '" must fail on ' + changed.filter(id => !failed.has(id)).join(', ') + ' in ' + browser);
    ++effective; ++executions;
  }
  if (effective === 0) withoutEffect.push(name); else ++negatives;
}
assert.deepEqual(withoutEffect, ['small-caps label ignored', 'weight before variant', 'OpenType small capitals']);

// A binding that can no longer tell the norm from a rival rule is refused, whatever a browser shows.
{
  const original = structuredClone(context().bindings), files = Object.fromEntries(Object.entries(context().files).map(([key, entry]) => [key, entry.file]));
  const face = (bindings, family, label) => bindings.families[family].faces.find(item => Object.entries(label).every(([key, value]) => (item[key] ?? false) === value));
  const without = (bindings, family, label) => bindings.families[family].faces.splice(bindings.families[family].faces.indexOf(face(bindings, family, label)), 1);
  for (const [description, weaken, message] of [
    ['Ladder 100 drawn by the program of 400', bindings => { face(bindings, 'Ladder', { weight: 100 }).file = files[REGULAR]; }, /nearest weight/],
    ['Ladder oblique 600 drawn by the program of 300', bindings => { face(bindings, 'Ladder', { weight: 600, style: 'oblique' }).file = files[ITALIC]; }, /separate the norm/],
    ['Scale 900 drawn by the program of 700', bindings => { face(bindings, 'Scale', { weight: 900 }).file = files[BOLD]; }, /Scale must draw/],
    ['Scale without its 500 face', bindings => without(bindings, 'Scale', { weight: 500 }), /Scale must draw/],
    ['Slant oblique face drawn by the regular program', bindings => { face(bindings, 'Slant', { style: 'oblique' }).file = files[REGULAR]; }, /separate the norm/],
    ['Engraved small-caps label on the regular program', bindings => { face(bindings, 'Engraved', { smallCaps: true }).file = files[REGULAR]; }, /font-variant ignored/],
    ['Engraved without a small-caps label', bindings => without(bindings, 'Engraved', { smallCaps: true }), /separate the norm/],
    ['Generic serif bound to the default program', bindings => { for (const item of bindings.generic.serif.faces) item.file = files[NOTO]; }, /generic families without binding/],
    ['Reference family bound to another program', bindings => { bindings.families['Reference Lato Bold'].faces[0].file = files[REGULAR]; }, /reference family/],
    ['Default family with a second face', bindings => bindings.default.faces.push({ file: files[REGULAR], weight: 700, style: 'normal' }), /default family/],
    ['Second family of the fallback bound to the first program', bindings => { bindings.families['Noto Sans'].faces[0].file = files[REGULAR]; }, /separate the norm|No program/]]) {
    const bindings = structuredClone(original);
    weaken(bindings);
    rebind(bindings);
    assert.throws(design, message, description);
    ++negatives; ++executions;
  }
  rebind();
  assert.deepEqual(context().bindings, original);
  assert.deepEqual(Object.keys(design().separations), Object.keys(RIVALS));
  ++positives;
}

console.log(JSON.stringify({ status: 'pass', positiveControls: positives, negativeControls: negatives, executions }));
