'use strict';

// Independent computed-style witnesses, not a browser-to-PDF equivalence test.
const assert = require('node:assert/strict');
const crypto = require('node:crypto');
const fs = require('node:fs');
const path = require('node:path');
const { chromium, firefox } = require('playwright');

class StyleOracle {
  constructor(input, output) {
    this.input = path.resolve(input);
    this.output = path.resolve(output);
    this.report = {
      schemaVersion: 1,
      status: 'running',
      scope: 'Selected computed CSS properties and simple content-box dimensions; no complete CSS conformance or browser/PDF layout parity claim.',
      nodeVersion: process.version,
      playwrightVersion: require('playwright/package.json').version,
      coordinateConversion: 'CSS px × 72/96 = PDF points; numeric tolerance 0.02 pt for browser subpixel serialization.',
      browsers: {},
    };
  }

  hash(bytes) { return crypto.createHash('sha256').update(bytes).digest('hex'); }

  async run() {
    fs.mkdirSync(this.output, { recursive: true });
    try {
      const bytes = fs.readFileSync(this.input);
      assert(bytes.length < 1_048_576, 'Bound the witness JSON');
      const witness = JSON.parse(bytes);
      assert.equal(witness.schemaVersion, 1);
      assert.equal(witness.media, 'print');
      assert.equal(witness.htmlFile, 'styles.html');
      assert.deepEqual(witness.diagnostics, [], 'The PHP compiler must accept every witness without fallback');
      assert.equal(witness.witnesses.length, 26, 'Run the complete original witness inventory');
      assert.equal(new Set(witness.witnesses.map(row => row.id)).size, 26);
      const html = fs.readFileSync(path.join(path.dirname(this.input), witness.htmlFile));
      assert(html.length < 1_048_576, 'Bound the witness HTML');
      assert.equal(this.hash(html), witness.htmlSha256, 'Browsers and PHP must consume identical HTML bytes');
      assert.equal(witness.layoutFixture.htmlFile, 'layout.html');
      assert.deepEqual(witness.layoutFixture.diagnostics, []);
      assert.equal(witness.layoutFixture.witnesses.length, 12);
      const layoutHtml = fs.readFileSync(path.join(path.dirname(this.input), witness.layoutFixture.htmlFile));
      assert(layoutHtml.length < 1_048_576);
      assert.equal(this.hash(layoutHtml), witness.layoutFixture.htmlSha256);
      this.report.input = { path: this.input, sha256: this.hash(bytes), htmlSha256: this.hash(html), layoutHtmlSha256: this.hash(layoutHtml), phpVersion: witness.phpVersion };
      this.report.references = witness.references;
      for (const [name, type] of Object.entries({ chromium, firefox })) {
        await this.browser(name, type, html.toString('utf8'), witness.witnesses, layoutHtml.toString('utf8'), witness.layoutFixture);
      }
      assert(Object.values(this.report.browsers).every(browser => browser.passed), 'Every browser witness must match PHP');
      this.report.status = 'pass';
    } catch (error) {
      this.report.status = 'fail';
      this.report.error = error.stack || String(error);
      process.exitCode = 1;
    } finally {
      fs.writeFileSync(path.join(this.output, 'report.json'), JSON.stringify(this.report, null, 2) + '\n');
      console.log(JSON.stringify({ status: this.report.status, report: path.join(this.output, 'report.json'), browsers: Object.fromEntries(Object.entries(this.report.browsers).map(([name, value]) => [name, { version: value.version, comparisons: value.comparisons, failures: value.failures?.length }])) }));
    }
  }

  async browser(name, type, html, witnesses, layoutHtml, layoutFixture) {
    const record = this.report.browsers[name] = { passed: false, media: 'print', comparisons: 0, failures: [], witnesses: [], blockedRequests: [] };
    let browser;
    try {
      browser = await type.launch({ headless: true });
      record.version = browser.version();
      const context = await browser.newContext({ javaScriptEnabled: false, serviceWorkers: 'block', viewport: { width: 1000, height: 1000 }, deviceScaleFactor: 1 });
      await context.route('**/*', async route => {
        record.blockedRequests.push(route.request().url());
        await route.abort();
      });
      const page = await context.newPage();
      await page.emulateMedia({ media: 'print' });
      await page.setContent(html, { waitUntil: 'load', timeout: 30_000 });
      const actuals = await page.evaluate(rows => rows.map(row => {
        const element = document.getElementById(row.id);
        if (!element) throw new Error(`Missing browser witness ${row.id}`);
        const computed = getComputedStyle(element);
        const values = Object.fromEntries(Object.keys(row.values).map(property => [property, computed.getPropertyValue(property)]));
        const bounds = element.getBoundingClientRect();
        return { id: row.id, values, geometry: { width: bounds.width * 0.75, height: bounds.height * 0.75 } };
      }), witnesses);
      for (const [index, expected] of witnesses.entries()) {
        const actual = actuals[index];
        assert.equal(actual.id, expected.id);
        const checks = [];
        for (const [property, target] of Object.entries(expected.values)) {
          const raw = actual.values[property];
          const observed = this.normalize(raw, target);
          checks.push(this.compare(record, expected.id, property, target, observed, raw));
        }
        if (expected.geometry !== null) {
          for (const dimension of ['width', 'height']) {
            checks.push(this.compare(record, expected.id, `borderBox.${dimension}`, expected.geometry[dimension], actual.geometry[dimension]));
          }
        }
        record.witnesses.push({ id: expected.id, tier: expected.tier, features: expected.features, checks, passed: checks.every(check => check.passed) });
      }
      assert.equal(record.blockedRequests.length, 0, 'Witnesses must not fetch external resources');
      const screenshot = `${name}-styles.png`;
      await page.screenshot({ path: path.join(this.output, screenshot), fullPage: true, animations: 'disabled' });
      record.screenshot = { file: screenshot, sha256: this.hash(fs.readFileSync(path.join(this.output, screenshot))) };
      await page.setContent(layoutHtml, { waitUntil: 'load', timeout: 30_000 });
      const boxes = await page.evaluate(rows => rows.map(row => {
        const element = document.getElementById(row.id);
        if (!element) throw new Error(`Missing geometry witness ${row.id}`);
        const bounds = element.getBoundingClientRect();
        return { id: row.id, values: { x: bounds.x * 0.75, y: bounds.y * 0.75, width: bounds.width * 0.75, height: bounds.height * 0.75 } };
      }), layoutFixture.witnesses);
      record.layout = { scope: layoutFixture.scope, witnesses: [] };
      for (const [index, expected] of layoutFixture.witnesses.entries()) {
        assert.equal(boxes[index].id, expected.id);
        const checks = Object.entries(expected.values).map(([dimension, target]) => this.compare(record, expected.id, `layout.${dimension}`, target, boxes[index].values[dimension]));
        record.layout.witnesses.push({ id: expected.id, checks, passed: checks.every(check => check.passed) });
      }
      assert.equal(record.blockedRequests.length, 0, 'Geometry witnesses must not fetch external resources');
      const layoutScreenshot = `${name}-layout.png`;
      await page.screenshot({ path: path.join(this.output, layoutScreenshot), fullPage: true, animations: 'disabled' });
      record.layout.screenshot = { file: layoutScreenshot, sha256: this.hash(fs.readFileSync(path.join(this.output, layoutScreenshot))) };
      record.passed = record.failures.length === 0;
    } catch (error) {
      record.failures.push({ error: error.stack || String(error) });
    } finally {
      if (browser) await browser.close();
    }
  }

  normalize(raw, target) {
    if (typeof target === 'number') {
      assert.match(raw, /^-?(?:\d+(?:\.\d+)?|\.\d+)px$/, `Expected a browser-computed CSS length, received ${raw}`);
      return Number.parseFloat(raw) * 0.75;
    }
    if (Array.isArray(target)) {
      const match = /^rgb\(\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)\s*\)$/.exec(raw);
      assert(match, `Expected an opaque sRGB browser color, received ${raw}`);
      return match.slice(1).map(Number);
    }
    return raw;
  }

  compare(record, id, property, expected, actual, raw = null) {
    const passed = typeof expected === 'number'
      ? Number.isFinite(actual) && Math.abs(expected - actual) <= 0.02
      : JSON.stringify(expected) === JSON.stringify(actual);
    const check = { property, expected, actual, ...(raw === null ? {} : { browserCss: raw }), passed };
    record.comparisons++;
    if (!passed) record.failures.push({ id, ...check });
    return check;
  }
}

if (process.argv.length !== 4) {
  console.error('Usage: node tools/validate_styles.cjs WITNESS_JSON OUTPUT_DIRECTORY');
  process.exitCode = 2;
} else {
  new StyleOracle(process.argv[2], process.argv[3]).run();
}
