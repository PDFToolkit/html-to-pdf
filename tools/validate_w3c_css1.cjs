'use strict';

// The unchanged historical source is the input; PHP output is never an expectation.
const assert = require('node:assert/strict');
const crypto = require('node:crypto');
const fs = require('node:fs');
const path = require('node:path');

const FIXTURE = path.resolve(__dirname, '../resources/css1/w3c');
const SOURCE_HASH = '6dcacbb4314074283548010682dcb632abe459852f38f18c41abc8a33d321097';
const GEOMETRY = ['body', 'dl', 'dt', 'dd', 'ul', 'li:nth-child(1)', '#bar', 'li:nth-child(3)', '#baz', 'blockquote', 'h1', 'body > p', '#bar > p'];
const PROPERTIES = ['html', 'li:nth-child(1)', '#bar > form', '#bar > form > p:nth-child(1)', '#bar > form > p:nth-child(2)', 'body > p', 'address', 'h1'];
const hash = bytes => crypto.createHash('sha256').update(bytes).digest('hex');

class HistoricalCss1Oracle {
  constructor(fixture = FIXTURE) {
    this.source = fs.readFileSync(path.join(fixture, 'test5526c.htm'));
    assert.equal(hash(this.source), SOURCE_HASH, 'Use the exact unmodified historical HTML');
    this.expected = JSON.parse(fs.readFileSync(path.join(fixture, 'expected.json')));
    assert.equal(this.expected.schemaVersion, 1);
    assert.equal(this.expected.sourceSha256, SOURCE_HASH);
    assert.equal(this.expected.cssPxToPdfPt, 0.75);
    assert.equal(this.expected.browserToleranceCssPx, 0.04);
    assert.deepEqual(Object.keys(this.expected.geometry), GEOMETRY, 'Complete box inventory');
    assert.deepEqual(Object.keys(this.expected.properties), PROPERTIES, 'Complete property inventory');
    assert.equal(Object.values(this.expected.geometry).reduce((n, values) => n + Object.keys(values).length, 0), 50);
    assert.equal(Object.values(this.expected.properties).reduce((n, values) => n + Object.keys(values).length, 0), 13);
    for (const dimensions of Object.values(this.expected.geometry)) {
      assert(Object.keys(dimensions).every(key => ['x', 'y', 'width', 'height'].includes(key)));
      assert(Object.values(dimensions).every(value => Number.isFinite(value) && value >= 0));
    }
  }

  compare(observed) {
    assert.equal(observed.compatMode, 'CSS1Compat', 'Legacy doctype must select standards mode');
    const rows = Object.fromEntries(observed.elements.map(row => [row.selector, row]));
    const checks = [];
    for (const [selector, dimensions] of Object.entries(this.expected.geometry)) {
      assert(rows[selector], 'Missing element: ' + selector);
      for (const [dimension, expected] of Object.entries(dimensions)) {
        const actual = rows[selector].geometry?.[dimension];
        checks.push({ selector, property: 'borderBox.' + dimension, expected, actual,
          passed: Number.isFinite(actual) && Math.abs(actual - expected) <= 0.04 });
      }
    }
    for (const [selector, properties] of Object.entries(this.expected.properties)) {
      assert(rows[selector], 'Missing element: ' + selector);
      for (const [property, expected] of Object.entries(properties)) {
        const actual = rows[selector].properties?.[property];
        checks.push({ selector, property, expected, actual, passed: actual === expected });
      }
    }
    assert.equal(rows['#bar > form > p:nth-child(1)'].parent, 'FORM');
    assert.equal(rows['#bar > form > p:nth-child(2)'].parent, 'FORM');
    return { passed: checks.every(check => check.passed), comparisons: checks.length, checks };
  }

  async run(output) {
    const { chromium, firefox } = require('playwright');
    fs.mkdirSync(output, { recursive: true });
    const report = { status: 'fail', scope: this.expected.scope, sourceSha256: SOURCE_HASH,
      media: 'print', viewport: { width: 794, height: 1123 }, deviceScaleFactor: 1,
      cssPxToPdfPt: 0.75, browserToleranceCssPx: 0.04, excluded: this.expected.excluded,
      playwright: require('playwright/package.json').version, browsers: {} };
    try {
      for (const [name, type] of Object.entries({ chromium, firefox })) {
        const browser = await type.launch({ headless: true });
        try {
          const context = await browser.newContext({ javaScriptEnabled: false, serviceWorkers: 'block', viewport: report.viewport, deviceScaleFactor: 1 });
          const blockedRequests = [];
          await context.route('**/*', async route => { blockedRequests.push(route.request().url()); await route.abort(); });
          const page = await context.newPage();
          await page.emulateMedia({ media: 'print' });
          await page.setContent(this.source.toString('utf8'), { waitUntil: 'load', timeout: 30_000 });
          const observed = await page.evaluate(expected => ({ compatMode: document.compatMode,
            elements: [...new Set([...Object.keys(expected.geometry), ...Object.keys(expected.properties)])].map(selector => {
              const el = document.querySelector(selector);
              if (!el) throw new Error('Missing witness: ' + selector);
              const box = el.getBoundingClientRect(), style = getComputedStyle(el);
              return { selector, parent: el.parentElement?.tagName ?? null,
                geometry: { x: box.x, y: box.y, width: box.width, height: box.height },
                properties: Object.fromEntries(Object.keys(expected.properties[selector] || {}).map(key => [key, style.getPropertyValue(key)])) };
            }) }), this.expected);
          const result = this.compare(observed);
          assert.deepEqual(blockedRequests, [], 'This source must need no external resources');
          const screenshot = name + '-historical-css1.png';
          await page.screenshot({ path: path.join(output, screenshot), fullPage: true, animations: 'disabled' });
          report.browsers[name] = { version: browser.version(), ...result, observed, blockedRequests,
            screenshot: { file: screenshot, sha256: hash(fs.readFileSync(path.join(output, screenshot))) } };
          await context.close();
        } finally { await browser.close(); }
      }
      assert(Object.values(report.browsers).every(browser => browser.passed), 'Every browser check must pass');
      report.status = 'pass';
    } catch (error) { report.error = error.stack || String(error); }
    fs.writeFileSync(path.join(output, 'browser-report.json'), JSON.stringify(report, null, 2) + '\n');
    return report;
  }
}

module.exports = { HistoricalCss1Oracle, FIXTURE, SOURCE_HASH };
if (require.main === module) {
  if (process.argv.length !== 3) throw new Error('Usage: node tools/validate_w3c_css1.cjs OUTPUT_DIRECTORY');
  new HistoricalCss1Oracle().run(path.resolve(process.argv[2])).then(report => {
    console.log(JSON.stringify({ status: report.status, browsers: Object.fromEntries(Object.entries(report.browsers).map(([name, result]) => [name, { version: result.version, passed: result.passed, comparisons: result.comparisons }])) }));
    if (report.status !== 'pass') process.exitCode = 1;
  }).catch(error => { console.error(error); process.exitCode = 1; });
}
