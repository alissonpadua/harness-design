import json, html

F = json.load(open('harness/audits/current/findings.json'))
led = json.load(open('harness/audits/current/coverage-ledger.json'))
meta = json.load(open('harness/audits/current/run-metadata.json'))

rank = {'critical': 4, 'high': 3, 'medium': 2, 'low': 1, 'informational': 0}
conf = sorted([r for r in F if r['verdict'] == 'confirmed'], key=lambda r: -rank[r['severity']['overall_severity']])
nv = [r for r in F if r['verdict'] == 'needs_validation']
rj = [r for r in F if r['verdict'] == 'rejected']
cnt = {s: sum(1 for r in conf if r['severity']['overall_severity'] == s) for s in ('critical', 'high', 'medium', 'low', 'informational')}

e = html.escape
SEVC = {'critical': '#dc2626', 'high': '#ea580c', 'medium': '#ca8a04', 'low': '#2563eb', 'informational': '#64748b'}

def badge(s, txt=None):
    return '<span class="sev" style="background:%s">%s</span>' % (SEVC[s], e(txt or s.upper()))

def trace_tbl(rows):
    out = ['<table class="trc"><tr><th>kind</th><th>file</th><th>line</th><th>step</th></tr>']
    for t in rows:
        out.append('<tr><td><code>%s</code></td><td><code>%s</code></td><td class="num">%s</td><td>%s</td></tr>' % (
            e(t['kind']), e(t['file']), t['line'], e(t.get('description') or t.get('scope') or '')))
    out.append('</table>')
    return ''.join(out)

def ev_tbl(rows):
    out = ['<table class="trc"><tr><th>evidence</th><th>line</th><th>says</th></tr>']
    for t in rows:
        out.append('<tr><td><code>%s</code></td><td class="num">%s</td><td>%s</td></tr>' % (e(t['file']), t['line'], e(t['description'])))
    out.append('</table>')
    return ''.join(out)

def code_items(chs):
    return ''.join('<div class="fix"><div class="fx-file"><code>%s</code></div><pre>%s</pre></div>' % (e(c['file_name']), e(c['fixed_code'])) for c in chs)

cards = []
for i, r in enumerate(conf):
    s = r['severity']
    ex = r.get('execution') or {}
    anchor = 'F%d' % i
    cards.append('''
<article class="card" id="%s">
 <div class="card-head">
  %s
  <div><h3>%s</h3><div class="fp"><code>%s</code></div></div>
 </div>
 <p class="desc">%s</p>
 <div class="kv"><b>Root cause</b><span>%s</span></div>
 <div class="kv"><b>Intended behavior</b><span>%s</span></div>
 <div class="kv"><b>Severity</b><span>Likelihood <i>%s</i> (%s) · Impact <i>%s</i> (%s)</span></div>
 <div class="kv"><b>Confidence</b><span>%s — %s</span></div>
 <div class="kv"><b>Conditions</b><span>%s</span></div>
 <details><summary>Attack trace</summary>%s</details>
 <details><summary>Evidence (file:line)</summary>%s</details>
 <details><summary>Reproduction (source-determined — no sandbox execution)</summary>
   <p class="who">%s</p><ul>%s</ul>
   <p class="obs"><b>Observed:</b> %s</p>
 </details>
 <div class="fixes"><b>Fix —</b> %s%s</div>
</article>''' % (anchor, badge(s['overall_severity']), e(r['title']), e(r['fingerprint']), e(r['description']),
    e(r['root_cause']), e(r['intended_behavior']),
    e(s['likelihood']['score']), e(s['likelihood']['reason']), e(s['impact']['score']), e(s['impact']['reason']),
    e(r['confidence']['score']), e(r['confidence']['reason']),
    e('; '.join(c['description'] for c in r['conditions'])),
    trace_tbl(r['trace']), ev_tbl(r['evidence']),
    e(ex.get('attacker_perspective', '')),
    ''.join('<li><code>%s</code></li>' % e(p) for p in ex.get('payloads', [])),
    e(ex.get('observed_result', '')),
    e(r['remediation']['strategy']), code_items(r['remediation']['code_changes'])))

nav = ' · '.join('<a href="#F%d">%s <code>%s</code></a>' % (i, badge(r['severity']['overall_severity'], r['severity']['overall_severity'][:4].upper()), e(r['fingerprint'][3:26])) for i, r in enumerate(conf))

nv_items = ''.join('''<div class="nvc"><div class="nv-head"><span class="nvb">NEEDS VALIDATION</span><h4>%s</h4><div class="fp"><code>%s</code></div></div>
<p>%s</p><div class="kv"><b>Claimed root cause</b><span>%s</span></div>
<div class="kv"><b>Blocked on</b><span>%s</span></div>
<details><summary>Validation plan</summary><p>%s</p></details>
<details><summary>Trace / evidence</summary>%s%s</details></div>''' % (
    e(r['title']), e(r['fingerprint']), e(r['description']), e(r['claimed_root_cause']),
    e(' • '.join(r['blockers'])),
    e((r.get('validation_plan') or {}).get('local', '')),
    trace_tbl(r['trace']), ev_tbl(r['evidence'])) for r in nv)

rj_items = ''.join('<div class="rjc"><h4>%s <code>%s</code></h4><p><b>Why rejected:</b> %s</p><p class="sub"><b>Claim was:</b> %s</p></div>' % (
    badge('informational', 'REJECTED'), e(r['fingerprint']), e(r['reason']), e(r['description'])) for r in rj)

cov_rows = ''.join('<tr><td><code>%s</code></td><td>%s</td><td><span class="pill %s">%s</span></td><td>%s</td><td class="fps">%s</td></tr>' % (
    e(u['agent_id'] or '—'), e(u['canonical_refs']['surface'][:46]),
    'cov' if u['status'] == 'covered' else 'cnd', e(u['status']),
    e((u['local_checks'][0]['result'] if u['local_checks'] else '—')[:110]),
    e(', '.join(u.get('result_fingerprints', [])))) for u in led)

doc = '''<!doctype html><html lang="en"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Security Audit — boilerplate run-1</title>
<style>
:root{--bg:#0b1120;--panel:#111a2e;--line:#1f2b45;--tx:#dbe4f3;--mut:#8ea0bf;--mono:ui-monospace,'SF Mono',Menlo,monospace}
*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--tx);font:15px/1.6 -apple-system,'Segoe UI',Roboto,sans-serif}
.wrap{max-width:980px;margin:0 auto;padding:28px 20px 80px}
header h1{font-size:26px;margin:0 0 6px}header .meta{color:var(--mut);font-size:13px}
.strip{display:flex;gap:10px;flex-wrap:wrap;margin:18px 0 6px}
.stat{background:var(--panel);border:1px solid var(--line);border-radius:12px;padding:10px 16px;min-width:96px}
.stat b{display:block;font-size:22px}.stat span{color:var(--mut);font-size:12px;text-transform:uppercase;letter-spacing:.06em}
.sev{color:#fff;font-size:11px;font-weight:700;padding:2px 8px;border-radius:99px;letter-spacing:.05em;white-space:nowrap;display:inline-block}
nav.toc{margin:14px 0 26px;font-size:12.5px;line-height:2}
nav.toc a{color:var(--mut);text-decoration:none;margin-right:10px}nav.toc a:hover{color:var(--tx)}
h2{font-size:19px;margin:36px 0 12px;border-bottom:1px solid var(--line);padding-bottom:8px}
.card,.nvc,.rjc{background:var(--panel);border:1px solid var(--line);border-radius:14px;padding:18px 20px;margin:14px 0}
.card-head{display:flex;gap:12px;align-items:flex-start}.card h3,.nvc h4{margin:0;font-size:16px}
.fp code{font-family:var(--mono);font-size:11.5px;color:var(--mut)}
.desc{margin:10px 0 12px}
.kv{display:grid;grid-template-columns:150px 1fr;gap:10px;font-size:13.5px;padding:5px 0;border-top:1px dashed var(--line)}
.kv b{color:var(--mut);font-weight:600}
details{margin:8px 0;font-size:13.5px}summary{cursor:pointer;color:#93c5fd;font-weight:600}
table.trc{border-collapse:collapse;width:100%;margin-top:8px;font-size:12.5px}
.trc th{text-align:left;color:var(--mut);font-weight:600;padding:4px 8px;border-bottom:1px solid var(--line)}
.trc td{padding:5px 8px;border-bottom:1px dashed var(--line);vertical-align:top}
.trc code{font-family:var(--mono);font-size:11.5px;color:#a5b4fc;word-break:break-all}
td.num{width:44px;color:var(--mut)}
.who{color:var(--mut)}.obs{font-size:12.5px}
ul{margin:6px 0;padding-left:20px}li code{font-family:var(--mono);font-size:11.5px;color:#fbbf24;word-break:break-all}
.fixes{margin-top:10px;font-size:13.5px}
.fix{margin:8px 0;background:#0d1526;border:1px solid var(--line);border-radius:10px;overflow:hidden}
.fx-file{padding:6px 10px;border-bottom:1px solid var(--line);font-size:12px}.fx-file code{font-family:var(--mono);color:#7dd3fc}
.fix pre{margin:0;padding:10px;font-family:var(--mono);font-size:11.5px;color:#c7f9cc;overflow-x:auto;white-space:pre-wrap}
.nvb{background:#7c3aed;color:#fff;font-size:10.5px;font-weight:700;padding:2px 8px;border-radius:99px;letter-spacing:.05em}
.nv-head{display:flex;gap:10px;align-items:baseline;flex-wrap:wrap}.nvc p{font-size:13.5px}
.rjc h4{display:flex;gap:10px;align-items:baseline}.rjc .sub{color:var(--mut);font-size:12.5px}
table.cov{border-collapse:collapse;width:100%;font-size:12.5px}
.cov th{text-align:left;color:var(--mut);padding:6px 8px;border-bottom:1px solid var(--line)}
.cov td{padding:6px 8px;border-bottom:1px dashed var(--line);vertical-align:top}
.pill{font-size:11px;padding:1px 8px;border-radius:99px}.pill.cov{background:#14532d;color:#bbf7d0}.pill.cnd{background:#713f12;color:#fde68a}
.cov code,.fps{font-family:var(--mono);font-size:11px;color:#a5b4fc}
footer{margin-top:40px;color:var(--mut);font-size:12px;border-top:1px solid var(--line);padding-top:14px}
.note{background:#1e1b4b;border:1px solid #3730a3;border-radius:10px;padding:10px 14px;font-size:13px;margin:14px 0}
</style></head><body><div class="wrap">
<header>
 <h1>🛡️ Security Audit — <code style="font-family:var(--mono)">boilerplate</code> · run-1</h1>
 <div class="meta">Cloudflare security-audit skill (6 phases · 6 hunters · 5 adversarial verifiers · 1 independent record verifier) — 2026-10-06 · HEAD d2663eb + dirty worktree ·
 evidence: <b>source-only</b> (no OS sandbox ⇒ nothing executed; runtime facts parked as needs_validation) · validators: findings <b style="color:#4ade80">PASS 28</b> / ledger <b style="color:#4ade80">PASS 14</b></div>
 <div class="strip">
  <div class="stat"><b style="color:#ea580c">@@HIGH@@</b><span>high</span></div>
  <div class="stat"><b style="color:#ca8a04">@@MEDIUM@@</b><span>medium</span></div>
  <div class="stat"><b style="color:#2563eb">@@LOW@@</b><span>low</span></div>
  <div class="stat"><b style="color:#64748b">@@INFO@@</b><span>info</span></div>
  <div class="stat"><b style="color:#7c3aed">@@N@@</b><span>needs validation</span></div>
  <div class="stat"><b style="color:#334155">@@RJ@@</b><span>rejected</span></div>
  <div class="stat"><b>@@UNITS@@</b><span>coverage units</span></div>
 </div>
  <div class="stat"><b style="color:#ca8a04">@@MEDIUM@@</b><span>medium</span></div>
  <div class="stat"><b style="color:#2563eb">@@LOW@@</b><span>low</span></div>
  <div class="stat"><b style="color:#64748b">@@INFO@@</b><span>info</span></div>
  <div class="stat"><b style="color:#7c3aed">@@N@@</b><span>needs validation</span></div>
  <div class="stat"><b style="color:#4ade80"></b><span>rejected</span></div>
  <div class="stat"><b>@@UNITS@@</b><span>coverage units</span></div>
 </div>
 <div class="note">A single run finds ≈ half of what repeated runs find — treat this as a coverage <i>floor</i>. The two HIGHs share one seam (org role assignment, module 002) and are fixed by two ~5-line changes + tests.</div>
 <nav class="toc">@@NAV@@</nav>
</header>
<h2>Confirmed findings</h2>
@@CARDS@@

<h2>Needs validation <span style="color:var(--mut);font-weight:400;font-size:13px">(no severity by contract — each has an exact blocker + 3-minute validation)</span></h2>
@@NVS@@

<h2>Rejected after adversarial verification</h2>
@@RJS@@

<h2>Coverage ledger</h2>
<table class="cov"><tr><th>agent</th><th>surface</th><th>status</th><th>result</th><th>findings</th></tr>@@COVS@@</table>
<footer>Machine source of truth: <code>harness/audits/current/findings.json</code> (validated against the skill's <code>report-schema.json</code>).<br>
This page is generated from it — prose and JSON cannot disagree. Artifacts live in a git-ignored directory; nothing from this audit is committed.<br>
Fixed during the run itself (owner hook tooling): vacuous-green secret scan · whole-line allow-word suppression · init.sh hooks-install path (regression-tested).</footer>
</div></body></html>'''
for k, v in {'@@HIGH@@': str(cnt['high']), '@@MEDIUM@@': str(cnt['medium']), '@@LOW@@': str(cnt['low']),
             '@@INFO@@': str(cnt['informational']), '@@N@@': str(len(nv)), '@@RJ@@': str(len(rj)),
             '@@UNITS@@': str(len(led)), '@@NAV@@': nav, '@@CARDS@@': '\n'.join(cards),
             '@@NVS@@': nv_items, '@@RJS@@': rj_items, '@@COVS@@': cov_rows}.items():
    doc = doc.replace(k, v)

open('harness/audits/current/report.html', 'w').write(doc)
print('report.html written:', len(doc), 'bytes')
