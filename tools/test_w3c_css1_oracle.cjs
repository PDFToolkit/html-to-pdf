'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const { HistoricalCss1Oracle, FIXTURE } = require('./validate_w3c_css1.cjs');
const oracle = new HistoricalCss1Oracle();
const observations = { compatMode: 'CSS1Compat', elements: [...new Set([...Object.keys(oracle.expected.geometry), ...Object.keys(oracle.expected.properties)])].map(selector => ({
  selector, parent: selector.startsWith('#bar > form > p') ? 'FORM' : null,
  geometry: structuredClone(oracle.expected.geometry[selector] || {}), properties: structuredClone(oracle.expected.properties[selector] || {}),
})) };
assert.equal(oracle.compare(observations).passed, true);
assert.equal(oracle.compare(observations).comparisons, 63);
let tests = 1;
for (const selector of ['dt', '#bar', 'li:nth-child(3)', 'blockquote', 'body > p']) {
  const changed = structuredClone(observations);
  changed.elements.find(row => row.selector === selector).geometry.y += 2;
  assert.equal(oracle.compare(changed).passed, false, 'A misplaced box must not pass'); tests++;
}
for (const [selector, property, value] of [['li:nth-child(1)', 'display', 'list-item'], ['body > p', 'clear', 'none'], ['html', 'background-color', 'rgb(255, 255, 255)']]) {
  const changed = structuredClone(observations);
  changed.elements.find(row => row.selector === selector).properties[property] = value;
  assert.equal(oracle.compare(changed).passed, false); tests++;
}
assert.throws(() => oracle.compare({ ...observations, compatMode: 'BackCompat' })); tests++;
const missing = structuredClone(observations); missing.elements.shift();
assert.throws(() => oracle.compare(missing)); tests++;
const unwrapped = structuredClone(observations); unwrapped.elements.find(row => row.selector === '#bar > form > p:nth-child(1)').parent = 'LI';
assert.throws(() => oracle.compare(unwrapped)); tests++;
const temp = fs.mkdtempSync(path.join(os.tmpdir(), 'pdf-css1-oracle-'));
try {
  for (const file of ['test5526c.htm', 'expected.json']) fs.copyFileSync(path.join(FIXTURE, file), path.join(temp, file));
  fs.appendFileSync(path.join(temp, 'test5526c.htm'), '\n');
  assert.throws(() => new HistoricalCss1Oracle(temp), /exact unmodified/); tests++;
  fs.copyFileSync(path.join(FIXTURE, 'test5526c.htm'), path.join(temp, 'test5526c.htm'));
  const expected = structuredClone(oracle.expected); delete expected.geometry.dd;
  fs.writeFileSync(path.join(temp, 'expected.json'), JSON.stringify(expected));
  assert.throws(() => new HistoricalCss1Oracle(temp), /Complete box inventory/); tests++;
} finally { fs.rmSync(temp, { recursive: true, force: true }); }
console.log(JSON.stringify({ status: 'pass', tests, scope: 'Exact source, legacy standards mode, complete witnesses and rejected layout/style regressions' }));
