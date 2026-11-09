'use strict';

// Independent browser observations of the exact source, with explicit font bytes.
const assert = require('node:assert/strict');
const crypto = require('node:crypto');
const fs = require('node:fs');
const path = require('node:path');
const ROOT = path.resolve(__dirname, '..');
const hash = bytes => crypto.createHash('sha256').update(bytes).digest('hex');
const CASES = ['word-normal', 'word-positive', 'word-negative', 'nbsp-normal', 'nbsp-positive', 'nbsp-negative',
  'letter-normal', 'letter-positive', 'letter-negative', 'combined', 'inherited', 'combining-normal',
  'combining-positive', 'ligature-normal', 'ligature-positive', 'indent-positive', 'indent-negative', 'indent-percent', 'page-continuation'];
const DELTAS = [['word-positive', 'word-normal', 6], ['word-negative', 'word-normal', -2],
  ['nbsp-positive', 'nbsp-normal', 6], ['nbsp-negative', 'nbsp-normal', -2],
  ['letter-positive', 'letter-normal', 2], ['letter-negative', 'letter-normal', -1],
  ['combined', 'word-normal', 10], ['inherited', 'word-normal', 10]];

function compare(observed) {
  assert.deepEqual(Object.keys(observed), CASES, 'Complete fixture inventory required');
  const checks = [];
  for (const [variant, control, addition] of DELTAS) {
    for (let index = 0; index < 4; ++index) {
      const actual = observed[variant].letters[index].x - observed[variant].letters[0].x
        - observed[control].letters[index].x + observed[control].letters[0].x;
      checks.push({ case: variant, property: 'glyphStartDelta', index, expected: addition * index,
        actual, passed: Number.isFinite(actual) && Math.abs(actual - addition * index) <= 0.09 });
    }
  }
  for (const [name, expected] of [['indent-positive', 24], ['indent-negative', -12], ['indent-percent', 16]]) {
    assert(observed[name].lines.length >= 2, 'First-line tests must actually wrap');
    observed[name].lines.forEach((line, index) => {
      const actual = line.x - observed[name].block.x, indent = index === 0 ? expected : 0;
      checks.push({ case: name, property: 'lineIndent', index, expected: indent, actual,
        passed: Number.isFinite(actual) && Math.abs(actual - indent) <= 0.09 });
    });
  }
  const normal = observed['combining-normal'].characters, spaced = observed['combining-positive'].characters;
  const distance = row => row.findLast(item => item.text === 'b').x - row.findLast(item => item.text === 'a').x;
  const actual = distance(spaced) - distance(normal);
  checks.push({ case: 'combining-positive', property: 'oneGapAfterGrapheme', expected: 2, actual,
    passed: Math.abs(actual - 2) <= 0.09 });
  return { passed: checks.every(item => item.passed), comparisons: checks.length, checks };
}

async function run(output) {
  const { chromium, firefox } = require('playwright');
  const source = fs.readFileSync(path.join(ROOT, 'examples/sources/text-spacing.html'));
  const font = fs.readFileSync(path.join(ROOT, 'tests/Fixtures/fonts/NotoSans-Regular.ttf'));
  assert(source.length < 100_000 && font.length < 16_777_216);
  fs.mkdirSync(output, { recursive: true });
  const report = { status: 'fail', sourceSha256: hash(source), fontSha256: hash(font),
    scope: 'Signed word/letter spacing, grapheme boundary and first-line indent; browser continuous-page geometry, not browser pagination equivalence',
    cssPxToPdfPt: 0.75, toleranceCssPx: 0.09, playwright: require('playwright/package.json').version, browsers: {} };
  try {
    for (const [name, type] of Object.entries({ chromium, firefox })) {
      const browser = await type.launch({ headless: true });
      try {
        assert(!/<script\b/i.test(source.toString('utf8')), 'Only the explicit observation harness may execute JavaScript');
        const context = await browser.newContext({ viewport: { width: 794, height: 1123 }, deviceScaleFactor: 1, serviceWorkers: 'block' });
        context.setDefaultTimeout(30_000);
        const requests = [];
        await context.route('**/*', async route => { requests.push(route.request().url()); await route.abort(); });
        const page = await context.newPage();
        await page.emulateMedia({ media: 'print' });
        await page.setContent(source.toString('utf8'), { waitUntil: 'load', timeout: 30_000 });
        await page.addStyleTag({ content: '@font-face{font-family:ExplicitWitness;src:url(data:font/ttf;base64,' + font.toString('base64') + ')}body{font-family:ExplicitWitness!important;margin:48px;width:697.700787401px}' });
        const fontBinding = await page.evaluate(async () => {
          const faces = await Promise.race([document.fonts.load('16px ExplicitWitness'), new Promise((_, reject) => setTimeout(() => reject(new Error('Explicit font load timeout')), 15_000))]);
          if (faces.length !== 1 || faces[0].family !== 'ExplicitWitness' || faces[0].status !== 'loaded' || !document.fonts.check('16px ExplicitWitness')) throw new Error('Explicit font unavailable');
          return { family: faces[0].family, status: faces[0].status, faces: faces.length };
        });
        const observed = await page.evaluate(ids => Object.fromEntries(ids.map(id => {
          const element = document.getElementById(id), block = element.getBoundingClientRect();
          const walker = document.createTreeWalker(element, NodeFilter.SHOW_TEXT), characters = [];
          for (let node; (node = walker.nextNode());) {
            for (let offset = 0; offset < node.data.length;) {
              const text = String.fromCodePoint(node.data.codePointAt(offset)), range = document.createRange();
              range.setStart(node, offset); range.setEnd(node, offset + text.length);
              const box = range.getBoundingClientRect();
              characters.push({ text, x: box.x, y: box.y, width: box.width });
              offset += text.length;
            }
          }
          const lines = [];
          for (const item of characters.filter(item => /\S/u.test(item.text))) {
            if (!lines.length || Math.abs(lines.at(-1).y - item.y) > 0.1) lines.push({ x: item.x, y: item.y, text: item.text });
            else lines.at(-1).text += item.text;
          }
          return [id, { text: element.textContent, block: { x: block.x, y: block.y, width: block.width }, characters,
            letters: characters.slice(3).filter(item => /^[ABCD]$/.test(item.text)), lines }];
        })), CASES);
        assert.deepEqual(requests, [], 'No external font or resource requests allowed');
        const comparison = compare(observed);
        const screenshot = name + '-text-spacing.png';
        await page.screenshot({ path: path.join(output, screenshot), fullPage: true, animations: 'disabled' });
        report.browsers[name] = { version: browser.version(), fontBinding, ...comparison, observed,
          screenshot: { file: screenshot, sha256: hash(fs.readFileSync(path.join(output, screenshot))) } };
        await context.close();
      } finally { await browser.close(); }
    }
    assert(Object.values(report.browsers).every(result => result.passed), 'Every browser comparison must pass');
    report.status = 'pass';
  } catch (error) { report.error = error.stack || String(error); }
  fs.writeFileSync(path.join(output, 'browser-report.json'), JSON.stringify(report, null, 2) + '\n');
  return report;
}

module.exports = { CASES, DELTAS, compare, run };
if (require.main === module) {
  assert.equal(process.argv.length, 3, 'Usage: node tools/validate_text_spacing.cjs OUTPUT_DIRECTORY');
  run(path.resolve(process.argv[2])).then(report => {
    console.log(JSON.stringify({ status: report.status, browsers: Object.fromEntries(Object.entries(report.browsers).map(([name, result]) => [name, { version: result.version, comparisons: result.comparisons, passed: result.passed }])), error: report.error }));
    if (report.status !== 'pass') process.exitCode = 1;
  }).catch(error => { console.error(error); process.exitCode = 1; });
}
