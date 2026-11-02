'use strict';

const assert = require('node:assert/strict');
const crypto = require('node:crypto');
const fs = require('node:fs');
const path = require('node:path');

const IDS = ['invalid-color', 'invalid-important', 'signed-rgb', 'percent-rgb', 'mixed-rgb', 'invalid-rgb-token', 'invalid-hex', 'atomic-margin', 'atomic-border', 'invalid-list', 'escaped:selector', 'recovery', 'after-recovery', 'linked', 'nested-import', 'print-import', 'screen-import', 'late-import', 'border-keywords', 'zero-style', 'none-style', 'border-inherit', 'currentcolor', 'asymmetric', 'transparent-border', 'three-dimensional'];
const RESOURCES = ['css/foundation.css', 'css/nested.css', 'print.css', 'screen.css', 'late.css'];
const BORDER_IDS = ['atomic-border', 'border-keywords', 'zero-style', 'none-style', 'border-inherit', 'currentcolor', 'asymmetric', 'transparent-border', 'three-dimensional'];
const ORIGIN = 'http://127.0.0.1:17771/'; // Every request is intercepted; no listener or network access is used.

class CssFoundationOracle {
  constructor(input, output, fixture) {
    this.output = output;
    this.fixture = fixture;
    this.hash = bytes => crypto.createHash('sha256').update(bytes).digest('hex');
    assert(fixture && fixture.policy === 'Report', 'Report-mode foundation witnesses are mandatory');
    assert.equal(fixture.htmlFile, 'foundations.html');
    assert.deepEqual(fixture.witnesses.map(row => row.id), IDS, 'Require the complete foundation inventory');
    for (const row of fixture.witnesses) {
      const properties = ['color'];
      if (row.id === 'atomic-margin') properties.push(...['top', 'right', 'bottom', 'left'].map(side => 'margin-' + side));
      if (BORDER_IDS.includes(row.id)) {
        for (const side of ['top', 'right', 'bottom', 'left']) {
          properties.push('border-' + side + '-width', 'border-' + side + '-style', 'border-' + side + '-color');
        }
      }
      assert.deepEqual(Object.keys(row.values), properties, 'Complete properties for ' + row.id);
    }
    assert.deepEqual(fixture.requiredDiagnosticCodes, ['css.syntax', 'css.value', 'css.selector', 'css.import-order']);
    const counts = {};
    for (const diagnostic of fixture.diagnostics) {
      assert.equal(typeof diagnostic.message, 'string');
      counts[diagnostic.code] = (counts[diagnostic.code] || 0) + 1;
    }
    assert.deepEqual(counts, { 'css.syntax': 1, 'css.value': 7, 'css.selector': 1, 'css.import-order': 1 }, 'Invalid sources must be diagnosed without unrelated fallback');
    assert.deepEqual(fixture.resources.map(row => row.uri), RESOURCES);
    this.documents = new Map();
    const base = path.dirname(path.resolve(input));
    const read = (relative, sha256, bytes) => {
      assert(relative && !path.isAbsolute(relative) && !relative.split(/[\\/]/).includes('..'));
      const file = path.resolve(base, relative);
      assert(file.startsWith(base + path.sep));
      const stat = fs.lstatSync(file);
      assert(stat.isFile() && !stat.isSymbolicLink() && stat.size <= 1_048_576);
      const source = fs.readFileSync(file);
      assert.equal(this.hash(source), sha256, `Source identity: ${relative}`);
      if (bytes !== undefined) assert.equal(source.length, bytes);
      return source;
    };
    this.documents.set(ORIGIN + fixture.htmlFile, { contentType: 'text/html; charset=utf-8', body: read(fixture.htmlFile, fixture.htmlSha256) });
    for (const resource of fixture.resources) {
      assert.equal(resource.file, 'foundation-resources/' + resource.uri);
      this.documents.set(new URL(resource.uri, ORIGIN).href, { contentType: 'text/css; charset=utf-8', body: read(resource.file, resource.sha256, resource.bytes) });
    }
    const geometryRows = fixture.witnesses.filter(row => row.geometry !== null);
    assert.deepEqual(geometryRows.map(row => row.id), ['asymmetric', 'transparent-border', 'three-dimensional']);
    for (const row of geometryRows) {
      assert.deepEqual(Object.keys(row.geometry), ['x', 'y', 'width', 'height']);
      assert(Object.values(row.geometry).every(value => Number.isFinite(value) && value >= 0));
    }
    this.input = { htmlSha256: fixture.htmlSha256, resources: fixture.resources, diagnosticCounts: counts };
  }

  async run(browser, name) {
    const record = { passed: false, scope: this.fixture.scope, input: this.input, comparisons: 0, failures: [], witnesses: [], fulfilledLocalResources: [], blockedRequests: [], networkPolicy: 'Only exact hash-checked local fixture bytes are fulfilled at a synthetic loopback origin; every other request is aborted.' };
    const context = await browser.newContext({ javaScriptEnabled: false, serviceWorkers: 'block', viewport: { width: 1000, height: 1500 }, deviceScaleFactor: 1 });
    try {
      await context.route('**/*', async route => {
        const url = route.request().url();
        const document = this.documents.get(url);
        if (!document) {
          record.blockedRequests.push(url);
          await route.abort();
          return;
        }
        record.fulfilledLocalResources.push(url.slice(ORIGIN.length));
        await route.fulfill({ status: 200, ...document });
      });
      const page = await context.newPage();
      await page.emulateMedia({ media: 'print' });
      await page.goto(ORIGIN + this.fixture.htmlFile, { waitUntil: 'load', timeout: 30_000 });
      const actuals = await page.evaluate(rows => rows.map(row => {
        const element = document.getElementById(row.id);
        if (!element) throw new Error('Missing foundation witness ' + row.id);
        const computed = getComputedStyle(element);
        const box = element.getBoundingClientRect();
        return { id: row.id, values: Object.fromEntries(Object.keys(row.values).map(key => [key, computed.getPropertyValue(key)])), geometry: { x: box.x * .75, y: box.y * .75, width: box.width * .75, height: box.height * .75 } };
      }), this.fixture.witnesses);
      for (const [index, expected] of this.fixture.witnesses.entries()) {
        const actual = actuals[index];
        assert.equal(actual.id, expected.id);
        const checks = [];
        for (const [property, target] of Object.entries(expected.values)) {
          const raw = actual.values[property];
          checks.push(this.compare(record, expected.id, property, target, this.normalize(raw, target), raw));
        }
        if (expected.geometry !== null) {
          for (const [dimension, target] of Object.entries(expected.geometry)) {
            checks.push(this.compare(record, expected.id, 'BorderBox.' + dimension, target, actual.geometry[dimension]));
          }
        }
        record.witnesses.push({ id: expected.id, features: expected.features, passed: checks.every(check => check.passed), checks });
      }
      assert.deepEqual(record.blockedRequests, [], 'No external or unexpected fixture requests');
      for (const required of ['foundations.html', 'css/foundation.css', 'css/nested.css', 'print.css']) {
        assert(record.fulfilledLocalResources.includes(required), 'Browser must consume ' + required);
      }
      assert(!record.fulfilledLocalResources.includes('late.css'), 'A late import must not be loaded');
      const file = `${name}-css1-foundations.png`;
      await page.screenshot({ path: path.join(this.output, file), fullPage: true, animations: 'disabled' });
      record.screenshot = { file, sha256: this.hash(fs.readFileSync(path.join(this.output, file))) };
      record.passed = record.failures.length === 0;
    } catch (error) {
      record.failures.push({ error: error.stack || String(error) });
    } finally {
      await context.close();
    }
    return record;
  }

  normalize(raw, target) {
    if (typeof target === 'number') {
      assert.match(raw, /^-?(?:\d+(?:\.\d+)?|\.\d+)px$/);
      return Number.parseFloat(raw) * .75;
    }
    if (Array.isArray(target)) {
      const match = /^rgba?\(\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)(?:\s*,\s*(\d+(?:\.\d+)?))?\s*\)$/.exec(raw);
      assert(match, 'Expected browser sRGB or transparent color: ' + raw);
      return match.slice(1).filter(value => value !== undefined).map(Number);
    }
    return raw;
  }

  compare(record, id, property, expected, actual, raw = null) {
    const passed = typeof expected === 'number'
      ? Number.isFinite(actual) && Math.abs(expected - actual) <= .02
      : JSON.stringify(expected) === JSON.stringify(actual);
    const check = { property, expected, actual, ...(raw === null ? {} : { browserCss: raw }), passed };
    record.comparisons++;
    if (!passed) record.failures.push({ id, ...check });
    return check;
  }
}

module.exports = { CssFoundationOracle };
