<?php

declare(strict_types=1);

/**
 * connect/public/admin/config_map.php
 *
 * Konfigurations-Landkarte: alle shop-uebergreifenden Bausteine auf einer
 * Seite -- Systeme (Connector Types) als Spalten mit ihren Objects,
 * Mapping-Vorlagen als Verbindungen zwischen Objects, dazu Funktionen,
 * Lookup-Tabellen, Job-Vorlagen und Belegprofile. Zu jedem Baustein: alle
 * Einstellungen, Ampel und "Verwendet in" (welche Shops/Aktivierungen/
 * anderen Bausteine betroffen sind, wenn man ihn aendert).
 *
 * Ersetzt das "Konfiguration"-Dropdown als Einstieg; die bisherigen Listen-
 * und Bearbeitungsseiten bleiben und werden von hier verlinkt. Rein lesend,
 * Daten aus Connect\Flow\ConfigMapInspector.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Connect\Auth\AdminAuth;
use Connect\Config;
use Connect\Database;
use Connect\Flow\ConfigMapInspector;

$config = Config::load();
$db = Database::connect($config);
$pdo = $db->pdo();

$auth = new AdminAuth($pdo);
$auth->requireLogin();

$flashMessage = null;
$flashType = 'danger';
try {
    $map = (new ConfigMapInspector($pdo))->build();
} catch (\Throwable $e) {
    $map = null;
    $flashMessage = 'Die Konfiguration konnte nicht gelesen werden: ' . $e->getMessage();
}

$pageTitle = 'Konfiguration';
$homeUrl = 'shops.php';
$adminNavItems = require __DIR__ . '/_nav.php';
$navItems = []; // kein Shop-Bezug auf dieser Seite
$topRightHtml = '<span class="text-secondary me-3">' . htmlspecialchars((string) $auth->currentEmail()) . '</span>'
    . '<a href="logout.php" class="btn btn-sm btn-outline-secondary">Logout</a>';

require dirname(__DIR__) . '/app/_header.php';
?>

<link rel="stylesheet" href="../assets/flow/flow.css">

<?php if ($map !== null): ?>
    <div class="map-toolbar">
        <label class="d-flex align-items-center gap-2 mb-0">
            <span class="text-secondary text-nowrap">Shopify ↔</span>
            <select id="map-target" class="form-select" aria-label="System rechts"></select>
        </label>
        <input type="search" id="map-search" class="form-control" placeholder="Suchen: Object, Feld, Vorlage, Lookup …" aria-label="Konfiguration durchsuchen">
        <label class="form-check form-switch mb-0">
            <input type="checkbox" class="form-check-input" id="map-hide-unused" checked>
            <span class="form-check-label">Nur Objects mit Vorlagen zeigen</span>
        </label>
        <div class="ms-auto d-flex flex-wrap gap-2">
            <span class="text-secondary align-self-center small">Listen &amp; neu anlegen:</span>
            <a class="btn btn-sm btn-outline-secondary" href="connector_types.php">Connector Types</a>
            <a class="btn btn-sm btn-outline-secondary" href="mapping_templates.php">Mapping-Vorlagen</a>
            <a class="btn btn-sm btn-outline-secondary" href="sync_job_templates.php">Job-Vorlagen</a>
            <a class="btn btn-sm btn-outline-secondary" href="functions.php">Funktionen</a>
            <a class="btn btn-sm btn-outline-secondary" href="lookup_tables.php">Lookup-Tabellen</a>
        </div>
    </div>

    <div class="flow-main">
        <div class="d-grid gap-3" style="min-width:0">
            <div class="card flow-canvas-box"><div class="flow-canvas" id="map-canvas"></div></div>
            <div class="card">
                <div class="card-body d-grid gap-3">
                    <div><div class="flow-h">Lookup-Tabellen</div><div class="map-chips" id="map-lookups"></div></div>
                    <div><div class="flow-h">Funktionen</div><div class="map-chips" id="map-functions"></div></div>
                </div>
            </div>
        </div>
        <div id="map-panel"></div>
    </div>

    <div class="flow-legend" aria-label="Legende">
        <span class="st-ok"><i class="flow-dot"></i>in Ordnung</span>
        <span class="st-warn"><i class="flow-dot"></i>Hinweis</span>
        <span class="st-err"><i class="flow-dot"></i>Fehler</span>
        <span class="st-opt"><i class="flow-dot"></i>nicht verwendet</span>
        <span>Pfeil = Mapping-Vorlage · ƒ = Funktion · L = Lookup-Tabelle</span>
        <span><i class="dash"></i>fest eingebaut</span>
    </div>

    <script type="application/json" id="map-data"><?php echo json_encode($map, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE); ?></script>
    <script>
    (function () {
        var DATA = JSON.parse(document.getElementById('map-data').textContent);
        var ICONS = {
            clock: '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
            doc: '<path d="M14 3H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z"/><path d="M14 3v6h6"/>',
            scale: '<path d="M12 3v18M5 7h14M5 7l-3 7a4 4 0 0 0 6 0zM19 7l-3 7a4 4 0 0 0 6 0z"/>'
        };
        var STATUS_TEXT = { ok: 'In Ordnung.', warn: 'Hinweis.', err: 'Fehler.', opt: 'Nicht verwendet.' };
        var SRC_LABEL = { sys: 'System', prof: 'Belegprofil', tpl: 'Vorlage', obj: 'Object/Feld', fn: 'Funktion', fix: 'fest eingebaut' };
        var COLW = 250, GAP = 210, X0 = 24, HEAD_Y = 16, HEAD_H = 40, OBJ_Y = 76, OH = 72, OROW = 88;

        var systems = DATA.systems.slice().sort(function (a, b) {
            var rank = function (s) { return s.key === 'shopify' ? 0 : (s.key === 'connect_internal' ? 1 : 2); };
            return rank(a) - rank(b) || a.name.localeCompare(b.name);
        });
        var objById = {}, tplById = {}, lkpById = {}, sysById = {};
        DATA.objects.forEach(function (o) { objById[o.id] = o; });
        DATA.templates.forEach(function (t) { tplById[t.id] = t; });
        DATA.lookups.forEach(function (l) { lkpById[l.id] = l; });
        systems.forEach(function (s) { sysById[s.id] = s; });

        // Links immer Shopify, rechts ein waehlbares System; "Connect (intern)"
        // nur dazwischen, wenn es Vorlagen zwischen intern und dem gewaehlten System gibt.
        var shopifySys = systems.filter(function (s) { return s.key === 'shopify'; })[0] || null;
        var internalSys = systems.filter(function (s) { return s.key === 'connect_internal'; })[0] || null;
        var targetChoices = systems.filter(function (s) { return s !== shopifySys && s !== internalSys; });

        var state = { sel: null, term: '', hideUnused: true, target: null };
        var m = (location.hash || '').match(/^#(sys|obj|tpl|lkp)-(\d+)$/);
        if (m) state.sel = { type: m[1], id: parseInt(m[2], 10) };
        try { var storedTarget = parseInt(localStorage.getItem('connectMapTarget') || '', 10); if (sysById[storedTarget] && targetChoices.indexOf(sysById[storedTarget]) !== -1) state.target = storedTarget; } catch (e) {}
        if (state.target === null && targetChoices.length) {
            state.target = targetChoices.slice().sort(function (a, b) { return b.connections - a.connections; })[0].id;
        }
        // Direktlink auf einen Baustein eines anderen Systems: dorthin umschalten
        (function () {
            if (!state.sel) return;
            var sysId = null;
            if (state.sel.type === 'sys') sysId = state.sel.id;
            if (state.sel.type === 'obj' && objById[state.sel.id]) sysId = objById[state.sel.id].system;
            if (state.sel.type === 'tpl' && tplById[state.sel.id]) {
                [tplById[state.sel.id].source, tplById[state.sel.id].target].forEach(function (oid) {
                    var o = objById[oid]; if (o && targetChoices.indexOf(sysById[o.system]) !== -1) sysId = o.system;
                });
            }
            if (sysId !== null && targetChoices.indexOf(sysById[sysId]) !== -1) state.target = sysId;
        })();

        function visibleSystems() {
            var list = [];
            if (shopifySys) list.push(shopifySys);
            if (internalSys && state.target !== null) {
                var bridges = DATA.templates.some(function (t) {
                    var s = objById[t.source], g = objById[t.target];
                    return s && g && ((s.system === internalSys.id && g.system === state.target) || (g.system === internalSys.id && s.system === state.target));
                });
                if (bridges) list.push(internalSys);
            }
            if (state.target !== null && sysById[state.target]) list.push(sysById[state.target]);
            return list;
        }
        function isVisibleSystem(id) { return visibleSystems().some(function (s) { return s.id === id; }); }
        function isVisibleTemplate(t) {
            var s = objById[t.source], g = objById[t.target];
            return !!(s && g && isVisibleSystem(s.system) && isVisibleSystem(g.system) && (s.system !== g.system || s.system === state.target || s.system === (shopifySys && shopifySys.id)));
        }
        function isVisibleLookup(l) {
            if (!l.templateIds || l.templateIds.length === 0) return true;
            return l.templateIds.some(function (id) { return tplById[id] && isVisibleTemplate(tplById[id]); });
        }

        (function () {
            var sel = document.getElementById('map-target');
            if (targetChoices.length === 0) { var o = el('option', null, 'kein weiteres System'); sel.appendChild(o); sel.disabled = true; return; }
            targetChoices.forEach(function (s) {
                var o = el('option', null, s.name + ' (' + s.connections + ' Connection' + (s.connections === 1 ? '' : 's') + ')');
                o.value = String(s.id); if (s.id === state.target) o.selected = true; sel.appendChild(o);
            });
            sel.addEventListener('change', function () {
                state.target = parseInt(sel.value, 10);
                try { localStorage.setItem('connectMapTarget', String(state.target)); } catch (e) {}
                if (state.sel) {
                    var keep = (state.sel.type === 'obj' && objById[state.sel.id] && isVisibleSystem(objById[state.sel.id].system))
                        || (state.sel.type === 'tpl' && tplById[state.sel.id] && isVisibleTemplate(tplById[state.sel.id]))
                        || (state.sel.type === 'sys' && isVisibleSystem(state.sel.id))
                        || (state.sel.type === 'lkp' && lkpById[state.sel.id] && isVisibleLookup(lkpById[state.sel.id]));
                    if (!keep) { state.sel = null; try { history.replaceState(null, '', location.pathname); } catch (e) {} }
                }
                render();
            });
        })();

        function el(tag, cls, text) { var e = document.createElement(tag); if (cls) e.className = cls; if (text != null) e.textContent = text; return e; }
        function safeUrl(url) { return typeof url === 'string' && /^[a-z_]+\.php(\?[^"<>]*)?(#[A-Za-z0-9_-]+)?$/.test(url) ? url : null; }
        function select(type, id) {
            state.sel = { type: type, id: id };
            try { history.replaceState(null, '', '#' + type + '-' + id); } catch (e) {}
            render();
        }
        function isSel(type, id) { return state.sel && state.sel.type === type && state.sel.id === id; }
        function matches(item) { return state.term === '' || (item.search || '').indexOf(state.term) !== -1; }

        // Objects, die an einer sichtbaren Vorlage (oder der fest eingebauten
        // Aufbereitung) haengen -- fuer "Nur Objects mit Vorlagen zeigen".
        function linkedObjectIds() {
            var ids = {};
            DATA.templates.forEach(function (t) { if (isVisibleTemplate(t)) { ids[t.source] = true; ids[t.target] = true; } });
            DATA.implicit.forEach(function (i) { ids[i.source] = true; ids[i.target] = true; });
            return ids;
        }

        function layout() {
            var pos = {}, colOf = {}, height = OBJ_Y, cols = [];
            var shown = visibleSystems();
            var linked = state.hideUnused ? linkedObjectIds() : null;
            // Spaltenbreite an die verfuegbare Breite anpassen, damit alle Systeme
            // ohne seitliches Scrollen sichtbar sind (Mindestmasse bleiben lesbar).
            var avail = Math.max(600, document.getElementById('map-canvas').parentNode.clientWidth - 2);
            var n = Math.max(1, shown.length);
            COLW = Math.max(190, Math.min(250, Math.floor((avail - 2 * X0 - (n - 1) * 140) / n)));
            GAP = n > 1 ? Math.max(140, Math.min(260, Math.floor((avail - 2 * X0 - n * COLW) / (n - 1)))) : 0;
            shown.forEach(function (s, i) {
                var x = X0 + i * (COLW + GAP);
                cols.push({ sys: s, x: x });
                var objs = DATA.objects.filter(function (o) {
                    if (o.system !== s.id) return false;
                    if (!linked) return true;
                    return linked[o.id] || isSel('obj', o.id) || (state.term !== '' && matches(o));
                });
                objs.sort(function (a, b) { return (b.involved - a.involved) || (a.status === 'opt') - (b.status === 'opt') || a.label.localeCompare(b.label); });
                objs.forEach(function (o, j) {
                    pos[o.id] = { x: x, y: OBJ_Y + j * OROW, w: COLW };
                    colOf[o.id] = i;
                    height = Math.max(height, OBJ_Y + j * OROW + OH);
                });
            });
            return { pos: pos, colOf: colOf, cols: cols, w: X0 + shown.length * (COLW + GAP) - GAP + 24, h: height + 40 };
        }

        function curve(p0, p1, p2, p3) {
            return { d: 'M' + p0[0] + ' ' + p0[1] + ' C' + p1[0] + ' ' + p1[1] + ' ' + p2[0] + ' ' + p2[1] + ' ' + p3[0] + ' ' + p3[1],
                mx: (p0[0] + 3 * p1[0] + 3 * p2[0] + p3[0]) / 8, my: (p0[1] + 3 * p1[1] + 3 * p2[1] + p3[1]) / 8 };
        }
        function connect(L, sourceId, targetId, bend) {
            var s = L.pos[sourceId], t = L.pos[targetId];
            var cs = L.colOf[sourceId], ct = L.colOf[targetId];
            var sy = s.y + OH / 2 + bend, ty = t.y + OH / 2 + bend;
            if (cs < ct) { var a = [s.x + s.w, sy], b = [t.x - 2, ty], c = (b[0] - a[0]) / 2; return curve(a, [a[0] + c, a[1]], [b[0] - c, b[1]], b); }
            if (cs > ct) { var a2 = [s.x, sy], b2 = [t.x + t.w + 2, ty], c2 = (a2[0] - b2[0]) / 2; return curve(a2, [a2[0] - c2, a2[1]], [b2[0] + c2, b2[1]], b2); }
            var a3 = [s.x + s.w, sy], b3 = [t.x + t.w + 2, ty];
            return curve(a3, [a3[0] + 70, a3[1]], [b3[0] + 70, b3[1]], b3);
        }

        function overlaps(r, list) {
            for (var i = 0; i < list.length; i++) {
                var o = list[i];
                if (r.x < o.x + o.w && r.x + r.w > o.x && r.y < o.y + o.h && r.y + r.h > o.y) return true;
            }
            return false;
        }

        function connectedTemplates(objectId) {
            return DATA.templates.filter(function (t) { return t.source === objectId || t.target === objectId; }).map(function (t) { return t.id; });
        }

        function renderCanvas() {
            var L = layout();
            var canvas = document.getElementById('map-canvas');
            canvas.innerHTML = '';
            var placed = [];
            Object.keys(L.pos).forEach(function (id) { var p = L.pos[id]; placed.push({ x: p.x, y: p.y, w: p.w, h: OH }); });

            var NS = 'http://www.w3.org/2000/svg', svg = document.createElementNS(NS, 'svg');
            var defs = document.createElementNS(NS, 'defs'), marker = document.createElementNS(NS, 'marker');
            marker.setAttribute('id', 'map-arr'); marker.setAttribute('viewBox', '0 0 10 10'); marker.setAttribute('refX', '9'); marker.setAttribute('refY', '5');
            marker.setAttribute('markerWidth', '7'); marker.setAttribute('markerHeight', '7'); marker.setAttribute('orient', 'auto-start-reverse');
            var mp = document.createElementNS(NS, 'path'); mp.setAttribute('d', 'M0 0 L10 5 L0 10 z'); mp.setAttribute('class', 'flow-arrow');
            marker.appendChild(mp); defs.appendChild(marker); svg.appendChild(defs);

            var hlTemplates = {};
            if (state.sel && state.sel.type === 'obj') connectedTemplates(state.sel.id).forEach(function (id) { hlTemplates[id] = true; });
            if (state.sel && state.sel.type === 'tpl') hlTemplates[state.sel.id] = true;

            var pairCount = {}, labels = [], maxY = L.h;
            var edges = DATA.implicit.map(function (i) { return { implicit: true, source: i.source, target: i.target, name: i.label }; }).concat(DATA.templates);
            edges.forEach(function (t) {
                if (!L.pos[t.source] || !L.pos[t.target]) return;
                if (!t.implicit && !isVisibleTemplate(t)) return;
                var key = Math.min(t.source, t.target) + '-' + Math.max(t.source, t.target);
                var k = pairCount[key] = (pairCount[key] || 0) + 1;
                var bend = (k - 1) * 8;
                var g = connect(L, t.source, t.target, bend);
                var path = document.createElementNS(NS, 'path');
                var dim = !t.implicit && state.term !== '' && !matchesTemplate(t);
                path.setAttribute('d', g.d);
                path.setAttribute('class', 'flow-edge' + (t.implicit ? ' alt' : '') + (!t.implicit && t.status === 'err' ? ' st-err' : '') + (hlTemplates[t.id] ? ' hl' : '') + (dim ? ' map-dim' : ''));
                path.setAttribute('marker-end', 'url(#map-arr)');
                svg.appendChild(path);
                labels.push({ t: t, mx: g.mx, my: g.my, dim: dim });
            });

            labels.forEach(function (it) {
                var t = it.t;
                var text = t.implicit ? t.name : t.name + ' · ' + t.fieldCount;
                var extra = t.implicit ? 0 : ((t.hasFunction ? 1 : 0) + (t.hasLookup ? 1 : 0));
                var w = Math.min(Math.max(100, Math.min(180, GAP - 12)), 22 + text.length * 6.3 + extra * 14), h = 22;
                var r = { x: it.mx - w / 2, y: it.my - h / 2, w: w, h: h };
                var guard = 0;
                while (overlaps(r, placed) && guard < 30) { r.y += 24; guard++; }
                placed.push(r);
                maxY = Math.max(maxY, r.y + h + 16);
                var b;
                if (t.implicit) {
                    b = el('span', 'map-label implicit');
                    b.appendChild(el('span', 't', text));
                } else {
                    b = el('button', 'map-label st-' + t.status + (it.dim ? ' map-dim' : '')); b.type = 'button';
                    b.setAttribute('aria-pressed', isSel('tpl', t.id) ? 'true' : 'false');
                    b.appendChild(el('i', 'flow-dot'));
                    b.appendChild(el('span', 't', text));
                    if (t.hasFunction) b.appendChild(el('strong', null, 'ƒ'));
                    if (t.hasLookup) b.appendChild(el('strong', null, 'L'));
                    b.title = t.name + ' (' + t.fieldCount + ' Felder)';
                    b.addEventListener('click', function () { select('tpl', t.id); });
                }
                b.style.left = r.x + 'px'; b.style.top = r.y + 'px'; b.style.maxWidth = w + 'px';
                canvas.appendChild(b);
            });

            L.cols.forEach(function (c) {
                var s = c.sys, b = el('button', 'map-system st-' + s.status); b.type = 'button';
                b.style.left = c.x + 'px'; b.style.top = HEAD_Y + 'px'; b.style.width = COLW + 'px'; b.style.height = HEAD_H + 'px';
                b.setAttribute('aria-pressed', isSel('sys', s.id) ? 'true' : 'false');
                b.appendChild(el('span', null, s.name));
                b.appendChild(el('small', null, s.key === 'connect_internal' ? 'intern' : s.connections + ' Connection' + (s.connections === 1 ? '' : 's')));
                b.addEventListener('click', function () { select('sys', s.id); });
                canvas.appendChild(b);
            });

            Object.keys(L.pos).forEach(function (id) {
                var o = objById[id], p = L.pos[id];
                var dim = state.term !== '' && !matches(o);
                var b = el('button', 'map-object st-' + o.status + (dim ? ' map-dim' : '')); b.type = 'button';
                b.style.left = p.x + 'px'; b.style.top = p.y + 'px'; b.style.width = p.w + 'px'; b.style.height = OH + 'px';
                b.setAttribute('aria-pressed', isSel('obj', o.id) ? 'true' : 'false');
                b.appendChild(el('span', 'ttl', o.label));
                b.appendChild(el('span', 'key', o.key));
                var badges = el('span', 'map-badges');
                (o.badges || []).forEach(function (bd) {
                    var x = el('span', 'map-badge'); x.innerHTML = '<svg viewBox="0 0 24 24">' + (ICONS[bd.icon] || '') + '</svg>';
                    x.appendChild(document.createTextNode(bd.text)); badges.appendChild(x);
                });
                b.appendChild(badges);
                b.appendChild(el('span', 'dot'));
                b.title = o.label + ' (' + o.key + ')' + (o.note ? ' – ' + o.note : '');
                b.addEventListener('click', function () { select('obj', o.id); });
                canvas.appendChild(b);
            });

            canvas.style.width = L.w + 'px'; canvas.style.height = maxY + 'px';
            svg.setAttribute('width', L.w); svg.setAttribute('height', maxY); svg.setAttribute('aria-hidden', 'true');
            canvas.insertBefore(svg, canvas.firstChild);
        }

        function matchesTemplate(t) {
            if (state.term === '') return true;
            return matches(t) || (objById[t.source] && matches(objById[t.source])) || (objById[t.target] && matches(objById[t.target]));
        }

        function renderChips() {
            var lk = document.getElementById('map-lookups'); lk.innerHTML = '';
            var lookups = DATA.lookups.filter(isVisibleLookup);
            if (lookups.length === 0) lk.appendChild(el('span', 'text-secondary small', 'Keine Lookup-Tabellen für diese Systeme.'));
            lookups.forEach(function (l) {
                var b = el('button', 'map-chip st-' + l.status + (state.term !== '' && !matches(l) ? ' map-dim' : '')); b.type = 'button';
                b.setAttribute('aria-pressed', isSel('lkp', l.id) ? 'true' : 'false');
                b.appendChild(el('i', 'flow-dot')); b.appendChild(document.createTextNode(l.name));
                b.addEventListener('click', function () { select('lkp', l.id); });
                lk.appendChild(b);
            });
            var fn = document.getElementById('map-functions'); fn.innerHTML = '';
            var functions = DATA.functions.filter(function (f) { return tplById[f.template] && isVisibleTemplate(tplById[f.template]); });
            if (functions.length === 0) fn.appendChild(el('span', 'text-secondary small', 'Keine Funktionen für diese Systeme.'));
            functions.forEach(function (f) {
                var b = el('button', 'map-chip'); b.type = 'button';
                b.appendChild(el('strong', null, 'ƒ')); b.appendChild(document.createTextNode(f.label + ' → ' + f.templateName));
                b.title = f.use;
                b.addEventListener('click', function () { select('tpl', f.template); });
                fn.appendChild(b);
            });
        }

        function settingsList(list) {
            var box = el('div', 'flow-settings');
            list.forEach(function (s) {
                var row = el('div', 'flow-setting' + (s.warn ? ' warn' : ''));
                var r1 = el('div', 'r1'); r1.appendChild(el('span', 'nm', s.name)); r1.appendChild(el('span', 'flow-src src-' + s.src, SRC_LABEL[s.src] || s.src));
                row.appendChild(r1); row.appendChild(el('div', 'vl', s.value));
                if (s.where) {
                    var wh = el('div', 'wh'), url = safeUrl(s.url);
                    wh.appendChild(document.createTextNode('Ändern: '));
                    if (url) { var a = el('a', null, s.where); a.href = url; wh.appendChild(a); } else wh.appendChild(document.createTextNode(s.where));
                    row.appendChild(wh);
                }
                box.appendChild(row);
            });
            return box;
        }

        function issues() {
            var list = [];
            DATA.objects.forEach(function (o) { if ((o.status === 'err' || o.status === 'warn') && isVisibleSystem(o.system)) list.push({ st: o.status, text: 'Object „' + o.label + '“: ' + (o.note || ''), type: 'obj', id: o.id }); });
            DATA.templates.forEach(function (t) { if ((t.status === 'err' || t.status === 'warn') && isVisibleTemplate(t)) list.push({ st: t.status, text: 'Vorlage „' + t.name + '“: ' + (t.note || ''), type: 'tpl', id: t.id }); });
            DATA.lookups.forEach(function (l) { if ((l.status === 'err' || l.status === 'warn') && isVisibleLookup(l)) list.push({ st: l.status, text: 'Lookup „' + l.name + '“: ' + (l.note || ''), type: 'lkp', id: l.id }); });
            list.sort(function (a, b) { return (a.st === 'err' ? 0 : 1) - (b.st === 'err' ? 0 : 1); });
            return list;
        }

        function renderPanel() {
            var host = document.getElementById('map-panel'); host.innerHTML = '';
            var card = el('div', 'card flow-panel'), body = el('div', 'card-body d-grid gap-3');
            var item = null, kind = '', title = '';
            if (state.sel) {
                if (state.sel.type === 'obj') { item = objById[state.sel.id]; kind = 'Object · ' + (item && sysById[item.system] ? sysById[item.system].name : ''); title = item ? item.label : ''; }
                if (state.sel.type === 'tpl') { item = tplById[state.sel.id]; kind = 'Mapping-Vorlage'; title = item ? item.name : ''; }
                if (state.sel.type === 'lkp') { item = lkpById[state.sel.id]; kind = 'Lookup-Tabelle'; title = item ? item.name : ''; }
                if (state.sel.type === 'sys') { item = sysById[state.sel.id]; kind = 'System'; title = item ? item.name : ''; }
            }
            if (!item) {
                body.appendChild(el('h3', 'card-title mb-0', 'Überblick'));
                var shownNames = visibleSystems().map(function (s) { return s.name; }).join(' ↔ ');
                body.appendChild(el('p', 'mb-0 text-secondary', shownNames + ' · ' + DATA.objects.filter(function (o) { return isVisibleSystem(o.system); }).length + ' Objects · '
                    + DATA.templates.filter(isVisibleTemplate).length + ' Vorlagen · ' + DATA.lookups.filter(isVisibleLookup).length + ' Lookup-Tabellen'));
                var list = issues();
                var sec = el('div'); sec.appendChild(el('div', 'flow-h', 'Zu prüfen (' + list.length + ')'));
                if (list.length === 0) sec.appendChild(el('p', 'mb-0 text-secondary', 'Keine Auffälligkeiten.'));
                var ul = el('ul', 'map-issues');
                list.forEach(function (i) {
                    var li = el('li', 'st-' + i.st); li.appendChild(el('i', 'flow-dot'));
                    var b = el('button', 'flow-link', i.text); b.type = 'button';
                    b.addEventListener('click', function () { select(i.type, i.id); });
                    li.appendChild(b); ul.appendChild(li);
                });
                sec.appendChild(ul); body.appendChild(sec);
                body.appendChild(el('p', 'mb-0 text-secondary small', 'Klick auf ein System, Object oder eine Vorlage zeigt alle Einstellungen und wo der Baustein verwendet wird.'));
            } else {
                var head = el('div'); head.appendChild(el('div', 'flow-h', kind)); head.appendChild(el('h3', 'card-title mb-0', title)); body.appendChild(head);
                body.appendChild(el('div', 'flow-status st-' + item.status, STATUS_TEXT[item.status] + (item.note ? ' ' + item.note : '')));
                if (item.settings && item.settings.length) {
                    var s = el('div'); s.appendChild(el('div', 'flow-h', 'Einstellungen')); s.appendChild(settingsList(item.settings)); body.appendChild(s);
                }
                var used = el('div'); used.appendChild(el('div', 'flow-h', 'Verwendet in (' + (item.usedBy || []).length + ')'));
                if (!item.usedBy || item.usedBy.length === 0) used.appendChild(el('p', 'mb-0 text-secondary', 'Nirgends – eine Änderung betrifft keinen Shop.'));
                else {
                    var ul2 = el('ul', 'flow-used');
                    item.usedBy.forEach(function (u) { var li = el('li'), url = safeUrl(u.url); if (url) { var a = el('a', null, u.label); a.href = url; li.appendChild(a); } else li.textContent = u.label; ul2.appendChild(li); });
                    used.appendChild(ul2);
                }
                body.appendChild(used);
                var openUrl = item.open ? safeUrl(item.open.url) : null;
                var row = el('div', 'd-flex flex-wrap gap-2');
                if (openUrl) { var a2 = el('a', 'btn btn-primary', item.open.label + ' →'); a2.href = openUrl; row.appendChild(a2); }
                var back = el('button', 'btn btn-outline-secondary', 'Zum Überblick'); back.type = 'button';
                back.addEventListener('click', function () { state.sel = null; try { history.replaceState(null, '', location.pathname); } catch (e) {} render(); });
                row.appendChild(back);
                body.appendChild(row);
            }
            card.appendChild(body); host.appendChild(card);
        }

        function render() { renderCanvas(); renderChips(); renderPanel(); }

        document.getElementById('map-search').addEventListener('input', function (e) { state.term = e.target.value.trim().toLowerCase(); renderCanvas(); renderChips(); });
        document.getElementById('map-hide-unused').addEventListener('change', function (e) { state.hideUnused = e.target.checked; renderCanvas(); });
        var resizeTimer = null;
        window.addEventListener('resize', function () { clearTimeout(resizeTimer); resizeTimer = setTimeout(renderCanvas, 150); });
        render();
    })();
    </script>
<?php endif; ?>

<?php require dirname(__DIR__) . '/app/_footer.php'; ?>
