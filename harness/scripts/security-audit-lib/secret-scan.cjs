#!/usr/bin/env node
/*
 * harness/scripts/security-audit-lib/secret-scan.cjs
 * Deterministic secret scanner used by the security-audit gate.
 * Reads a unified diff on stdin, inspects ADDED lines only, prints
 * "<file>\t<kind>" per hit, exit 0. Exit code 2 signals an internal
 * error so the caller can FAIL CLOSED (a silently-skipped secret scan
 * is worse than no scan).
 *
 * Rules mirror high-signal patterns (Stripe/AWS/GitHub/Slack/Google/PEM
 * + generic hardcoded credentials) with path + value allowlists so the
 * repo's intentional demo fixtures do not false-positive.
 */
'use strict';

const fs = require('fs');

const EXEMPT_PATH = new RegExp(
  [
    '^(tests|specs|docs)/',
    '\\.md$',
    '\\.env\\.example$',
    '^src/bruno/',
    '^harness/audits/',
    '(composer|package)(-lock)?\\.json$',
    '^\\.agents/skills/security-audit/',
    '^src/storage/',
  ].join('|')
);

const ALLOW_VALUE = /(__replace_me__|change[-_]?me|your[-_ ]|example|placeholder|dummy|sample|fake|lorem|xxxxxxxx|<[^>]+>|\$\{|\{\{|env\(|config\(|Str0ng!Passw0rd)/i;

const RULES = [
  ['stripe-live-key', /s[kr]_live_[A-Za-z0-9]{12,}/],
  ['aws-access-key-id', /\bAKIA[0-9A-Z]{16}\b/],
  ['github-token', /\bgh[pousr]_[A-Za-z0-9]{20,}\b|\bgithub_pat_[A-Za-z0-9_]{30,}\b/],
  ['slack-token', /\bxox[baprs]-[A-Za-z0-9-]{10,}\b/],
  ['google-api-key', /\bAIza[0-9A-Za-z_-]{30,}\b/],
  ['private-key-block', /-----BEGIN (?:RSA |EC |OPENSSH |DSA |ENCRYPTED )?PRIVATE KEY-----/],
  ['jwt-token', /\beyJ[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}\b/],
  [
    'generic-hardcoded-credential',
    /\b(?:api[_-]?key|apikey|secret|client[_-]?secret|access[_-]?token|refresh[_-]?token|auth[_-]?token|bearer|password|passwd)\b["']?\s*(?:=>|[:=])\s*["'`]([^"'`\s]{12,})["'`]/i,
  ],
];

function run(diff) {
  const hits = new Set();
  let file = null;
  for (const raw of diff.split('\n')) {
    if (raw.startsWith('+++ ')) {
      file = raw.slice(4).replace(/^b\//, '').trim();
      if (file === '/dev/null') file = null;
      continue;
    }
    if (!file || EXEMPT_PATH.test(file)) continue;
    if (!raw.startsWith('+') || raw.startsWith('+++')) continue;
    const line = raw.slice(1);
    for (const [kind, re] of RULES) {
      const m = line.match(re);
      if (!m) continue;
      const probe = m[1] !== undefined ? m[1] : m[0];
      if (ALLOW_VALUE.test(probe)) continue;
      if (kind === "generic-hardcoded-credential" && ALLOW_VALUE.test(line)) continue;
      hits.add(`${file}\t${kind}`);
    }
  }
  const out = [...hits].sort();
  if (out.length) process.stdout.write(out.join('\n') + '\n');
}

try {
  const data = fs.readFileSync(0, 'utf8');
  run(data);
} catch (e) {
  process.stderr.write('secret-scan: internal error: ' + e.message + '\n');
  process.exit(2);
}
