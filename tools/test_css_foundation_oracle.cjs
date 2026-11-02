'use strict';

// Negative qualification gates: missing checks or changed source bytes cannot produce a pass.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const { CssFoundationOracle } = require('./css_foundation_oracle.cjs');

if (process.argv.length !== 3) throw new Error('Usage: node tools/test_css_foundation_oracle.cjs WITNESS_JSON');
const input = path.resolve(process.argv[2]);
const source = JSON.parse(fs.readFileSync(input));
const original = source.css1FoundationFixture;
const build = fixture => new CssFoundationOracle(input, '/unused', fixture);
build(original);
let count = 1;
const rejects = mutate => {
  const fixture = structuredClone(original);
  mutate(fixture);
  assert.throws(() => build(fixture));
  count++;
};
rejects(fixture => fixture.witnesses.pop());
rejects(fixture => { fixture.witnesses[0].values = {}; });
rejects(fixture => { fixture.diagnostics = []; });
rejects(fixture => fixture.resources.pop());
rejects(fixture => { fixture.htmlSha256 = '0'.repeat(64); });
rejects(fixture => { fixture.resources[0].sha256 = '0'.repeat(64); });
rejects(fixture => { fixture.resources[0].file = '../../outside.css'; });
rejects(fixture => { fixture.witnesses.find(row => row.id === 'asymmetric').geometry = null; });
rejects(fixture => { fixture.witnesses.find(row => row.id === 'asymmetric').geometry.width = Number.NaN; });

const temporary = fs.mkdtempSync(path.join(os.tmpdir(), 'pdftoolkit-css-oracle-'));
try {
  for (const file of [original.htmlFile, ...original.resources.map(row => row.file)]) {
    const target = path.join(temporary, file);
    fs.mkdirSync(path.dirname(target), { recursive: true });
    fs.copyFileSync(path.join(path.dirname(input), file), target);
  }
  const copy = path.join(temporary, 'styles.json');
  new CssFoundationOracle(copy, '/unused', original);
  const css = path.join(temporary, original.resources[0].file);
  fs.appendFileSync(css, '\n/* changed after export */');
  assert.throws(() => new CssFoundationOracle(copy, '/unused', original));
  count++;
  fs.unlinkSync(css);
  assert.throws(() => new CssFoundationOracle(copy, '/unused', original));
  count++;
  fs.symlinkSync(path.join(path.dirname(input), original.resources[0].file), css);
  assert.throws(() => new CssFoundationOracle(copy, '/unused', original));
  count++;
} finally {
  fs.rmSync(temporary, { recursive: true });
}
console.log(JSON.stringify({ status: 'pass', tests: count, scope: 'CSS foundation oracle source identity, inventory completeness, geometry and explicit resource guards' }));
