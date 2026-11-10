'use strict';

// Independent browser observations of the CSS1 font witness, with the font bytes and labels of fonts.json.
// Expected values come from CSS1 5.2, CSS 2.1 15 and the cmap, hmtx, hhea, head and GSUB tables read below; nothing
// is taken from the converter. Usage: node tools/validate_fonts.cjs OUTPUT_DIRECTORY
const assert = require('node:assert/strict');
const crypto = require('node:crypto');
const fs = require('node:fs');
const path = require('node:path');

const ROOT = path.resolve(__dirname, '..');
const SOURCE = 'examples/sources/fonts.html';
const BINDINGS = 'examples/sources/fonts.json';
const ORIGIN = 'https://fonts-witness.invalid';
const hash = bytes => crypto.createHash('sha256').update(bytes).digest('hex');
const sum = values => values.reduce((total, value) => total + value, 0);

const TARGETS = ['engine', 'chromium', 'firefox'];
const WEIGHTS = [100, 200, 300, 400, 500, 600, 700, 800, 900];
const GENERIC = ['serif', 'sans-serif', 'cursive', 'fantasy', 'monospace'];
const SLANTS = ['normal', 'italic', 'oblique'];
// CSS1 5.2.6 leaves the table to the user agent; both browsers and the converter use these pixel values.
const ABSOLUTE_PX = { 'xx-small': 9, 'x-small': 10, small: 13, medium: 16, large: 18, 'x-large': 24, 'xx-large': 32 };
const RELATIVE_RATIO = 1.2;
// CSS 2.1 15.6: inherited weight to bolder / lighter.
const BOLDER = { 100: 400, 200: 400, 300: 400, 400: 700, 500: 700, 600: 900, 700: 900, 800: 900, 900: 900 };
const LIGHTER = { 100: 100, 200: 100, 300: 100, 400: 100, 500: 100, 600: 400, 700: 400, 800: 700, 900: 700 };
const SMALL_CAPS_SCALE = { engine: 0.8, chromium: 0.7, firefox: 0.8 };
const WORD = 'minimum';
const [REGULAR, BOLD, ITALIC, BOLD_ITALIC, NOTO] = ['Lato-Regular', 'Lato-Bold', 'Lato-Italic', 'Lato-BoldItalic', 'NotoSans-Regular'];
// One-face families: the same word in each supplied program, on the page itself.
const REFERENCES = { 'reference-lato-regular': ['Reference Lato Regular', REGULAR], 'reference-lato-bold': ['Reference Lato Bold', BOLD],
  'reference-lato-italic': ['Reference Lato Italic', ITALIC], 'reference-lato-bold-italic': ['Reference Lato Bold Italic', BOLD_ITALIC],
  'reference-noto-sans': ['Reference Noto Sans', NOTO] };
// normalLineHeightPx: browsers round the ascent and the descent of a font to whole pixels before adding them.
const TOLERANCE = { computedPx: 0.005, lineHeightPx: 0.01, normalLineHeightPx: 1, widthPx: 0.12, xPx: 0.3, smallCapsScale: 0.004 };
const BODY_FAMILIES = ['Lato', 'Noto Sans'];
const CONTENT_LEFT_PX = 48;
const CONTENT_WIDTH_PX = 697.700787401;
// Parent of the font shorthand examples: italic small-caps bold 25px/50px Ladder.
const INHERITED = { style: 'italic', variant: 'small-caps', weight: 700, sizePx: 25, lineHeight: 50 };
// Weights the five programs bound to Scale can tell apart: each result of the CSS 2.1 15.6 chart has its own program.
const SCALE_CLASSES = [[100, 200, 300], [400], [500], [600, 700], [800, 900]];
const KNOWN_DEVIATIONS = {
  'relative-size-ratio': 'larger and smaller use the ratio 1.2 in the converter and in both browsers, so larger from medium is 19.2px and not large (18px): the CSS1 5.2.6 example is not reproduced.',
  'chromium-small-caps-scale': 'Chromium synthesizes small capitals at 0.7 of the font size; Firefox and the converter use 0.8.',
  'chromium-slanted-faces-merged': 'Chromium matches faces declared italic and faces declared oblique as one set, so an oblique request can be answered by a face labelled italic; Firefox and the converter keep the two labels apart (CSS1 5.2.1, CSS 2.1 15.2).',
  'style-family-skip': 'CSS1 5.2.1 and CSS 2.1 15.2 skip a family that has no face of the requested style, and so does the converter; both browsers stay in the first family (Chromium answers oblique with its italic face, Firefox with its upright face, both answer italic with the only upright face).',
  'labelled-small-caps-face': 'CSS1 5.2.1 lets a font labelled as a small-caps font answer font-variant: small-caps, and the converter draws such a face as written. @font-face has no such label: both browsers only see the other faces of the family and synthesize from them.',
  'opentype-small-caps-feature': 'With a font that has the OpenType smcp feature, both browsers draw its small-capital glyphs at the full size; the converter synthesizes small capitals from the capitals at 0.8 for every face that is not labelled.',
  'normal-line-height': 'line-height: normal, restored by the font shorthand, is a value of the user agent (CSS1 5.4.8): both browsers add the hhea ascent, descent and line gap of the font, rounded to pixels, and the converter uses its own value.',
};

const below = limit => WEIGHTS.filter(weight => weight < limit).reverse(), above = limit => WEIGHTS.filter(weight => weight > limit);
// CSS 2.1 15.6: order in which a missing weight is replaced. It never fails.
function weightOrder(desired) {
  if (desired === 400) return [400, 500, ...below(400), ...above(500)];
  if (desired === 500) return [500, 400, ...below(400), ...above(500)];
  return desired < 400 ? [desired, ...below(desired), ...above(desired)] : [desired, ...above(desired), ...below(desired)];
}

// What CSS1 5.2 and CSS 2.1 15 require and the converter documents. styles: labels accepted for a requested style, in
// order, a family without any of them being skipped. Browsers and rival rules replace some entries.
const NORM = { absolute: ABSOLUTE_PX, larger: px => px * RELATIVE_RATIO, smaller: px => px / RELATIVE_RATIO, bolder: BOLDER, lighter: LIGHTER, weightOrder,
  styles: { normal: [['normal']], italic: [['italic'], ['oblique']], oblique: [['oblique']] },
  genericFamilies: true, skipUnbound: true, smallCaps: true, labelledSmallCaps: true, variantBeforeWeight: true, smallCapsScale: SMALL_CAPS_SCALE.engine, smallCapsFeature: false, fallbackUnit: 'cluster', shorthandResets: true };
// Measured browser behaviour where it departs from CSS1: a style never fails, Chromium merges the italic and oblique
// labels, a small-caps label does not exist and OpenType small capitals come before the synthesis.
const RULES = { engine: NORM,
  chromium: { ...NORM, styles: { normal: [['normal']], italic: [['italic', 'oblique'], ['normal']], oblique: [['italic', 'oblique'], ['normal']] },
    labelledSmallCaps: false, smallCapsScale: SMALL_CAPS_SCALE.chromium, smallCapsFeature: true },
  firefox: { ...NORM, styles: { normal: [['normal']], italic: [['italic'], ['oblique'], ['normal']], oblique: [['oblique'], ['normal'], ['italic']] },
    labelledSmallCaps: false, smallCapsScale: SMALL_CAPS_SCALE.firefox, smallCapsFeature: true } };

const tableStep = direction => px => {
  const sizes = Object.values(ABSOLUTE_PX);
  return (direction > 0 ? sizes.find(size => size > px + 1e-9) : sizes.findLast(size => size < px - 1e-9)) ?? px;
};
const lightWeight = desired => [desired, ...below(desired), ...above(desired)], boldWeight = desired => [desired, ...above(desired), ...below(desired)];
// Plausible wrong rules. The witness must tell each of them from the norm on the listed samples: design() verifies it
// from the labels and the font tables, and the negative controls feed the comparison what such a rule would draw.
const RIVALS = {
  'nearest weight': { rules: { weightOrder: desired => [...WEIGHTS].sort((left, right) => Math.abs(left - desired) - Math.abs(right - desired) || left - right) },
    separates: ['match-ladder-300', 'match-ladder-600', 'match-ladder-oblique-500'] },
  'bold threshold at 600': { rules: { weightOrder: desired => desired >= 600 ? [700, 800, 900, 600, 500, 400, 300, 200, 100] : [400, 300, 200, 100, 500, 600, 700, 800, 900] },
    separates: ['match-ladder-100', 'match-ladder-300', 'lighter-from-400', 'bolder-from-900'] },
  'lighter faces first': { rules: { weightOrder: lightWeight }, separates: ['match-ladder-600', 'match-ladder-italic-400', 'match-lato-600'] },
  'darker faces first': { rules: { weightOrder: boldWeight }, separates: ['match-ladder-200', 'match-ladder-300', 'match-ladder-500', 'match-lato-500'] },
  '400 and 500 without their own rule': { rules: { weightOrder: desired => desired <= 500 ? lightWeight(desired) : boldWeight(desired) }, separates: ['match-ladder-italic-400'] },
  '500 as a bold weight': { rules: { weightOrder: desired => desired < 500 ? weightOrder(desired) : boldWeight(desired) }, separates: ['match-ladder-500', 'match-lato-500', 'match-ladder-oblique-500'] },
  '400 then 500 then darker': { rules: { weightOrder: desired => desired === 400 ? [400, 500, ...above(500), ...below(400)] : weightOrder(desired) }, separates: ['match-ladder-oblique-400'] },
  '500 then 400 then darker': { rules: { weightOrder: desired => desired === 500 ? [500, 400, ...above(500), ...below(400)] : weightOrder(desired) }, separates: ['match-ladder-oblique-500'] },
  'bolder one step': { rules: { bolder: Object.fromEntries(WEIGHTS.map(weight => [weight, Math.min(900, weight + 100)])) }, separates: ['bolder-from-100', 'bolder-from-400', 'bolder-from-600', 'strong', 'b-in-strong'] },
  'lighter one step': { rules: { lighter: Object.fromEntries(WEIGHTS.map(weight => [weight, Math.max(100, weight - 100)])) }, separates: ['lighter-from-500', 'lighter-from-600', 'lighter-from-700', 'lighter-from-900'] },
  'bolder as bold': { rules: { bolder: Object.fromEntries(WEIGHTS.map(weight => [weight, 700])) }, separates: ['bolder-from-100', 'bolder-from-600', 'bolder-from-900', 'b-in-strong', 'b-from-300'] },
  'lighter as normal': { rules: { lighter: Object.fromEntries(WEIGHTS.map(weight => [weight, 400])) }, separates: ['lighter-from-100', 'lighter-from-400', 'lighter-from-800'] },
  'bolder stops at 700': { rules: { bolder: { ...BOLDER, 600: 700, 700: 700 } }, separates: ['bolder-from-600', 'bolder-from-700', 'b-in-strong', 'b-in-strong-in-b-from-300'] },
  'lighter stops at 400': { rules: { lighter: { ...LIGHTER, 100: 100, 200: 100, 300: 100, 400: 400, 500: 400 } }, separates: ['lighter-from-400', 'lighter-from-500'] },
  'italic refuses oblique faces': { rules: { styles: { ...NORM.styles, italic: [['italic']] } }, separates: ['style-italic-from-oblique-face'] },
  'italic prefers oblique faces': { rules: { styles: { ...NORM.styles, italic: [['oblique'], ['italic']] } }, separates: ['match-ladder-italic-400', 'match-ladder-italic-500'] },
  'oblique accepts italic faces': { rules: { styles: { ...NORM.styles, oblique: [['oblique'], ['italic']] } }, separates: ['oblique-skips-italic'] },
  'italic and oblique as one label': { rules: { styles: { ...NORM.styles, italic: [['italic', 'oblique']], oblique: [['italic', 'oblique']] } },
    separates: ['match-ladder-oblique-400', 'match-ladder-oblique-500', 'oblique-skips-italic'] },
  'style never skips a family': { rules: { styles: RULES.firefox.styles }, separates: ['oblique-skips-italic', 'italic-skips-upright'] },
  'generic families without binding': { rules: { genericFamilies: false }, separates: ['font-3', 'font-4', 'font-5'] },
  'unbound name ending the list': { rules: { skipUnbound: false }, separates: ['font-3', 'font-4'] },
  'font-variant ignored': { rules: { smallCaps: false }, separates: ['caps-lowercase', 'caps-mixed', 'caps-punctuation', 'caps-sharp-s', 'caps-bold', 'caps-inherited', 'caps-labelled', 'caps-labelled-bold', 'caps-feature', 'font-5'] },
  'small capitals at 0.7': { rules: { smallCapsScale: 0.7 }, separates: ['caps-lowercase', 'caps-mixed', 'caps-punctuation', 'caps-sharp-s', 'caps-bold', 'caps-inherited', 'caps-feature', 'font-5', 'font-5-again'] },
  'small capitals as full capitals': { rules: { smallCapsScale: 1 }, separates: ['caps-lowercase', 'caps-mixed', 'caps-punctuation', 'caps-sharp-s', 'caps-bold', 'caps-inherited', 'caps-feature', 'font-5'] },
  'small-caps label ignored': { rules: { labelledSmallCaps: false }, separates: ['caps-labelled', 'caps-labelled-bold'] },
  'weight before variant': { rules: { variantBeforeWeight: false }, separates: ['caps-labelled-bold'] },
  'OpenType small capitals': { rules: { smallCapsFeature: true }, separates: ['caps-feature'] },
  'fallback by code point': { rules: { fallbackUnit: 'codepoint' }, separates: ['fallback-cluster'] },
  'fallback by sample': { rules: { fallbackUnit: 'sample' }, separates: ['fallback-lato-noto', 'fallback-noto-lato', 'fallback-cluster', 'fallback-character-default'] },
  'relative sizes by table step': { rules: { larger: tableStep(1), smaller: tableStep(-1) },
    separates: ['larger-from-medium', 'smaller-from-medium', 'larger-from-x-large', 'smaller-from-x-large', 'larger-from-12', 'smaller-from-30', 'inherited-larger', 'nested-larger', 'smaller-from-small', 'indent-larger'] },
  'relative sizes by 1.5': { rules: { larger: px => px * 1.5, smaller: px => px / 1.5 }, separates: ['larger-from-medium', 'smaller-from-medium', 'larger-from-30', 'nested-larger', 'smaller-from-small', 'indent-larger'] },
  'CSS1 scaling factor 1.5': { rules: { absolute: Object.fromEntries(Object.keys(ABSOLUTE_PX).map((keyword, index) => [keyword, 16 * 1.5 ** (index - 3)])) },
    separates: ['absolute-12-xx-small', 'absolute-12-x-small', 'absolute-12-small', 'absolute-30-large', 'absolute-30-x-large', 'absolute-30-xx-large', 'inherited-x-large', 'indent-x-large', 'font-3', 'font-4'] },
  'shorthand keeping omitted values': { rules: { shorthandResets: false }, separates: ['font-1', 'font-2', 'font-3', 'font-4', 'font-5', 'row-font-2-again', 'row-font-4-again'] },
};

// Minimal TrueType reader: unitsPerEm (head), line metrics (hhea), advances (hmtx), the Unicode BMP map (cmap format 4)
// and the single substitutions of the OpenType smcp feature (GSUB).
function smallCapitalGlyphs(gsub) {
  const substitutes = new Map(), lookups = new Set();
  if (!gsub) return substitutes;
  const features = gsub.readUInt16BE(6), lookupList = gsub.readUInt16BE(8);
  for (let index = 0; index < gsub.readUInt16BE(features); ++index) {
    const record = features + 2 + 6 * index, feature = features + gsub.readUInt16BE(record + 4);
    if (gsub.toString('latin1', record, record + 4) !== 'smcp') continue;
    for (let item = 0; item < gsub.readUInt16BE(feature + 2); ++item) lookups.add(gsub.readUInt16BE(feature + 4 + 2 * item));
  }
  for (const index of lookups) {
    const lookup = lookupList + gsub.readUInt16BE(lookupList + 2 + 2 * index);
    for (let item = 0; item < gsub.readUInt16BE(lookup + 4); ++item) {
      let table = lookup + gsub.readUInt16BE(lookup + 6 + 2 * item), type = gsub.readUInt16BE(lookup);
      if (type === 7) { type = gsub.readUInt16BE(table + 2); table += gsub.readUInt32BE(table + 4); }
      assert.equal(type, 1, 'The smcp feature is read as single substitutions only');
      const format = gsub.readUInt16BE(table), coverage = table + gsub.readUInt16BE(table + 2), covered = [];
      if (gsub.readUInt16BE(coverage) === 1) {
        for (let glyph = 0; glyph < gsub.readUInt16BE(coverage + 2); ++glyph) covered.push(gsub.readUInt16BE(coverage + 4 + 2 * glyph));
      } else {
        for (let range = coverage + 4; range < coverage + 4 + 6 * gsub.readUInt16BE(coverage + 2); range += 6) {
          for (let glyph = gsub.readUInt16BE(range); glyph <= gsub.readUInt16BE(range + 2); ++glyph) covered.push(glyph);
        }
      }
      covered.forEach((glyph, order) => substitutes.set(glyph, format === 1 ? (glyph + gsub.readUInt16BE(table + 4)) & 0xffff : gsub.readUInt16BE(table + 6 + 2 * order)));
    }
  }
  return substitutes;
}

function readProgram(bytes) {
  assert(bytes.length >= 12 && bytes.length <= 16_777_216, 'Bounded font program required');
  const count = bytes.readUInt16BE(4), tables = {};
  assert(count >= 1 && count <= 256 && 12 + count * 16 <= bytes.length, 'Malformed table directory');
  for (let entry = 12; entry < 12 + count * 16; entry += 16) {
    const tag = bytes.toString('latin1', entry, entry + 4), offset = bytes.readUInt32BE(entry + 8), length = bytes.readUInt32BE(entry + 12);
    assert(!(tag in tables) && offset + length <= bytes.length, 'Malformed table directory');
    tables[tag] = bytes.subarray(offset, offset + length);
  }
  for (const tag of ['head', 'hhea', 'hmtx', 'cmap']) assert(tables[tag], 'Missing table ' + tag);
  const unitsPerEm = tables.head.readUInt16BE(18), metrics = tables.hhea.readUInt16BE(34), cmap = tables.cmap;
  assert(unitsPerEm > 0 && metrics >= 1 && tables.hmtx.length >= metrics * 4, 'Malformed horizontal metrics');
  let subtable = null;
  for (let record = 4; record < 4 + cmap.readUInt16BE(2) * 8; record += 8) {
    const platform = cmap.readUInt16BE(record), encoding = cmap.readUInt16BE(record + 2), offset = cmap.readUInt32BE(record + 4);
    if (((platform === 3 && encoding === 1) || platform === 0) && cmap.readUInt16BE(offset) === 4) subtable = offset;
  }
  assert(subtable !== null, 'A Unicode cmap format 4 subtable is required');
  const glyphs = new Map(), doubled = cmap.readUInt16BE(subtable + 6);
  const ends = subtable + 14, starts = ends + doubled + 2, deltas = starts + doubled, ranges = deltas + doubled;
  for (let segment = 0; segment < doubled; segment += 2) {
    const end = cmap.readUInt16BE(ends + segment), start = cmap.readUInt16BE(starts + segment);
    const delta = cmap.readUInt16BE(deltas + segment), range = cmap.readUInt16BE(ranges + segment);
    for (let code = start; code <= end && code < 0xffff; ++code) {
      let glyph = range === 0 ? code : cmap.readUInt16BE(ranges + segment + range + 2 * (code - start));
      if (glyph !== 0 || range === 0) glyph = (glyph + delta) & 0xffff;
      if (glyph !== 0) glyphs.set(code, glyph);
    }
  }
  const substitutes = smallCapitalGlyphs(tables.GSUB), advanceOfGlyph = glyph => tables.hmtx.readUInt16BE(4 * Math.min(glyph, metrics - 1)) / unitsPerEm;
  return { unitsPerEm, smallCapitals: substitutes.size, has: code => glyphs.has(code),
    // hhea ascender, descender and lineGap, per em.
    lineSpacing: (tables.hhea.readInt16BE(4) - tables.hhea.readInt16BE(6) + tables.hhea.readInt16BE(8)) / unitsPerEm,
    advance(code) {
      assert(glyphs.has(code), 'No glyph for U+' + code.toString(16).toUpperCase().padStart(4, '0'));
      return advanceOfGlyph(glyphs.get(code));
    },
    // Advance of the small capital the smcp feature substitutes for a character, or null without a substitution.
    smallCapitalAdvance: code => substitutes.has(glyphs.get(code)) ? advanceOfGlyph(substitutes.get(glyphs.get(code))) : null };
}

const faceKey = file => path.basename(file, '.ttf');
const cachedExpectations = new Map();

// Explicit bindings: every family is a list of labelled faces; one file may carry several labels.
function loadContext() {
  const bindingBytes = fs.readFileSync(path.join(ROOT, BINDINGS));
  assert(bindingBytes.length < 100_000, 'Bounded bindings required');
  const bindings = JSON.parse(bindingBytes.toString('utf8'));
  assert.deepEqual(Object.keys(bindings).sort(), ['default', 'families', 'generic'], 'Bindings declare default, families and generic');
  const groups = [{ kind: 'default', name: null, faces: bindings.default.faces },
    ...Object.entries(bindings.families).map(([name, family]) => ({ kind: 'family', name, faces: family.faces })),
    ...Object.entries(bindings.generic).map(([name, family]) => ({ kind: 'generic', name, faces: family.faces }))];
  const files = {}, programs = {};
  for (const group of groups) {
    assert(group.kind !== 'generic' || GENERIC.includes(group.name), 'Unknown generic family ' + group.name);
    const labels = group.faces.map(face => {
      assert(typeof face.file === 'string' && /^[A-Za-z0-9/_.-]+\.ttf$/.test(face.file) && !face.file.includes('..'), 'Repository-relative TrueType path required');
      assert(WEIGHTS.includes(face.weight) && SLANTS.includes(face.style), 'Faces are labelled 100 to 900 and normal, italic or oblique');
      assert(face.smallCaps === undefined || typeof face.smallCaps === 'boolean', 'smallCaps is a boolean label');
      return face.style + ':' + (face.smallCaps ? 'small-caps' : 'normal') + ':' + face.weight;
    });
    assert(new Set(labels).size === labels.length && labels.includes('normal:normal:400'), 'Distinct labels and a normal 400 face are required');
    for (const face of group.faces) {
      const key = faceKey(face.file);
      assert(!(key in files) || files[key].file === face.file, 'Face keys must identify one file');
      if (!(key in files)) {
        const bytes = fs.readFileSync(path.join(ROOT, face.file));
        files[key] = { file: face.file, bytes, sha256: hash(bytes) };
        programs[key] = readProgram(bytes);
      }
    }
  }
  return { bindings, bindingBytes, groups, files, programs };
}
let cachedContext = null;
const context = () => cachedContext ??= loadContext();
// Negative controls only: the same programs under other bindings, or the bindings of fonts.json again.
function rebind(bindings = JSON.parse(context().bindingBytes.toString('utf8'))) {
  context().bindings = bindings;
  cachedExpectations.clear();
}

// The witness inventory: rows (blocks) and their samples, in document order, with the computed values of one rule set.
function buildModel(rules) {
  const rows = [], { absolute, larger, smaller } = rules, bolder = weight => rules.bolder[weight];
  const sample = (id, requirement, text, sizePx, extra = {}) => ({ id, requirement, text, sizePx, weight: 400, style: 'normal', variant: 'normal', families: BODY_FAMILIES, ...extra });
  // Samples of a row are separated by one space of the row's own font: Lato Regular at the row size.
  const row = (id, sizePx, samples, extra = {}) => rows.push({ id, sizePx, indentPx: 0, samples, ...extra });
  const word = (id, requirement, extra = {}, sizePx = 14) => sample(id, requirement, WORD, sizePx, extra);
  const digits = (id, requirement, sizePx, text) => sample(id, requirement, text, sizePx);

  row('row-reference', 16, Object.entries(REFERENCES).map(([id, [family]]) => word(id, 'E1', { families: [family] }, 16)));
  // The text of a size sample is its size in pixels as CSS1 and the measurements give it, whatever the rules compute.
  for (const parent of [12, 30]) {
    row('row-absolute-' + parent, parent, Object.keys(ABSOLUTE_PX).map(keyword => digits('absolute-' + parent + '-' + keyword, 'E22 M1', absolute[keyword], String(ABSOLUTE_PX[keyword]))));
  }
  row('row-relative', 14, [['medium', absolute.medium, '13.3', '19.2'], ['x-large', absolute['x-large'], '20', '28.8'], ['12', 12, '10', '14.4'], ['30', 30, '25', '36']].flatMap(([parent, px, less, more]) => [
    digits('smaller-from-' + parent, 'E23 M2', smaller(px), less), digits('larger-from-' + parent, 'E23 M2', larger(px), more)]));
  row('row-inheritance', 14, [digits('inherited-larger', 'E21', larger(14), '16.8'), digits('nested-larger', 'E21 E23', larger(larger(14)), '20.16'),
    digits('inherited-x-large', 'E21', absolute['x-large'], '24'), digits('percentage-of-x-large', 'E21 E24', 0.5 * absolute['x-large'], '12'),
    digits('em-of-x-large', 'E25', 0.75 * absolute['x-large'], '18'), digits('smaller-from-small', 'E23 M2', smaller(absolute.small), '10.83')]);
  row('row-indent-x-large', absolute['x-large'], [digits('indent-x-large', 'E9', absolute['x-large'], '48')], { indentPx: 2 * absolute['x-large'] });
  row('row-indent-larger', larger(14), [digits('indent-larger', 'E9', larger(14), '84')], { indentPx: 5 * larger(14) });

  // Relative weights are set in Scale, where 100, 400, 500, 700 and 900 are five different programs.
  const scale = weight => ({ weight, families: ['Scale'] });
  for (const [name, table] of [['bolder', rules.bolder], ['lighter', rules.lighter]]) {
    row('row-' + name, 14, WEIGHTS.map(from => word(name + '-from-' + from, 'E17 E19 M3', scale(table[from]))));
  }
  row('row-nesting', 14, [word('strong', 'E19', scale(bolder(400))), word('b-in-strong', 'E19', scale(bolder(bolder(400)))), word('b-from-300', 'E19', scale(bolder(300))),
    word('strong-in-b-from-300', 'E19', scale(bolder(bolder(300)))), word('b-in-strong-in-b-from-300', 'E19', scale(bolder(bolder(bolder(300))))),
    word('inherited-bolder', 'E17', scale(bolder(400))), word('keyword-bold', 'E17', scale(700)), word('keyword-normal', 'E17', scale(400))]);

  const matching = (prefix, families, style, weights) => weights.map(weight => word(prefix + '-' + weight, 'E6 E8 E18 M4', { weight, style, families }));
  row('row-match-lato', 14, matching('match-lato', BODY_FAMILIES, 'normal', WEIGHTS));
  row('row-match-ladder', 14, matching('match-ladder', ['Ladder'], 'normal', WEIGHTS));
  row('row-match-ladder-italic', 14, matching('match-ladder-italic', ['Ladder'], 'italic', WEIGHTS.slice(0, 5)));
  row('row-match-ladder-oblique', 14, matching('match-ladder-oblique', ['Ladder'], 'oblique', WEIGHTS));
  row('row-match-single', 14, [word('match-single-900', 'E8', { weight: 900, families: ['Reference Noto Sans'] })]);

  row('row-style', 14, [word('style-i', 'E12 E13', { style: 'italic' }), word('style-em', 'E12 E13', { style: 'italic' }),
    word('style-i-in-b', 'E2 E13', { style: 'italic', weight: bolder(400) }), word('style-normal-in-i', 'E12'),
    word('style-italic-from-oblique-face', 'E6 E13', { style: 'italic', families: ['Slant'] }), word('style-oblique', 'E6 E13 M5', { style: 'oblique', families: ['Slant'] })]);
  row('row-oblique-skips-italic', 14, [word('oblique-skips-italic', 'E3 E6', { style: 'oblique', families: ['Lato', 'Slant'] })]);
  row('row-italic-skips-upright', 14, [word('italic-skips-upright', 'E3 E6', { style: 'italic', families: ['Noto Sans', 'Lato'] })]);

  // Small capitals are measured at 20px and 30px: Chromium rounds the reduced size to a whole pixel, exactly 0.7 there.
  const caps = { variant: 'small-caps' };
  row('row-small-caps', 20, [sample('caps-lowercase', 'E7 E15 M7', WORD, 20, caps), sample('caps-mixed', 'E7 E15 M7', 'Nominal', 20, caps),
    sample('caps-digits', 'E15 M7', '2008', 20, caps), sample('caps-punctuation', 'E15', 'No.3', 20, caps), sample('caps-sharp-s', 'E16 M7', 'ma\u00DF', 20, caps),
    sample('caps-bold', 'E2 E7', WORD, 20, { ...caps, weight: bolder(400) }), sample('caps-inherited', 'E14', 'nominal', 20, caps), sample('caps-restored', 'E14', WORD, 20)]);
  row('row-small-caps-faces', 20, [sample('caps-labelled', 'E2 E7', WORD, 20, { ...caps, families: ['Engraved'] }),
    sample('caps-labelled-bold', 'E2 E7 E8', WORD, 20, { ...caps, weight: bolder(400), families: ['Engraved'] }),
    sample('caps-labelled-normal', 'E7', WORD, 20, { families: ['Engraved'] }), sample('caps-feature', 'E7 E15 M6', WORD, 20, { ...caps, families: ['Noto Sans'] })]);
  row('row-fallback', 20, [sample('fallback-lato-noto', 'E2 E4 M8', 'n\u211Dn\u0377n', 20, { families: ['Lato', 'Noto Sans'] }),
    sample('fallback-noto-lato', 'E2 E4 E11 M8', 'n\u2192n\u25CBn\u25AAn', 20, { families: ['Noto Sans', 'Lato'] }),
    sample('fallback-cluster', 'E2 E4', 'n\u2192\u0301n', 20, { families: ['Noto Sans', 'Lato'] }),
    sample('fallback-default', 'E5', WORD, 20, { families: ['Unlisted Grotesk', { generic: 'cursive' }] }),
    sample('fallback-character-default', 'E4 E5', 'n\u211Dn', 20, { families: ['Lato'] }),
    sample('fallback-default-style', 'E5 E8', WORD, 20, { style: 'italic', weight: 700, families: ['Unlisted Grotesk'] })]);

  // CSS1 5.2.7 examples under a parent set to italic small-caps bold 25px/50px. Each example is set twice: the second
  // block starts one line height below the first.
  const shorthand = (number, declared) => {
    const { sizePx, lineHeight, ...computed } = { ...(rules.shorthandResets ? { style: 'normal', variant: 'normal', weight: 400, lineHeight: 'normal' } : INHERITED), ...declared };
    for (const suffix of ['', '-again']) {
      row('row-font-' + number + suffix, sizePx, [sample('font-' + number + suffix, 'E26 E27 M10', WORD, sizePx, computed)], { lineHeight, ...(suffix ? { repeats: 'row-font-' + number } : {}) });
    }
  };
  shorthand(1, { sizePx: 12 / 0.75, lineHeight: 14 / 0.75, families: [{ generic: 'sans-serif' }] });
  shorthand(2, { sizePx: 0.8 * INHERITED.sizePx, families: [{ generic: 'sans-serif' }] });
  shorthand(3, { sizePx: absolute['x-large'], lineHeight: 1.1 * absolute['x-large'], families: ['new century schoolbook', { generic: 'serif' }] });
  shorthand(4, { weight: 700, style: 'italic', sizePx: absolute.large, families: ['Palatino', { generic: 'serif' }] });
  shorthand(5, { variant: 'small-caps', sizePx: 1.2 * INHERITED.sizePx, lineHeight: 1.2 * (1.2 * INHERITED.sizePx), families: [{ generic: 'fantasy' }] });

  const ids = rows.flatMap(entry => [entry.id, ...entry.samples.map(item => item.id)]);
  assert(new Set(ids).size === ids.length, 'Unique identifiers required');
  return rows;
}

// CSS1 5.2.1 inside one family: the style first, then the variant, then the weight, which never fails. null: the family
// has no face of the style and is skipped. A face labelled as a small-caps font is drawn as written.
function familyFace(faces, request, rules) {
  const capitals = request.variant === 'small-caps' && rules.smallCaps;
  for (const labels of rules.styles[request.style]) {
    const styled = faces.filter(face => labels.includes(face.style) && (rules.labelledSmallCaps || !face.smallCaps));
    const labelled = styled.filter(face => face.smallCaps), plain = styled.filter(face => !face.smallCaps);
    let pool = capitals && labelled.length > 0 ? labelled : plain;
    if (!rules.variantBeforeWeight) {
      const weight = rules.weightOrder(request.weight).find(candidate => styled.some(face => face.weight === candidate));
      pool = capitals && labelled.some(face => face.weight === weight) ? labelled : plain;
    }
    const weight = rules.weightOrder(request.weight).find(candidate => pool.some(face => face.weight === candidate));
    const chosen = pool.filter(face => face.weight === weight);
    if (chosen.length === 0) continue;
    assert(new Set(chosen.map(face => faceKey(face.file))).size === 1, 'Labels matched as one must share a program at each weight');
    return { face: faceKey(chosen[0].file), label: { style: chosen[0].style, weight, smallCaps: Boolean(chosen[0].smallCaps) } };
  }
  return null;
}

// Faces tried in order for every character: one per listed family that has the style, then the default family, which
// cannot fail and supplies the best match it has. Names without a binding are ignored.
function chain(item, rules) {
  const { bindings } = context(), elements = [];
  for (const [index, name] of item.families.entries()) {
    const family = typeof name === 'string' ? bindings.families[name] : rules.genericFamilies ? bindings.generic[name.generic] : undefined;
    if (!family && !rules.skipUnbound) break;
    const matched = family ? familyFace(family.faces, item, rules) : null;
    if (matched) elements.push({ ...matched, family: index });
  }
  const last = familyFace(bindings.default.faces, item, rules) ?? familyFace(bindings.default.faces, { ...item, style: 'normal' }, rules);
  return [...elements, { ...last, family: null }];
}

// The informative diagnostics the converter documents for a sample: the default family because no listed family
// supplied a face, a default face of another style, and a face across the bold threshold (600) from the request.
function diagnostics(item, elements, used, rules) {
  const notes = [];
  if (elements.length === 1) notes.push(['font.default-family', JSON.stringify(item.families) + ' ' + item.style]);
  for (const element of used) {
    const label = element.label.style + (element.label.smallCaps ? ' small-caps ' : ' ') + element.label.weight;
    if (element.family === null && !rules.styles[item.style].some(labels => labels.includes(element.label.style))) notes.push(['font.style-fallback', item.style + ' to ' + element.label.style]);
    if ((element.label.weight >= 600) !== (item.weight >= 600)) notes.push(['font.weight-fallback', item.weight + ' to ' + label]);
  }
  return [...new Map(notes.map(([code, circumstance]) => [code + ' ' + circumstance, { code, circumstance }])).values()];
}

// Text commands of a sample under one rule set: one segment per face and size. Small capitals are the face as written
// when it is labelled, its smcp glyphs when the rules use them, and otherwise its capitals at a reduced size.
// `only` draws the whole sample with one program and returns null when that program lacks a glyph.
function shape(item, rules, only = null) {
  const { programs } = context(), elements = chain(item, rules), segments = [], characters = [], used = new Set();
  const candidates = only === null ? elements : [{ ...elements[0], face: only }];
  const units = rules.fallbackUnit === 'cluster' ? item.text.match(/\P{M}\p{M}*/gu) : rules.fallbackUnit === 'codepoint' ? [...item.text] : [item.text];
  for (const source of units) {
    let character = null;
    for (const element of candidates) {
      const program = programs[element.face], capitals = item.variant === 'small-caps' && rules.smallCaps && !element.label.smallCaps;
      const codes = text => [...text].map(letter => letter.codePointAt(0));
      if (capitals && rules.smallCapsFeature && program.smallCapitals > 0) {
        if (!codes(source).every(program.has)) continue;
        character = { text: source, drawn: source, face: element.face, sizePx: item.sizePx, feature: true,
          widthPx: sum(codes(source).map(code => program.smallCapitalAdvance(code) ?? program.advance(code))) * item.sizePx };
      } else {
        const drawn = capitals ? source.toUpperCase() : source, sizePx = drawn === source ? item.sizePx : item.sizePx * rules.smallCapsScale;
        if (!codes(drawn).every(program.has)) continue;
        character = { text: source, drawn, face: element.face, sizePx, feature: false, widthPx: sum(codes(drawn).map(program.advance)) * sizePx };
      }
      used.add(element);
      break;
    }
    if (character === null && only !== null) return null;
    assert(character, 'No program in the chain draws ' + source);
    characters.push(character);
    const last = segments.at(-1);
    if (last && last.face === character.face && last.sizePx === character.sizePx && last.feature === character.feature) {
      Object.assign(last, { text: last.text + source, drawn: last.drawn + character.drawn, widthPx: last.widthPx + character.widthPx });
    } else segments.push({ ...character });
  }
  const faces = new Set(characters.map(letter => letter.face)), first = [...used][0];
  return { segments, characters, widthPx: sum(segments.map(segment => segment.widthPx)), face: faces.size === 1 ? characters[0].face : null,
    family: first.family, asWritten: item.variant === 'small-caps' && first.label.smallCaps, feature: characters.some(letter => letter.feature),
    diagnostics: diagnostics(item, elements, used, rules) };
}

const finite = value => typeof value === 'number' && Number.isFinite(value);
const near = (actual, expected, tolerance) => finite(actual) && Math.abs(actual - expected) <= tolerance;
// Whether a supplied program, drawing the whole sample under the same rules, explains a width.
const explains = (item, rules, key, widthPx, margin = 1) => near(shape(item, rules, key)?.widthPx, widthPx, margin * TOLERANCE.widthPx);
const drawing = item => item.segments.map(segment => [segment.text, segment.drawn, segment.face, segment.sizePx, segment.feature]);
const sameDrawing = (left, right) => JSON.stringify(drawing(left)) === JSON.stringify(drawing(right));

// Why a browser draws a sample differently from the converter.
function deviation(engine, browser) {
  if (sameDrawing(engine, browser)) return null;
  if (engine.asWritten) return 'labelled-small-caps-face';
  if (browser.feature) return 'opentype-small-caps-feature';
  if (engine.characters.length === browser.characters.length && engine.characters.every((letter, index) => letter.face === browser.characters[index].face)) return 'chromium-small-caps-scale';
  return engine.family === browser.family ? 'chromium-slanted-faces-merged' : 'style-family-skip';
}

// Expected inventory, computed values and geometry for the converter ('engine'), one browser or a rival rule set.
function expectations(target, rules = RULES[target]) {
  assert(TARGETS.includes(target) && rules, 'Unknown target ' + target);
  if (cachedExpectations.has(rules)) return cachedExpectations.get(rules);
  const { programs } = context(), order = [], rows = {}, samples = {};
  for (const { id, samples: members, ...geometry } of buildModel(rules)) {
    order.push(id);
    let xPx = geometry.indentPx;
    for (const item of members) {
      order.push(item.id);
      const shaped = shape(item, rules);
      // A sample identifies its face when no other supplied program gives the same width.
      const rivals = shaped.face === null ? [] : Object.keys(programs).filter(key => key !== shaped.face && explains(item, rules, key, shaped.widthPx, 4));
      samples[item.id] = { ...item, row: id, ...shaped, xPx, identifiesFace: shaped.face !== null && rivals.length === 0 };
      xPx += shaped.widthPx + programs[REGULAR].advance(0x20) * geometry.sizePx;
    }
    rows[id] = { ...geometry, samples: members.map(item => item.id), endPx: xPx - programs[REGULAR].advance(0x20) * geometry.sizePx };
  }
  if (target !== 'engine' && rules === RULES[target]) {
    const engine = expectations('engine');
    for (const [id, item] of Object.entries(samples)) item.deviation = deviation(engine.samples[id], item);
  }
  const result = { order, rows, samples };
  cachedExpectations.set(rules, result);
  return result;
}

// Design conditions of the witness itself, from the labels and the font tables: it must be able to tell the norm from
// every rival rule, and every known deviation must be visible. A weakened binding or document is refused here.
function design() {
  const { bindings, programs } = context(), engine = expectations('engine'), browsers = { chromium: expectations('chromium'), firefox: expectations('firefox') };
  const word = face => sum([...WORD].map(letter => programs[face].advance(letter.codePointAt(0))));
  const widths = Object.values(REFERENCES).map(([, face]) => word(face));
  const facts = { wordSeparationEm: Math.min(...widths.flatMap((width, index) => widths.slice(index + 1).map(other => Math.abs(width - other)))) };
  assert(facts.wordSeparationEm >= 0.05, 'The specimen word must have a distinct width in every program');
  assert.deepEqual(Object.keys(REFERENCES).map(id => engine.samples[id].face), Object.values(REFERENCES).map(([, face]) => face), 'Every reference family binds its own program');
  assert.deepEqual(Object.keys(programs).sort(), [REGULAR, BOLD, ITALIC, BOLD_ITALIC, NOTO].sort(), 'The five supplied programs');
  assert(bindings.default.faces.length === 1 && faceKey(bindings.default.faces[0].file) === NOTO, 'The default family binds the single Noto Sans face');

  // Characters of the fallback samples: each in one program only, the combining accent in both and without advance.
  facts.coverage = Object.fromEntries([0x211D, 0x0377, 0x2192, 0x25CB, 0x25AA, 0x0301].map(code => ['U+' + code.toString(16).toUpperCase().padStart(4, '0'), [REGULAR, NOTO].filter(face => programs[face].has(code))]));
  assert.deepEqual(facts.coverage, { 'U+211D': [NOTO], 'U+0377': [NOTO], 'U+2192': [REGULAR], 'U+25CB': [REGULAR], 'U+25AA': [REGULAR], 'U+0301': [REGULAR, NOTO] },
    'Fallback characters must each exist in one program only and the combining accent in both');
  assert([REGULAR, NOTO].every(face => programs[face].advance(0x0301) === 0), 'The combining accent has no advance');
  facts.smallCapitalSubstitutions = Object.fromEntries(Object.entries(programs).map(([face, program]) => [face, program.smallCapitals]));
  assert(programs[NOTO].smallCapitals > 0 && [...WORD].every(letter => programs[NOTO].smallCapitalAdvance(letter.codePointAt(0)) !== null), 'Noto Sans has an smcp small capital for every letter of the specimen word');
  assert([REGULAR, BOLD, ITALIC, BOLD_ITALIC].every(face => programs[face].smallCapitals === 0), 'Lato has no smcp feature: its small capitals are synthesized everywhere');

  // Scale: the programs separate the weights exactly as SCALE_CLASSES says.
  const scale = weight => familyFace(bindings.families.Scale.faces, { style: 'normal', variant: 'normal', weight }, NORM).face;
  facts.scaleClasses = Object.values(Object.groupBy(WEIGHTS, scale));
  assert.deepEqual(facts.scaleClasses, SCALE_CLASSES, 'Scale must draw 100, 400, 500, 700 and 900 with five different programs');

  // Every rival rule changes what the converter would draw for its samples, or where it would set a repeated block.
  facts.separations = {};
  for (const [name, rival] of Object.entries(RIVALS)) {
    const other = expectations('engine', { ...NORM, ...rival.rules });
    const block = row => JSON.stringify([row.sizePx, row.indentPx, row.lineHeight]);
    const differing = other.order.filter(id => id in other.rows ? block(other.rows[id]) !== block(engine.rows[id])
      : !sameDrawing(other.samples[id], engine.samples[id]));
    assert(rival.separates.length > 0 && rival.separates.every(id => differing.includes(id)), 'The witness must separate the norm from: ' + name);
    facts.separations[name] = differing.filter(id => !(id in other.rows) || rival.separates.includes(id));
  }

  // Every known deviation is carried by at least one sample, visibly.
  facts.deviations = {};
  for (const [browser, expected] of Object.entries(browsers)) {
    facts.deviations[browser] = Object.fromEntries(Object.entries(expected.samples).filter(([, item]) => item.deviation).map(([id, item]) => [id, item.deviation]));
    for (const id of Object.keys(facts.deviations[browser])) {
      assert(Math.abs(expected.samples[id].widthPx - engine.samples[id].widthPx) > 8 * TOLERANCE.widthPx, 'A deviation must change the width of ' + id);
    }
  }
  const named = new Set(['relative-size-ratio', 'normal-line-height', ...Object.values(facts.deviations).flatMap(Object.values)]);
  assert.deepEqual([...named].sort(), Object.keys(KNOWN_DEVIATIONS).sort(), 'Every known deviation must be carried by a sample, and nothing else');
  assert(Object.values(engine.rows).some(row => row.repeats && row.lineHeight === 'normal') && Object.values(engine.rows).some(row => row.repeats && row.lineHeight !== 'normal'),
    'The shorthand examples must cover explicit and normal line heights');
  assert(Object.values(engine.rows).every(row => row.lineHeight !== 'normal' || INHERITED.lineHeight > 2 * row.sizePx), 'The inherited line height must lie far from any normal one');

  // Every row fits on one line, and every intended diagnostic has one circumstance.
  for (const expected of [engine, ...Object.values(browsers)]) {
    assert(Object.values(expected.rows).every(row => row.endPx <= CONTENT_WIDTH_PX - 1), 'Every row must fit on one line');
  }
  const notes = Object.values(engine.samples).flatMap(item => item.diagnostics.map(note => note.code + ' ' + note.circumstance));
  assert(new Set(notes).size === notes.length, 'Every intended diagnostic must have its own circumstance');
  return facts;
}

// Compare one browser's observations with the norm-derived model. An incomplete inventory is an error, not a failure.
function compare(observed, browser) {
  assert(browser === 'chromium' || browser === 'firefox', 'Unknown browser ' + browser);
  const expected = expectations(browser), engine = expectations('engine'), rules = RULES[browser], { programs } = context();
  assert(observed && Array.isArray(observed.order) && observed.elements, 'Observations required');
  assert.deepEqual(observed.order, expected.order, 'Complete fixture inventory required');
  assert.deepEqual(Object.keys(observed.elements).sort(), [...expected.order].sort(), 'Complete fixture inventory required');
  const checks = [], faces = {};
  const check = (id, property, wanted, actual, passed, extra = {}) => checks.push({ case: id, property, expected: wanted, actual, passed: Boolean(passed), ...extra });
  const exact = (id, property, wanted, actual) => check(id, property, wanted, actual, actual === wanted);
  const lineHeight = element => element.computed.lineHeight === 'normal' ? 'normal' : parseFloat(element.computed.lineHeight);
  const lineHeightCheck = (id, wanted, actual) => check(id, 'lineHeight', wanted, actual, wanted === 'normal' ? actual === 'normal' : near(actual, wanted, TOLERANCE.lineHeightPx));
  const sameList = (left, right) => JSON.stringify(left) === JSON.stringify(right);
  const referenceSizePx = expected.rows['row-reference'].sizePx;
  const references = Object.fromEntries(Object.entries(REFERENCES).map(([id, [, face]]) => [face, observed.elements[id].width / referenceSizePx]));

  for (const [id, row] of Object.entries(expected.rows)) {
    const actual = observed.elements[id];
    exact(id, 'kind', 'row', actual.kind);
    check(id, 'fontSizePx', row.sizePx, actual.computed.fontSizePx, near(actual.computed.fontSizePx, row.sizePx, TOLERANCE.computedPx));
    check(id, 'textIndentPx', row.indentPx, actual.computed.textIndentPx, near(actual.computed.textIndentPx, row.indentPx, TOLERANCE.computedPx));
    check(id, 'blockStartPx', 0, actual.x, near(actual.x, 0, TOLERANCE.widthPx));
    const members = observed.order.filter(member => observed.elements[member].row === id);
    check(id, 'samples', row.samples, members, sameList(members, row.samples));
    // Inter-sample spaces are measured in the body font.
    if (row.samples.length > 1) check(id, 'fontFamily', BODY_FAMILIES, actual.families, sameList(actual.families, BODY_FAMILIES));
    if (row.lineHeight !== undefined) lineHeightCheck(id, row.lineHeight, lineHeight(actual));
    if (row.repeats) {
      // Two identical one-line blocks without margins: the second starts one used line height below the first.
      const step = actual.y - observed.elements[row.repeats].y;
      if (row.lineHeight !== 'normal') check(id, 'lineStepPx', row.lineHeight, step, near(step, row.lineHeight, TOLERANCE.lineHeightPx));
      else {
        // 'normal' is a value of the user agent: both browsers add the hhea ascent, descent and line gap of the font.
        const metrics = programs[expected.samples[row.samples[0]].segments[0].face].lineSpacing * row.sizePx;
        check(id, 'lineStepPx', metrics, step, near(step, metrics, TOLERANCE.normalLineHeightPx),
          { inheritedPx: INHERITED.lineHeight, engine: 'the value of the converter, compared with this one by the PDF oracle', knownDeviation: 'normal-line-height' });
      }
    }
  }

  for (const [id, item] of Object.entries(expected.samples)) {
    const actual = observed.elements[id], computed = actual.computed, norm = engine.samples[id];
    const deviates = item.deviation ? { engine: norm.face, knownDeviation: item.deviation } : {};
    exact(id, 'kind', 'sample', actual.kind);
    exact(id, 'text', item.text, actual.text);
    check(id, 'fontSizePx', item.sizePx, computed.fontSizePx, near(computed.fontSizePx, item.sizePx, TOLERANCE.computedPx));
    exact(id, 'fontWeight', item.weight, computed.fontWeight);
    exact(id, 'fontStyle', item.style, computed.fontStyle);
    exact(id, 'fontVariant', item.variant, computed.fontVariant);
    if (expected.rows[item.row].lineHeight !== undefined) lineHeightCheck(id, expected.rows[item.row].lineHeight, lineHeight(actual));
    check(id, 'fontFamily', item.families, actual.families, sameList(actual.families, item.families));
    exact(id, 'lineBoxes', 1, actual.boxes);
    check(id, 'widthPx', item.widthPx, actual.width, near(actual.width, item.widthPx, TOLERANCE.widthPx));
    check(id, 'xPx', item.xPx, actual.x, near(actual.x, item.xPx, TOLERANCE.xPx));

    // Per-cluster advances: every base character with its marks comes from the expected program at the expected size.
    const letters = Array.isArray(actual.characters) ? actual.characters : [];
    let worst = letters.length === item.characters.length ? 0 : Infinity, offset = 0;
    const characterCandidates = item.characters.map((letter, index) => {
      const measured = letters[index];
      if (!measured || measured.text !== letter.text || !finite(measured.width) || !finite(measured.x)) worst = Infinity;
      else worst = Math.max(worst, Math.abs(measured.width - letter.widthPx), Math.abs(measured.x - actual.x - offset));
      offset += letter.widthPx;
      // Programs whose own advance explains the measured cluster.
      return Object.keys(programs).filter(key => explains({ ...item, text: letter.text }, rules, key, measured?.width));
    });
    check(id, 'characterAdvancesPx', 0, worst, worst <= TOLERANCE.widthPx, { faces: item.characters.map(letter => letter.face) });
    faces[id] = { face: null, characterCandidates };

    if (item.identifiesFace) {
      const candidates = Object.keys(programs).filter(key => explains(item, rules, key, actual.width));
      faces[id].face = candidates.length === 1 ? candidates[0] : null;
      check(id, 'faceByFontTables', item.face, candidates, candidates.length === 1 && candidates[0] === item.face, item.face === norm.face ? {} : deviates);
    }
    if (!(id in REFERENCES) && item.text === WORD && item.face !== null && item.characters.every(letter => letter.drawn === letter.text && !letter.feature)) {
      // The same word in the one-face reference families of the same page, compared per em. A reference is not
      // compared with itself: the font tables alone identify it.
      const em = actual.width / item.sizePx, ranked = Object.entries(references).sort((left, right) => Math.abs(left[1] - em) - Math.abs(right[1] - em));
      const distancePx = Math.abs(ranked[0][1] - em) * item.sizePx, runnerUpPx = Math.abs(ranked[1][1] - em) * item.sizePx;
      check(id, 'faceByReferenceFamily', item.face, ranked[0][0],
        ranked[0][0] === item.face && distancePx <= TOLERANCE.widthPx * (1 + item.sizePx / referenceSizePx) && runnerUpPx > 4 * TOLERANCE.widthPx,
        { distancePx, runnerUpPx, ...(item.face === norm.face ? {} : deviates) });
    }
    if (item.characters.some(letter => letter.sizePx !== item.sizePx)) {
      // Synthesized small capitals: measured size of the reduced capitals relative to the font size.
      const kept = sum(item.characters.filter(letter => letter.sizePx === item.sizePx).map(letter => letter.widthPx));
      const full = sum(item.characters.filter(letter => letter.sizePx !== item.sizePx).map(letter => letter.widthPx * item.sizePx / letter.sizePx));
      const scale = (actual.width - kept) / full, wanted = SMALL_CAPS_SCALE[browser];
      check(id, 'smallCapsScale', wanted, scale, near(scale, wanted, TOLERANCE.smallCapsScale),
        wanted === SMALL_CAPS_SCALE.engine ? {} : { engine: SMALL_CAPS_SCALE.engine, knownDeviation: 'chromium-small-caps-scale' });
    }
    if (item.deviation) {
      // The deviation must persist: what the browser draws stays visibly different from what the converter must draw.
      const distance = Math.abs(actual.width - norm.widthPx);
      check(id, 'differsFromConverterPx', '> ' + 4 * TOLERANCE.widthPx, distance, finite(distance) && distance > 4 * TOLERANCE.widthPx, { engineWidthPx: norm.widthPx, knownDeviation: item.deviation });
    }
  }

  // The CSS1 example (larger from medium is large) must stay unreproduced: the ratio is 1.2, not a table step.
  const size = id => observed.elements[id].computed.fontSizePx;
  for (const [relative, table, difference] of [['larger-from-medium', 'absolute-12-large', 16 * RELATIVE_RATIO - 18],
    ['smaller-from-medium', 'absolute-12-small', 16 / RELATIVE_RATIO - 13], ['smaller-from-small', 'absolute-12-x-small', 13 / RELATIVE_RATIO - 10]]) {
    const actual = size(relative) - size(table);
    check(relative, 'differenceFromTableStepPx', difference, actual, near(actual, difference, TOLERANCE.computedPx) && Math.abs(actual) > 0.3,
      { tableStep: table, knownDeviation: 'relative-size-ratio' });
  }
  return { passed: checks.every(item => item.passed), comparisons: checks.length, checks, faces };
}

// What the PDF oracle has to find: norm-derived targets, with the browsers that witness each of them.
function pdfTargets(offsets) {
  const engine = expectations('engine'), browsers = { chromium: expectations('chromium'), firefox: expectations('firefox') };
  const same = (left, right) => Math.abs(left.xPx - right.xPx) < 1e-9 && sameDrawing(left, right);
  return { cssPxToPdfPt: 0.75, contentLeftPt: CONTENT_LEFT_PX * 0.75,
    legend: { compact: 'Offset and length in code points of the body text with all white space removed.',
      segments: 'One text command per face and size, in order; drawn is the text of the glyphs, text the source it stands for.',
      xPx: 'From the left content edge, in CSS pixels; xPt is the same position on the PDF page.',
      lineHeight: 'Used line height of a font shorthand example in CSS pixels, or normal; repeats names the identical block set just above.',
      witnessedBy: 'Browsers whose expected position and segments equal this target; an empty list is a normative expectation no browser reproduces.' },
    rows: Object.fromEntries(Object.entries(engine.rows).map(([id, { endPx, ...row }]) => [id, row])),
    intendedDiagnostics: Object.values(engine.samples).flatMap(item => item.diagnostics.map(note => ({ code: note.code, sample: item.id, circumstance: note.circumstance }))),
    samples: Object.fromEntries(Object.entries(engine.samples).map(([id, item]) => {
      let xPx = item.xPx;
      const segments = item.segments.map(segment => {
        const placed = { text: segment.text, drawn: segment.drawn, face: segment.face, fontSizePx: segment.sizePx, fontSizePt: segment.sizePx * 0.75,
          xPx, xPt: (CONTENT_LEFT_PX + xPx) * 0.75, widthPx: segment.widthPx, widthPt: segment.widthPx * 0.75 };
        xPx += segment.widthPx;
        return placed;
      });
      const deviations = Object.fromEntries(Object.entries(browsers).filter(([, expected]) => expected.samples[id].deviation).map(([name, expected]) => [name, expected.samples[id].deviation]));
      return [id, { row: item.row, requirement: item.requirement, text: item.text, compact: offsets?.[id] ?? null,
        fontSizePx: item.sizePx, fontSizePt: item.sizePx * 0.75, fontWeight: item.weight, fontStyle: item.style, fontVariant: item.variant,
        face: item.face, segments, xPx: item.xPx, widthPx: item.widthPx, xPt: (CONTENT_LEFT_PX + item.xPx) * 0.75, widthPt: item.widthPx * 0.75,
        witnessedBy: Object.keys(browsers).filter(name => same(item, browsers[name].samples[id])), knownDeviations: deviations }];
    })) };
}

// In-page observation, serialized into the browser. Computed values are read from the untouched cascade; geometry is read
// after the explicit catalog semantics are applied: only bound names participate, generic keywords use their bound
// family and the default family comes last.
async function observeDocument({ generic, names, generics, fallback }) {
  const compact = text => Array.from(text.replace(/\s+/gu, '')).length;
  const families = value => {
    const list = [];
    let name = '', quoted = false, quote = null;
    const push = () => {
      const text = name.replace(/\s+/gu, ' ').trim();
      if (text !== '' || quoted) list.push(!quoted && generic.includes(text.toLowerCase()) ? { generic: text.toLowerCase() } : text);
      name = ''; quoted = false;
    };
    for (let index = 0; index < value.length; ++index) {
      const letter = value[index];
      if (quote) { if (letter === '\\') name += value[++index] ?? ''; else if (letter === quote) quote = null; else name += letter; }
      else if (letter === '"' || letter === "'") { quote = letter; quoted = true; }
      else if (letter === ',') push();
      else name += letter;
    }
    push();
    return list;
  };
  const offsets = new Map(), walker = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT);
  let compactLength = 0;
  for (let node; (node = walker.nextNode());) { offsets.set(node, compactLength); compactLength += compact(node.data); }
  const order = [], elements = {}, identified = [...document.body.querySelectorAll('[id]')];
  for (const element of identified) {
    const style = getComputedStyle(element), first = document.createTreeWalker(element, NodeFilter.SHOW_TEXT).nextNode();
    order.push(element.id);
    elements[element.id] = { kind: element.querySelector('[id]') ? 'row' : 'sample', row: element.parentElement.closest('[id]')?.id ?? null,
      text: element.textContent.replace(/\s+/gu, ' ').trim(), compact: { offset: offsets.get(first), length: compact(element.textContent) },
      computed: { fontSizePx: parseFloat(style.fontSize), fontWeight: Number(style.fontWeight), fontStyle: style.fontStyle, fontVariant: style.fontVariant,
        lineHeight: style.lineHeight, textIndentPx: parseFloat(style.textIndent), fontFamily: style.fontFamily,
        fontSynthesisWeight: style.fontSynthesisWeight, fontSynthesisStyle: style.fontSynthesisStyle },
      families: families(style.fontFamily) };
  }

  const known = [...names, ...Object.values(generics)];
  let rewrittenFamilyLists = 0;
  for (const element of [document.documentElement, document.body, ...document.body.querySelectorAll('*')]) {
    const current = families(getComputedStyle(element).fontFamily);
    const bound = current.flatMap(item => typeof item === 'string' ? (known.includes(item) ? [item] : []) : (generics[item.generic] ? [generics[item.generic]] : []))
      .filter(name => name !== fallback).concat([fallback]);
    if (JSON.stringify(current) !== JSON.stringify(bound)) {
      element.style.setProperty('font-family', bound.map(name => '"' + name.replace(/["\\]/gu, '\\$&') + '"').join(', '));
      ++rewrittenFamilyLists;
    }
  }
  await document.fonts.ready;

  // Advance boxes of every identified element and of each of its clusters (a base character with its combining
  // marks), relative to the content edge.
  const origin = document.body.getBoundingClientRect().x;
  for (const element of identified) {
    const box = element.getBoundingClientRect(), entry = elements[element.id];
    Object.assign(entry, { boundFamilies: families(getComputedStyle(element).fontFamily), x: box.x - origin, y: box.y, width: box.width, height: box.height,
      boxes: element.getClientRects().length });
    if (entry.kind !== 'sample') continue;
    entry.characters = [];
    const letters = document.createTreeWalker(element, NodeFilter.SHOW_TEXT);
    for (let node; (node = letters.nextNode());) {
      for (const cluster of node.data.matchAll(/\P{M}\p{M}*/gu)) {
        const range = document.createRange();
        range.setStart(node, cluster.index); range.setEnd(node, cluster.index + cluster[0].length);
        const letter = range.getBoundingClientRect();
        entry.characters.push({ text: cluster[0], x: letter.x - origin, width: letter.width });
      }
    }
  }
  return { order, elements, compactLength, rewrittenFamilyLists };
}

// @font-face rules for every labelled face; generic and default bindings get reserved family names. A face labelled as
// a small-caps font is not declared: @font-face cannot say so, and under another label it would answer normal text.
function fontFaceRules({ groups, files }) {
  const reserved = group => group.kind === 'family' ? group.name : group.kind === 'generic' ? 'witness generic ' + group.name : 'witness default';
  const rules = groups.flatMap(group => group.faces.filter(face => !face.smallCaps).map(face => '@font-face{font-family:"' + reserved(group) + '";src:url("' + ORIGIN + '/' + files[faceKey(face.file)].sha256 + '.ttf") format("truetype");font-weight:' + face.weight + ';font-style:' + face.style + '}'));
  return { rules, names: groups.filter(group => group.kind === 'family').map(group => group.name),
    generics: Object.fromEntries(groups.filter(group => group.kind === 'generic').map(group => [group.name, reserved(group)])),
    fallback: reserved(groups.find(group => group.kind === 'default')) };
}

async function observeBrowser(type, name, launch, source, output) {
  const { groups, files } = context(), fonts = fontFaceRules({ groups, files });
  assert(groups.every(group => group.kind !== 'family' || !/^witness (generic|default)/u.test(group.name)), 'Reserved family name used by the bindings');
  const served = new Map(Object.values(files).map(file => [ORIGIN + '/' + file.sha256 + '.ttf', file.bytes]));
  const browser = await type.launch({ headless: true, ...launch });
  try {
    const session = await browser.newContext({ viewport: { width: 794, height: 1123 }, deviceScaleFactor: 1, serviceWorkers: 'block' });
    session.setDefaultTimeout(30_000);
    const blocked = [], delivered = [];
    await session.route('**/*', async route => {
      const url = route.request().url();
      if (url === ORIGIN + '/fonts.html') return route.fulfill({ status: 200, contentType: 'text/html; charset=utf-8', body: source });
      if (served.has(url)) { delivered.push(url); return route.fulfill({ status: 200, contentType: 'font/ttf', body: served.get(url) }); }
      blocked.push(url);
      return route.abort();
    });
    const page = await session.newPage();
    await page.emulateMedia({ media: 'print' });
    await page.goto(ORIGIN + '/fonts.html', { waitUntil: 'load', timeout: 30_000 });
    // Page model of the converter: A4 content box at 96 CSS px per inch. Bold and oblique are never synthesized;
    // small-capital synthesis stays enabled because it is one of the measured subjects.
    await page.addStyleTag({ content: fonts.rules.join('\n') + '\nhtml{font-synthesis-weight:none!important;font-synthesis-style:none!important}'
      + 'body{margin:' + CONTENT_LEFT_PX + 'px!important;width:' + CONTENT_WIDTH_PX + 'px}' });
    const fontBinding = await page.evaluate(async () => {
      const faces = [...document.fonts];
      await Promise.race([Promise.all(faces.map(face => face.load())), new Promise((_, reject) => setTimeout(() => reject(new Error('Explicit font load timeout')), 20_000))]);
      return faces.map(face => ({ family: face.family.replace(/^["']|["']$/gu, ''), weight: face.weight, style: face.style, status: face.status }));
    });
    assert.equal(fontBinding.length, fonts.rules.length, 'Every declared face must be declared exactly once');
    assert(fontBinding.every(face => face.status === 'loaded'), 'Every declared face must load from the supplied bytes');
    const observed = await page.evaluate(observeDocument, { generic: GENERIC, names: fonts.names, generics: fonts.generics, fallback: fonts.fallback });
    assert(Object.values(observed.elements).every(element => element.computed.fontSynthesisWeight === 'none' && element.computed.fontSynthesisStyle === 'none'),
      'Synthetic bold and oblique must be disabled');
    assert.deepEqual(blocked, [], 'No external request allowed');
    assert.deepEqual([...new Set(delivered)].sort(), [...served.keys()].sort(), 'Every supplied program must be requested, and nothing else');
    const comparison = compare(observed, name);
    for (const [id, classified] of Object.entries(comparison.faces)) Object.assign(observed.elements[id], classified);
    delete comparison.faces;
    const screenshot = name + '-fonts.png';
    await page.screenshot({ path: path.join(output, screenshot), fullPage: true, animations: 'disabled' });
    const result = { version: browser.version(), launchArguments: launch.args ?? [],
      fontBinding: { faces: fontBinding.length, rewrittenFamilyLists: observed.rewrittenFamilyLists, declared: fontBinding },
      ...comparison, observed, screenshot: { file: screenshot, sha256: hash(fs.readFileSync(path.join(output, screenshot))) } };
    await session.close();
    return result;
  } finally { await browser.close(); }
}

// Firefox follows the fontconfig hinting of the machine, and hinted advances are rounded to whole pixels. The
// configuration of the machine is kept and only hinting is switched off.
function unhintedFontconfig(output) {
  const host = path.resolve(process.env.FONTCONFIG_FILE || '/etc/fonts/fonts.conf');
  const escaped = host.replace(/&/gu, '&amp;').replace(/</gu, '&lt;').replace(/>/gu, '&gt;');
  const file = path.join(output, 'fontconfig-unhinted.conf');
  fs.writeFileSync(file, '<?xml version="1.0"?>\n<!DOCTYPE fontconfig SYSTEM "fonts.dtd">\n<fontconfig>\n  <include ignore_missing="yes">' + escaped
    + '</include>\n  <match target="font"><edit name="hinting" mode="assign"><bool>false</bool></edit></match>\n</fontconfig>\n');
  return file;
}

async function run(output) {
  const { chromium, firefox } = require('playwright');
  const source = fs.readFileSync(path.join(ROOT, SOURCE));
  assert(source.length < 100_000, 'Bounded source required');
  assert(!/<script\b/iu.test(source.toString('utf8')), 'Only the explicit observation harness may execute JavaScript');
  fs.mkdirSync(output, { recursive: true });
  const report = { status: 'fail', sourceSha256: hash(source),
    scope: 'CSS1 5.2 font properties: size keywords, relative weights, weight, style and variant matching, small capitals, per-character fallback and the font shorthand with its line height; browser continuous-page geometry, not browser pagination equivalence',
    cssPxToPdfPt: 0.75, contentLeftCssPx: CONTENT_LEFT_PX, toleranceCssPx: TOLERANCE, browsers: {} };
  try {
    const { bindingBytes, files } = context();
    Object.assign(report, { bindingsSha256: hash(bindingBytes), fontFiles: Object.fromEntries(Object.entries(files).map(([key, file]) => [key, { file: file.file, sha256: file.sha256 }])),
      node: process.version, playwright: require('playwright/package.json').version, design: design(), knownDeviations: KNOWN_DEVIATIONS,
      familyBinding: 'Computed font-family lists are restricted to the families bound in fonts.json; generic keywords use their bound family and the default family comes last. System fonts never participate.' });
    // Both browsers lay text out with unhinted, fractional advances so that widths can be checked against hmtx:
    // Chromium through its own switch, Firefox through the fontconfig file written by unhintedFontconfig().
    const unhinted = { ...process.env, FONTCONFIG_FILE: unhintedFontconfig(output) };
    for (const [name, type, launch] of [['chromium', chromium, { args: ['--font-render-hinting=none'] }], ['firefox', firefox, { env: unhinted }]]) {
      report.browsers[name] = await observeBrowser(type, name, launch, source, output);
    }
    const [first, second] = Object.values(report.browsers).map(result => result.observed);
    assert.deepEqual(first.order, second.order, 'Both browsers must see the same inventory');
    const offsets = Object.fromEntries(first.order.map(id => [id, first.elements[id].compact]));
    assert.deepEqual(offsets, Object.fromEntries(second.order.map(id => [id, second.elements[id].compact])), 'Both browsers must see the same text offsets');
    report.inventory = first.order;
    report.compactTextLength = first.compactLength;
    report.pdfTargets = pdfTargets(offsets);
    for (const name of Object.keys(KNOWN_DEVIATIONS)) {
      assert(Object.values(report.browsers).some(result => result.checks.some(item => item.knownDeviation === name)), 'Every known deviation must be asserted by a comparison: ' + name);
    }
    assert(Object.values(report.browsers).every(result => result.passed), 'Every browser comparison must pass');
    report.status = 'pass';
  } catch (error) { report.error = error.stack || String(error); }
  fs.writeFileSync(path.join(output, 'browser-report.json'), JSON.stringify(report, null, 2) + '\n');
  return report;
}

module.exports = { ABSOLUTE_PX, BOLDER, LIGHTER, INHERITED, KNOWN_DEVIATIONS, NORM, REFERENCES, RIVALS, RULES, SCALE_CLASSES, SMALL_CAPS_SCALE, TOLERANCE, WEIGHTS, WORD,
  compare, context, design, expectations, familyFace, pdfTargets, rebind, run, shape, weightOrder };
if (require.main === module) {
  assert.equal(process.argv.length, 3, 'Usage: node tools/validate_fonts.cjs OUTPUT_DIRECTORY');
  run(path.resolve(process.argv[2])).then(report => {
    console.log(JSON.stringify({ status: report.status, browsers: Object.fromEntries(Object.entries(report.browsers).map(([name, result]) => [name, { version: result.version, comparisons: result.comparisons, passed: result.passed }])), error: report.error }));
    if (report.status !== 'pass') process.exitCode = 1;
  }).catch(error => { console.error(error); process.exitCode = 1; });
}
