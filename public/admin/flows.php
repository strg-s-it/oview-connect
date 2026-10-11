<?php

declare(strict_types=1);

/**
 * connect/public/admin/flows.php
 *
 * Ablauf-Ansicht eines Shops: jeder Ablauf (Rechnung, Lagerbestand,
 * Produktimport, Uebersetzungen, Daten-Jobs) als Flussdiagramm mit allen
 * Ausloesern (Webhook, Zeitplan, Button/Funktion), Verzweigungen und jeder
 * Einstellung, die einen Schritt beeinflusst -- mit Fundort und Link zur
 * Maske, in der sie gepflegt wird.
 *
 * Rein lesend (Stufe 1). Die Daten baut Connect\Flow\FlowInspector aus der
 * bestehenden Konfiguration; gezeichnet wird im Browser (kein externes
 * Script, nur Inline-SVG).
 */

use Connect\Flow\FlowInspector;

/** @var \Connect\AppContext $app */
$app = require dirname(__DIR__, 2) . '/admin_bootstrap.php';

$pdo = $app->pdo;
$shop = $app->shop;
$isAdmin = true;

$flowError = null;
try {
    $flows = (new FlowInspector($pdo))->forShop($shop);
} catch (\Throwable $e) {
    $flows = [];
    $flowError = 'Die Abläufe konnten nicht gelesen werden: ' . $e->getMessage();
}

$pageTitle = 'Abläufe';
$homeUrl = 'shops.php';
$adminNavItems = require __DIR__ . '/_nav.php';
$navItems = require dirname(__DIR__) . '/app/_shop_nav.php';
$adminEmail = (new \Connect\Auth\AdminAuth($pdo))->currentEmail();
$topRightHtml = '<span class="text-secondary me-3">' . htmlspecialchars((string) $adminEmail) . '</span>'
    . '<a href="logout.php" class="btn btn-sm btn-outline-secondary">Logout</a>';
$flashMessage = $flowError;
$flashType = 'danger';

require dirname(__DIR__) . '/app/_header.php';
?>

<link rel="stylesheet" href="../assets/flow/flow.css">

<?php if ($flows === [] && $flowError === null): ?>
    <div class="card"><div class="card-body text-secondary">Für diesen Shop ist noch kein Ablauf eingerichtet. Abläufe entstehen in den Shop-Einstellungen (Rechnungen, Lagerbestand), unter Mappings oder als Job.</div></div>
<?php else: ?>
    <div class="flow-tabs" role="tablist" aria-label="Abläufe dieses Shops" id="flow-tabs"></div>
    <div class="flow-summary" id="flow-summary" aria-live="polite"></div>
    <div id="flow-body"></div>
    <div class="flow-legend" aria-label="Legende">
        <span class="st-ok"><i class="flow-dot"></i>vollständig</span>
        <span class="st-warn"><i class="flow-dot"></i>Hinweis</span>
        <span class="st-err"><i class="flow-dot"></i>fehlt / blockiert</span>
        <span class="st-opt"><i class="flow-dot"></i>optional / aus</span>
        <span><i class="dash"></i>Nebenweg</span>
        <span>Fundort:</span>
        <span class="flow-src src-shop">Shop-Einstellung</span><span class="flow-src src-act">Aktivierung / Job</span><span class="flow-src src-prof">Belegprofil</span>
        <span class="flow-src src-tpl">Vorlage</span><span class="flow-src src-obj">Object/Feld</span><span class="flow-src src-fn">Funktion</span><span class="flow-src src-fix">fest eingebaut</span>
    </div>
    <script type="application/json" id="flow-data"><?php echo json_encode($flows, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE); ?></script>
    <script>
    (function () {
        var FLOWS = JSON.parse(document.getElementById('flow-data').textContent);
        var ICONS = {
            bolt: '<path d="M13 2 4 14h7l-1 8 9-12h-7z"/>',
            clock: '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
            hand: '<path d="M8 13V5a2 2 0 0 1 4 0v6"/><path d="M12 11V4a2 2 0 0 1 4 0v8"/><path d="M16 9a2 2 0 0 1 4 0v5a7 7 0 0 1-7 7h-1a7 7 0 0 1-6-3.4L3.6 14a2 2 0 0 1 3.2-2.4L8 13"/>',
            filter: '<path d="M3 5h18l-7 8v6l-4 2v-8z"/>',
            gear: '<circle cx="12" cy="12" r="3"/><path d="M12 2v3M12 19v3M4.2 4.2l2.1 2.1M17.7 17.7l2.1 2.1M2 12h3M19 12h3M4.2 19.8l2.1-2.1M17.7 6.3l2.1-2.1"/>',
            check: '<path d="M9 12l2 2 4-4"/><path d="M12 3l7 3v6c0 4.5-3 7.5-7 9-4-1.5-7-4.5-7-9V6z"/>',
            search: '<circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/>',
            userplus: '<circle cx="9" cy="8" r="4"/><path d="M2 21c0-3.9 3.1-7 7-7s7 3.1 7 7"/><path d="M19 8v6M16 11h6"/>',
            columns: '<path d="M4 5h6v14H4zM14 5h6v14h-6z"/><path d="M10 9h4M10 15h4"/>',
            doc: '<path d="M14 3H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z"/><path d="M14 3v6h6M8 13h8M8 17h5"/>',
            mail: '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/>',
            stop: '<circle cx="12" cy="12" r="9"/><path d="M12 7v6M12 16.5v.5"/>',
            box: '<path d="M21 8 12 3 3 8v8l9 5 9-5z"/><path d="M3 8l9 5 9-5M12 13v8"/>',
            swap: '<path d="M7 4 3 8l4 4M3 8h14M17 20l4-4-4-4M21 16H7"/>',
            db: '<ellipse cx="12" cy="6" rx="8" ry="3"/><path d="M4 6v12c0 1.7 3.6 3 8 3s8-1.3 8-3V6M4 12c0 1.7 3.6 3 8 3s8-1.3 8-3"/>'
        };
        var STATUS_TEXT = { ok: 'Vollständig eingerichtet.', warn: 'Eingerichtet, mit Hinweis.', err: 'Fehlt – blockiert den Ablauf.', opt: 'Optional oder ausgeschaltet.' };
        var STATUS_SHORT = { ok: 'vollständig', warn: 'Hinweis', err: 'fehlt', opt: 'optional / aus' };
        var SRC_LABEL = { shop: 'Shop-Einstellung', act: 'Aktivierung / Job', prof: 'Belegprofil', tpl: 'Vorlage', obj: 'Object/Feld', fn: 'Funktion', fix: 'fest eingebaut' };
        var SRC_ORDER = ['shop', 'act', 'prof', 'tpl', 'obj', 'fn', 'fix'];
        var GLOBAL_SRC = { prof: true, tpl: true, obj: true, fn: true };
        var RANK = { err: 3, warn: 2, ok: 1, opt: 0 };
        var COL_X = [24, 444], W_MAIN = 280, W_SIDE = 250, W_TRIG = 250, H = 86, ROW = 128, TOP = 30;

        var state = { flow: FLOWS.length ? FLOWS[0].key : null, view: 'diagram', selected: null };
        var hash = (location.hash || '').replace('#', '');
        FLOWS.forEach(function (f) { if (f.key === hash) state.flow = hash; });

        function el(tag, cls, text) { var e = document.createElement(tag); if (cls) e.className = cls; if (text != null) e.textContent = text; return e; }
        function safeUrl(url) { return typeof url === 'string' && /^[a-z_]+\.php(\?[^"<>]*)?$/.test(url) ? url : null; }
        function currentFlow() { for (var i = 0; i < FLOWS.length; i++) { if (FLOWS[i].key === state.flow) return FLOWS[i]; } return FLOWS[0]; }
        function worst(flow) { var w = 'opt'; Object.keys(flow.nodes).forEach(function (k) { if (RANK[flow.nodes[k].status] > RANK[w]) w = flow.nodes[k].status; }); return w; }

        function layout(flow) {
            var pos = {}, maxX = 0, maxY = 0;
            Object.keys(flow.nodes).forEach(function (k) {
                var n = flow.nodes[k], x, w;
                if (n.lane === 'trigger') { w = W_TRIG; x = 24 + n.slot * (W_TRIG + 24); }
                else { w = n.col === 1 ? W_SIDE : W_MAIN; x = COL_X[n.col] || 24; }
                var y = TOP + n.row * ROW;
                pos[k] = { x: x, y: y, w: w };
                maxX = Math.max(maxX, x + w); maxY = Math.max(maxY, y + H);
            });
            return { pos: pos, w: maxX + 24, h: maxY + 28 };
        }
        function anchor(p, side) {
            if (side === 'bottom') return [p.x + p.w / 2, p.y + H];
            if (side === 'top') return [p.x + p.w / 2, p.y];
            if (side === 'right') return [p.x + p.w, p.y + H / 2];
            return [p.x, p.y + H / 2];
        }
        function edgePath(e, pos) {
            var a = pos[e.from], b = pos[e.to], p, q, my;
            if (e.type === 'down') { p = anchor(a, 'bottom'); q = anchor(b, 'top'); return { d: 'M' + p[0] + ' ' + p[1] + ' L' + q[0] + ' ' + (q[1] - 2), lx: p[0] + 8, ly: (p[1] + q[1]) / 2 + 4 }; }
            if (e.type === 'side') { p = anchor(a, 'right'); q = anchor(b, 'left'); return { d: 'M' + p[0] + ' ' + p[1] + ' L' + (q[0] - 2) + ' ' + q[1], lx: (p[0] + q[0]) / 2, ly: p[1] - 8, center: true }; }
            if (e.type === 'merge') { p = anchor(a, 'bottom'); q = anchor(b, 'top'); my = p[1] + (q[1] - p[1]) / 2; return { d: 'M' + p[0] + ' ' + p[1] + ' C' + p[0] + ' ' + my + ' ' + q[0] + ' ' + my + ' ' + q[0] + ' ' + (q[1] - 2), lx: p[0] + 8, ly: p[1] + 16 }; }
            p = anchor(a, 'bottom'); q = anchor(b, 'right');
            return { d: 'M' + p[0] + ' ' + p[1] + ' L' + p[0] + ' ' + (q[1] - 10) + ' Q' + p[0] + ' ' + q[1] + ' ' + (p[0] - 10) + ' ' + q[1] + ' L' + (q[0] + 2) + ' ' + q[1], lx: p[0] + 8, ly: (p[1] + q[1]) / 2 };
        }

        function renderTabs() {
            var tabs = document.getElementById('flow-tabs');
            tabs.innerHTML = '';
            FLOWS.forEach(function (f) {
                var b = el('button', 'flow-tab st-' + worst(f)); b.type = 'button'; b.setAttribute('role', 'tab');
                b.setAttribute('aria-selected', f.key === state.flow ? 'true' : 'false');
                b.appendChild(el('i', 'flow-dot')); b.appendChild(document.createTextNode(f.label));
                b.addEventListener('click', function () { state.flow = f.key; state.selected = null; try { history.replaceState(null, '', '#' + f.key); } catch (e) {} render(); });
                tabs.appendChild(b);
            });
        }

        function renderSummary(flow) {
            var box = document.getElementById('flow-summary');
            box.innerHTML = '';
            var c = { ok: 0, warn: 0, err: 0, opt: 0 }, total = 0, settings = 0;
            Object.keys(flow.nodes).forEach(function (k) { c[flow.nodes[k].status]++; total++; settings += (flow.nodes[k].settings || []).length; });
            box.appendChild(el('strong', null, c.err > 0 ? 'Ablauf blockiert: ' + c.err + (c.err === 1 ? ' Schritt fehlt' : ' Schritte fehlen') : (c.warn > 0 ? 'Ablauf läuft, mit Hinweisen' : 'Ablauf einsatzbereit')));
            [['ok', c.ok + ' von ' + total + ' vollständig'], ['warn', c.warn + ' Hinweis' + (c.warn === 1 ? '' : 'e')], ['err', c.err + ' fehlt'], ['opt', c.opt + ' optional/aus']].forEach(function (p) {
                if (p[0] !== 'ok' && c[p[0]] === 0) return;
                var pill = el('span', 'flow-pill st-' + p[0]); pill.appendChild(el('i', 'flow-dot')); pill.appendChild(document.createTextNode(p[1])); box.appendChild(pill);
            });
            var right = el('span', 'ms-auto');
            var group = el('div', 'btn-group');
            [['diagram', 'Diagramm'], ['list', 'Alle Einstellungen (' + settings + ')']].forEach(function (v) {
                var b = el('button', 'btn btn-sm ' + (state.view === v[0] ? 'btn-primary' : 'btn-outline-secondary'), v[1]); b.type = 'button';
                b.setAttribute('aria-pressed', state.view === v[0] ? 'true' : 'false');
                b.addEventListener('click', function () { state.view = v[0]; render(); });
                group.appendChild(b);
            });
            right.appendChild(group);
            box.appendChild(right);
        }

        function renderCanvas(container, flow) {
            var L = layout(flow);
            var box = el('div', 'card flow-canvas-box'), canvas = el('div', 'flow-canvas');
            canvas.style.width = L.w + 'px'; canvas.style.height = L.h + 'px';
            var NS = 'http://www.w3.org/2000/svg', svg = document.createElementNS(NS, 'svg');
            svg.setAttribute('width', L.w); svg.setAttribute('height', L.h); svg.setAttribute('aria-hidden', 'true');
            var defs = document.createElementNS(NS, 'defs'), marker = document.createElementNS(NS, 'marker');
            marker.setAttribute('id', 'flow-arr'); marker.setAttribute('viewBox', '0 0 10 10'); marker.setAttribute('refX', '9'); marker.setAttribute('refY', '5');
            marker.setAttribute('markerWidth', '7'); marker.setAttribute('markerHeight', '7'); marker.setAttribute('orient', 'auto-start-reverse');
            var mp = document.createElementNS(NS, 'path'); mp.setAttribute('d', 'M0 0 L10 5 L0 10 z'); mp.setAttribute('class', 'flow-arrow');
            marker.appendChild(mp); defs.appendChild(marker); svg.appendChild(defs);
            flow.edges.forEach(function (e) {
                if (!L.pos[e.from] || !L.pos[e.to]) return;
                var g = edgePath(e, L.pos), path = document.createElementNS(NS, 'path');
                path.setAttribute('d', g.d); path.setAttribute('class', 'flow-edge' + (e.alt ? ' alt' : '') + (e.off ? ' off' : '')); path.setAttribute('marker-end', 'url(#flow-arr)');
                svg.appendChild(path);
                if (e.label) {
                    var t = document.createElementNS(NS, 'text'); t.setAttribute('class', 'flow-edge-label'); t.setAttribute('x', g.lx); t.setAttribute('y', g.ly);
                    if (g.center) t.setAttribute('text-anchor', 'middle'); t.textContent = e.label; svg.appendChild(t);
                }
            });
            canvas.appendChild(svg);
            var hasTriggerLane = Object.keys(flow.nodes).some(function (k) { return flow.nodes[k].lane === 'trigger'; });
            if (hasTriggerLane) { var lane = el('div', 'flow-lane', 'Auslöser – was den Ablauf startet'); lane.style.left = '24px'; lane.style.top = '8px'; canvas.appendChild(lane); }
            Object.keys(flow.nodes).forEach(function (k) {
                var n = flow.nodes[k], p = L.pos[k];
                var b = el('button', 'flow-node k-' + n.kind + ' st-' + n.status + (n.off ? ' off' : '')); b.type = 'button';
                b.style.left = p.x + 'px'; b.style.top = p.y + 'px'; b.style.width = p.w + 'px'; b.style.height = H + 'px';
                b.setAttribute('aria-pressed', state.selected === k ? 'true' : 'false');
                b.setAttribute('aria-label', n.title + ', ' + STATUS_SHORT[n.status]);
                var ico = el('span', 'ico'); ico.innerHTML = '<svg viewBox="0 0 24 24">' + (ICONS[n.icon] || ICONS.gear) + '</svg>';
                var txt = el('span'); txt.style.minWidth = '0'; txt.style.display = 'grid';
                txt.appendChild(el('span', 'eyebrow', n.kindLabel)); txt.appendChild(el('span', 'ttl', n.title)); txt.appendChild(el('span', 'sub', n.sub));
                b.appendChild(ico); b.appendChild(txt); b.appendChild(el('span', 'dot'));
                if (n.settings && n.settings.length) b.appendChild(el('span', 'cnt', n.settings.length + ' Einst.'));
                b.title = n.title + ' – ' + n.sub;
                b.addEventListener('click', function () { state.selected = k; render(); });
                canvas.appendChild(b);
            });
            box.appendChild(canvas);
            container.appendChild(box);
        }

        function settingsList(list) {
            var box = el('div', 'flow-settings');
            list.forEach(function (s) {
                var row = el('div', 'flow-setting' + (s.warn ? ' warn' : ''));
                var r1 = el('div', 'r1'); r1.appendChild(el('span', 'nm', s.name)); r1.appendChild(el('span', 'flow-src src-' + s.src, SRC_LABEL[s.src] || s.src));
                row.appendChild(r1);
                row.appendChild(el('div', 'vl', s.value));
                if (GLOBAL_SRC[s.src]) {
                    var gl = el('div', 'flow-global', 'Gilt für alle Shops, die diesen Baustein nutzen · ');
                    var ga = el('a', null, 'in der Konfiguration zeigen'); ga.href = 'config_map.php'; gl.appendChild(ga);
                    row.appendChild(gl);
                }
                if (s.where) {
                    var wh = el('div', 'wh');
                    var url = safeUrl(s.url);
                    if (url) { wh.appendChild(document.createTextNode('Ändern: ')); var a = el('a', null, s.where); a.href = url; wh.appendChild(a); }
                    else wh.appendChild(document.createTextNode('Ändern: ' + s.where));
                    row.appendChild(wh);
                }
                box.appendChild(row);
            });
            return box;
        }

        function pickDefault(flow) {
            var keys = Object.keys(flow.nodes), best = keys[0];
            keys.forEach(function (k) { if (RANK[flow.nodes[k].status] > RANK[flow.nodes[best].status]) best = k; });
            return flow.nodes[best].status === 'err' || flow.nodes[best].status === 'warn' ? best : keys[0];
        }

        function renderPanel(container, flow) {
            var n = flow.nodes[state.selected];
            var card = el('div', 'card flow-panel'), body = el('div', 'card-body d-grid gap-3');
            var head = el('div'); head.appendChild(el('div', 'flow-h', n.kindLabel)); head.appendChild(el('h3', 'card-title mb-0', n.title)); body.appendChild(head);
            body.appendChild(el('div', 'flow-status st-' + n.status, STATUS_TEXT[n.status] + (n.note ? ' ' + n.note : '')));
            var w = el('div'); w.appendChild(el('div', 'flow-h', 'Was hier passiert')); w.appendChild(el('p', 'mb-0', n.what)); body.appendChild(w);
            if (n.settings && n.settings.length) {
                var s = el('div'); s.appendChild(el('div', 'flow-h', 'Einstellungen, die hier wirken (' + n.settings.length + ')')); s.appendChild(settingsList(n.settings)); body.appendChild(s);
            }
            var openUrl = n.open ? safeUrl(n.open.url) : null;
            if (openUrl) { var a = el('a', 'btn btn-primary', n.open.label + ' →'); a.href = openUrl; a.style.justifySelf = 'start'; body.appendChild(a); }
            card.appendChild(body);
            container.appendChild(card);
        }

        function renderList(container, flow) {
            var groups = {};
            Object.keys(flow.nodes).forEach(function (k) { (flow.nodes[k].settings || []).forEach(function (s) { (groups[s.src] = groups[s.src] || []).push({ s: s, key: k, node: flow.nodes[k] }); }); });
            var card = el('div', 'card'), wrap = el('div', 'table-responsive'), t = el('table', 'table table-vcenter card-table flow-all');
            var thead = el('thead'), hr = el('tr');
            ['Einstellung', 'Wert', 'Wirkt in Schritt', 'Ändern'].forEach(function (h) { hr.appendChild(el('th', null, h)); });
            thead.appendChild(hr); t.appendChild(thead);
            var tb = el('tbody');
            SRC_ORDER.forEach(function (src) {
                if (!groups[src]) return;
                var gr = el('tr', 'grp'), gtd = el('td'); gtd.colSpan = 4;
                gtd.appendChild(el('span', 'flow-src src-' + src, SRC_LABEL[src])); gtd.appendChild(document.createTextNode(' ' + groups[src].length));
                gr.appendChild(gtd); tb.appendChild(gr);
                groups[src].forEach(function (it) {
                    var tr = el('tr', it.s.warn ? 'warn' : '');
                    tr.appendChild(el('td', null, it.s.name));
                    tr.appendChild(el('td', null, it.s.value));
                    var stepTd = el('td'), sb = el('button', 'flow-link', it.node.title); sb.type = 'button';
                    sb.addEventListener('click', function () { state.view = 'diagram'; state.selected = it.key; render(); });
                    stepTd.appendChild(sb); tr.appendChild(stepTd);
                    var whTd = el('td', 'text-secondary'), url = safeUrl(it.s.url);
                    if (it.s.where && url) { var a = el('a', null, it.s.where); a.href = url; whTd.appendChild(a); } else whTd.textContent = it.s.where || '–';
                    tr.appendChild(whTd); tb.appendChild(tr);
                });
            });
            t.appendChild(tb); wrap.appendChild(t); card.appendChild(wrap); container.appendChild(card);
        }

        function render() {
            var flow = currentFlow();
            if (!flow) return;
            if (!state.selected || !flow.nodes[state.selected]) state.selected = pickDefault(flow);
            renderTabs();
            renderSummary(flow);
            var body = document.getElementById('flow-body');
            body.innerHTML = '';
            if (state.view === 'list') { renderList(body, flow); return; }
            var main = el('div', 'flow-main'), left = el('div'); left.style.minWidth = '0';
            renderCanvas(left, flow);
            main.appendChild(left);
            renderPanel(main, flow);
            body.appendChild(main);
        }

        render();
    })();
    </script>
<?php endif; ?>

<?php require dirname(__DIR__) . '/app/_footer.php'; ?>
