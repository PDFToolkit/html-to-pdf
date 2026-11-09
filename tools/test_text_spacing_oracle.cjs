'use strict';

const assert = require('node:assert/strict');
const { CASES, DELTAS, compare } = require('./validate_text_spacing.cjs');

function positive() {
  const rows = Object.fromEntries(CASES.map(name => [name, { letters: [0, 10, 20, 30].map(x => ({ x })), block: { x: 40 }, lines: [], characters: [] }]));
  for (const [name, , addition] of DELTAS) rows[name].letters = [0, 1, 2, 3].map(index => ({ x: index * (10 + addition) }));
  for (const [name, indent] of [['indent-positive', 24], ['indent-negative', -12], ['indent-percent', 16]]) rows[name].lines = [{ x: 40 + indent }, { x: 40 }, { x: 40 }];
  rows['combining-normal'].characters = [{ text: 'a', x: 40 }, { text: '́', x: 40 }, { text: 'b', x: 50 }];
  rows['combining-positive'].characters = [{ text: 'a', x: 40 }, { text: '́', x: 40 }, { text: 'b', x: 52 }];
  return rows;
}

let controls = 0;
assert(compare(positive()).passed); ++controls;
for (const [name, control] of DELTAS) {
  const rows = positive(); rows[name].letters = structuredClone(rows[control].letters);
  assert(!compare(rows).passed, 'Missing spacing must fail: ' + name); ++controls;
}
for (const [name, index] of [['indent-positive', 0], ['indent-negative', 0], ['indent-percent', 0], ['indent-positive', 1]]) {
  const rows = positive(); rows[name].lines[index].x += 10;
  assert(!compare(rows).passed, 'Wrong first/continuation line indent must fail'); ++controls;
}
{
  const rows = positive(); rows['combining-positive'].characters[2].x += 2;
  assert(!compare(rows).passed, 'Splitting a combining sequence must fail'); ++controls;
}
{
  const rows = positive(); delete rows['nbsp-positive'];
  assert.throws(() => compare(rows), /Complete fixture inventory/); ++controls;
}
console.log(JSON.stringify({ status: 'pass', controls }));
