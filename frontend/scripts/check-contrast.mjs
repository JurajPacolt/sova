#!/usr/bin/env node
/**
 * Measures the design tokens against WCAG 2.2 AA, in both themes.
 *
 * The palette is the one place where an accessibility rule can be broken by a
 * single hex digit with nothing in a type checker noticing, so the ratios are
 * computed from `src/styles.scss` itself rather than from a table somebody keeps
 * in step by hand. It runs as part of `npm run check`: a token that stops
 * meeting its requirement fails the gate.
 *
 * Plain Node, no dependencies — the unit-test builder cannot load a stylesheet
 * from a spec, and a checker is not worth a new tool in the toolchain.
 */

import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const here = dirname(fileURLToPath(import.meta.url));
const stylesheet = join(here, '..', 'src', 'styles.scss');

/**
 * Text is 4.5:1 (1.4.3). A boundary or indicator that carries meaning is 3:1
 * (1.4.11): the edge of a form control, the focus ring, the outline that marks
 * a widget as movable, and the chart series, which is the only thing telling
 * two lines apart.
 */
const REQUIREMENTS = [
  ['text', 'canvas', 4.5, 'body text'],
  ['text', 'surface', 4.5, 'body text on a card'],
  ['text-muted', 'canvas', 4.5, 'secondary text'],
  ['text-muted', 'surface', 4.5, 'secondary text on a card'],
  ['text-subtle', 'canvas', 4.5, 'small print'],
  ['text-subtle', 'surface', 4.5, 'small print on a card'],
  ['link', 'canvas', 4.5, 'links'],
  ['link', 'surface', 4.5, 'links on a card'],
  ['on-primary', 'action-primary', 4.5, 'primary button label'],
  ['action-primary', 'surface', 3, 'primary button surface'],
  ['control-border', 'surface', 3, 'input edges'],
  ['border-strong', 'surface', 3, 'edit-mode outline'],
  ['focus', 'canvas', 3, 'focus ring'],
  ['focus', 'surface', 3, 'focus ring on a card'],
  ['chart-series-1', 'surface', 3, 'first chart series'],
  ['chart-series-2', 'surface', 3, 'second chart series'],
  ['chart-categorical-1', 'surface', 3, 'ring slice 1'],
  ['chart-categorical-2', 'surface', 3, 'ring slice 2'],
  ['chart-categorical-3', 'surface', 3, 'ring slice 3'],
  ['chart-categorical-4', 'surface', 3, 'ring slice 4'],
  ['chart-categorical-5', 'surface', 3, 'ring slice 5'],
  ['chart-categorical-6', 'surface', 3, 'ring slice 6'],
  ['chart-categorical-rest', 'surface', 3, 'the remainder slice'],
];

/** Reference pairs, so a broken formula fails here rather than passing a palette. */
const SELF_CHECK = [
  ['#000000', '#ffffff', 21],
  ['#767676', '#ffffff', 4.54],
  ['#ffffff', '#ffffff', 1],
];

function blockOf(css, opening) {
  const match = opening.exec(css);

  if (match === null) {
    return '';
  }

  const start = match.index + match[0].length;
  const end = css.indexOf('\n}', start);

  return end === -1 ? css.slice(start) : css.slice(start, end);
}

function declarations(block) {
  const found = {};

  for (const [, name, value] of block.matchAll(/(--[\w-]+):\s*([^;]+);/gu)) {
    found[name] = value.trim();
  }

  return found;
}

function readThemes(css) {
  const base = declarations(blockOf(css, /:root\s*\{/gu));
  const light = declarations(blockOf(css, /:root,\s*\[data-bs-theme='light'\]\s*\{/gu));
  const dark = declarations(blockOf(css, /\[data-bs-theme='dark'\]\s*\{/gu));

  return { light: { ...base, ...light }, dark: { ...base, ...dark } };
}

/** Follows `var(--x)` until a literal colour, or `null` when there is none. */
function resolveColor(tokens, name) {
  const seen = new Set();
  let key = name;
  let value = tokens[key];

  while (typeof value === 'string' && value.startsWith('var(')) {
    if (seen.has(key)) {
      return null;
    }

    seen.add(key);
    const reference = /var\((--[\w-]+)\)/u.exec(value);

    if (reference === null) {
      return null;
    }

    key = reference[1];
    value = tokens[key];
  }

  return typeof value === 'string' && /^#[0-9a-f]{6}$/iu.test(value.trim())
    ? value.trim().toLowerCase()
    : null;
}

function luminance(color) {
  const channels = [1, 3, 5]
    .map((offset) => Number.parseInt(color.slice(offset, offset + 2), 16) / 255)
    .map((channel) => (channel <= 0.03928 ? channel / 12.92 : ((channel + 0.055) / 1.055) ** 2.4));

  return 0.2126 * channels[0] + 0.7152 * channels[1] + 0.0722 * channels[2];
}

function contrastRatio(first, second) {
  const lighter = Math.max(luminance(first), luminance(second));
  const darker = Math.min(luminance(first), luminance(second));

  return (lighter + 0.05) / (darker + 0.05);
}

let failures = 0;

for (const [first, second, expected] of SELF_CHECK) {
  const measured = contrastRatio(first, second);

  if (Math.abs(measured - expected) > 0.02) {
    console.error(
      `self-check failed: ${first} on ${second} is ${measured.toFixed(2)}, expected ${expected}`,
    );
    failures += 1;
  }
}

const themes = readThemes(readFileSync(stylesheet, 'utf8'));

for (const mode of ['light', 'dark']) {
  console.log(`\n${mode}`);

  for (const [foreground, background, minimum, because] of REQUIREMENTS) {
    const first = resolveColor(themes[mode], `--sova-color-${foreground}`);
    const second = resolveColor(themes[mode], `--sova-color-${background}`);

    if (first === null || second === null) {
      console.error(
        `  MISSING ${foreground} on ${background} — a token does not resolve to a colour`,
      );
      failures += 1;

      continue;
    }

    const measured = contrastRatio(first, second);
    const passed = measured >= minimum;
    failures += passed ? 0 : 1;

    console.log(
      `  ${passed ? 'ok  ' : 'FAIL'} ${foreground} on ${background}: ` +
        `${measured.toFixed(2)}:1 (needs ${minimum}:1 — ${because})`,
    );
  }
}

if (failures > 0) {
  console.error(`\n${failures} contrast requirement(s) not met.`);
  process.exit(1);
}

console.log('\nEvery token meets its WCAG 2.2 AA requirement in both themes.');
