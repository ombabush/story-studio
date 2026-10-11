// Hit-test conformance of the Reader's geometry (docs/FORMAT.md, «Geometry»):
// boundary, inside/outside, holes (evenodd), curves, stretch X/Y, and the SVG path canonicaliser.
// Run: node --test tests/js
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const src = readFileSync(new URL('../../resources/dist/story-studio.js', import.meta.url), 'utf8');
globalThis.window = {};
globalThis.document = { querySelector: () => null, addEventListener() {} };
new Function(src)();
const G = window.__ssIXGeo;
const canonical = window.__ssCanonicalPath;
const fixture = JSON.parse(readFileSync(new URL('../../resources/fixtures/tablet-story/interaction.json', import.meta.url), 'utf8'));
const shapeOf = (id) => fixture.pages.flatMap((p) => p.interactions).find((i) => i.id === id).anchor.shape;

test('rect: inside, boundary, outside', () => {
  const r = { type: 'rect', x: 100, y: 100, width: 200, height: 100 };
  assert.ok(G.contains(r, 150, 150));
  assert.ok(G.contains(r, 100, 100));
  assert.ok(!G.contains(r, 99.9, 150));
});

test('ellipse: inside and outside its box corner', () => {
  const e = { type: 'ellipse', cx: 500, cy: 500, rx: 100, ry: 50 };
  assert.ok(G.contains(e, 590, 500));
  assert.ok(!G.contains(e, 590, 540));
});

test('the fixture silhouette: body hits, the hole does not (evenodd)', () => {
  const ilya = shapeOf('PIER_ILYA_PROFILE');
  assert.ok(G.contains(ilya, 500, 300));
  assert.ok(!G.contains(ilya, 425, 450));
  assert.ok(!G.contains(ilya, 150, 450));
});

test('nonzero keeps a same-direction inner ring filled', () => {
  const d = 'M 0 0 L 100 0 L 100 100 L 0 100 Z M 25 25 L 75 25 L 75 75 L 25 75 Z';
  assert.ok(G.contains({ type: 'path', d, fillRule: 'nonzero' }, 50, 50));
  assert.ok(!G.contains({ type: 'path', d, fillRule: 'evenodd' }, 50, 50));
});

test('curves are flattened, not ignored', () => {
  const c = { type: 'path', fillRule: 'evenodd', d: 'M 100 500 C 100 100 900 100 900 500 Z' };
  assert.ok(G.contains(c, 500, 300));
  assert.ok(!G.contains(c, 500, 150));
});

test('normalised geometry is independent of the screen: stretch X/Y maps the same source point', () => {
  const ilya = shapeOf('PIER_ILYA_PROFILE');
  for (const [w, h] of [[800, 1100], [1600, 2200], [400, 1100], [1600, 550]]) {
    const sx = 500 / 1000 * w, sy = 300 / 1000 * h;          // a source point on a page of w×h pixels
    assert.ok(G.contains(ilya, sx / w * 1000, sy / h * 1000), `${w}×${h}`);
  }
});

test('svg output carries numbers only', () => {
  assert.equal(G.svg({ type: 'path', d: 'M 0 0 L 1 1 Z" onload="x', fillRule: 'evenodd' }), '');
  assert.match(G.svg({ type: 'rect', x: 1, y: 2, width: 3, height: 4 }), /^<rect x="1.00"/);
});

test('canonicaliser: relative, H/V, S/T and scaling; arcs refused', () => {
  assert.equal(canonical('M10 10 l 100 0 v 50 h -100 z', 1000, 1000), 'M 10 10 L 110 10 L 110 60 L 10 60 Z');
  assert.equal(canonical('M 10 10 H 50 V 40 L 10 40 Z', 100, 100), 'M 100 100 L 500 100 L 500 400 L 100 400 Z');
  assert.throws(() => canonical('M 0 0 A 5 5 0 0 1 10 10 Z', 100, 100), /Дуги/);
});
