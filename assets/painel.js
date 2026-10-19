/* Painel do Operador - Issabel 5. Sem dependências. */
(function () {
  'use strict';

  var root = document.getElementById('pop-painel');
  if (!root || root.getAttribute('data-ready')) return;
  root.setAttribute('data-ready', '1');

  var API = root.getAttribute('data-api');
  var POLL_MS = Math.max(1, parseInt(root.getAttribute('data-poll'), 10) || 2) * 1000;
  var DEMO = root.getAttribute('data-demo') === '1';

  var S = {
    data: null, offset: 0, lastOk: 0, error: null, timer: null, busy: false, live: true,
    filter: 'all', group: '', q: '', open: null, tiles: {}, returnFocus: null
  };

  var LABEL = {
    free: 'Livre', ringing: 'Tocando', dialing: 'Chamando', incall: 'Em ligação', hold: 'Em espera',
    dnd: 'Não perturbe', offline: 'Offline', unknown: 'Sem status', virtual: 'Sem aparelho'
  };
  var FILTERS = [
    ['all', 'Todos'], ['free', 'Livres'], ['busy', 'Em ligação'], ['ringing', 'Tocando'], ['offline', 'Offline']
  ];
  function category(st) {
    if (st === 'incall' || st === 'dialing' || st === 'hold') return 'busy';
    if (st === 'offline' || st === 'unknown' || st === 'virtual') return 'offline';
    return st; // free, ringing, dnd
  }

  /* ---------- utilidades (mesmas regras do relatório de ligações) ---------- */
  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  function pad(n) { return (n < 10 ? '0' : '') + n; }
  function nf(n) { return Number(n || 0).toLocaleString('pt-BR'); }
  function phone(n) {
    var d = String(n || '').replace(/\D/g, '');
    if (!d) return 'Número oculto';
    if (d.length <= 5) return d; // ramal
    if (/^0?800/.test(d)) { d = d.replace(/^0(?=800)/, ''); return d.slice(0, 4) + ' ' + d.slice(4, 7) + ' ' + d.slice(7); }
    if (d.length >= 12 && d.slice(0, 2) === '55') d = d.slice(2);
    if (d.length === 13 && d[0] === '0') d = d.slice(3);
    else if ((d.length === 12 || d.length === 11) && d[0] === '0') d = d.slice(1);
    if (d.length === 11) return '(' + d.slice(0, 2) + ') ' + d.slice(2, 7) + '-' + d.slice(7);
    if (d.length === 10) return '(' + d.slice(0, 2) + ') ' + d.slice(2, 6) + '-' + d.slice(6);
    if (d.length === 9) return d.slice(0, 5) + '-' + d.slice(5);
    if (d.length === 8) return d.slice(0, 4) + '-' + d.slice(4);
    return d;
  }
  function clock(s) {
    s = Math.max(0, Math.floor(s));
    var h = Math.floor(s / 3600), m = Math.floor((s % 3600) / 60), r = s % 60;
    return h ? h + ':' + pad(m) + ':' + pad(r) : m + ':' + pad(r);
  }
  function dur(s) {
    s = Math.max(0, Math.round(s || 0));
    if (s < 60) return s + 's';
    var h = Math.floor(s / 3600), m = Math.floor((s % 3600) / 60);
    return h ? h + 'h ' + pad(m) + 'min' : m + 'min';
  }
  function nowSrv() { return Date.now() / 1000 + S.offset; }
  function plural(n, one, many) { return nf(n) + ' ' + (n === 1 ? one : many); }
  function since(ts, cls) {
    if (!ts) return '';
    return '<span class="' + (cls || 't') + '" data-since="' + ts + '">' + clock(nowSrv() - ts) + '</span>';
  }
  function settings() {
    var s = (S.data && S.data.settings) || {};
    return { sl: s.service_level_seconds || 20, ring: s.long_ring_seconds || 20 };
  }
  function queueName(num) {
    var qs = (S.data && S.data.queues) || [];
    for (var i = 0; i < qs.length; i++) if (qs[i].num === num) return qs[i].name || 'Fila ' + num;
    return 'Fila ' + num;
  }
  function trunkName(id) {
    var ts = (S.data && S.data.trunks) || [];
    for (var i = 0; i < ts.length; i++) if (ts[i].id === id) return ts[i].label;
    return '';
  }
  /* Quem está do outro lado: ramal pelo nome, externo pelo número. */
  function who(num, name, ext) {
    if (ext) return { main: name || ext, sub: 'ramal ' + ext };
    if (!num && name) return { main: name, sub: '' };
    var clean = name && name !== num && !/^\d+$/.test(name) ? name : '';
    return { main: phone(num), sub: clean };
  }

  var ICON = {
    vm: '<svg viewBox="0 0 16 16" width="12" height="12" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true" style="vertical-align:-1px;margin-right:3px"><rect x="2" y="3.5" width="12" height="9" rx="1.5"/><path d="m2.6 4.6 5.4 4 5.4-4"/></svg>'
  };

  /* ---------- estrutura fixa ---------- */
  root.innerHTML =
    '<header class="tb">' +
      '<h1 class="tb-title">Painel do operador</h1>' +
      '<dl class="metrics" id="pop-overview" aria-label="Resumo agora"></dl>' +
      '<div class="tb-side">' +
        '<span class="sub" id="pop-sub">Carregando…</span>' +
        '<label class="live" title="Atualiza a cada ' + (POLL_MS / 1000) + ' segundos">' +
          '<input type="checkbox" id="pop-live" checked><span class="live-dot" aria-hidden="true"></span><span>Ao vivo</span></label>' +
        '<button type="button" class="btn ghost" id="pop-full">Tela cheia</button>' +
      '</div>' +
    '</header>' +
    '<div id="pop-notices"></div>' +
    '<div class="board" id="pop-board">' +
      '<div class="col c-left">' +
        '<section class="c-ext" aria-labelledby="pop-h-ext">' +
          '<div class="section-head">' +
            '<h2 id="pop-h-ext">Ramais<span id="pop-ext-count"></span></h2>' +
            '<div class="seg" id="pop-filter" role="group" aria-label="Filtrar ramais por estado"></div>' +
            '<label class="sr-only" for="pop-group">Grupo</label><select class="field" id="pop-group"><option value="">Todos os grupos</option></select>' +
            '<label class="sr-only" for="pop-q">Buscar ramal</label><input type="search" class="field" id="pop-q" placeholder="Nome ou ramal" autocomplete="off">' +
          '</div>' +
          '<div class="scroll" id="pop-ext-scroll"><div class="exts" id="pop-exts" data-size="m"></div></div>' +
        '</section>' +
        '<div class="bottom scroll">' +
          '<section aria-labelledby="pop-h-t"><div class="section-head"><h2 id="pop-h-t">Troncos<span id="pop-trunk-count"></span></h2></div><ul class="chips" id="pop-trunks"></ul></section>' +
          '<div class="stack">' +
            '<section aria-labelledby="pop-h-p" id="pop-park-sec"><div class="section-head"><h2 id="pop-h-p">Estacionamento</h2></div><div class="slots" id="pop-park"></div></section>' +
            '<section aria-labelledby="pop-h-c" id="pop-conf-sec" hidden><div class="section-head"><h2 id="pop-h-c">Conferências</h2></div><ul class="chips" id="pop-confs"></ul></section>' +
          '</div>' +
        '</div>' +
      '</div>' +
      '<section class="col c-q" aria-labelledby="pop-h-q">' +
        '<div class="section-head"><h2 id="pop-h-q">Filas<span id="pop-q-count"></span></h2></div>' +
        '<div class="scroll"><div class="queues" id="pop-queues"></div></div>' +
      '</section>' +
    '</div>' +
    '<div id="pop-drawer"></div>';

  /* Ocupa exatamente a altura da janela: a página não rola, só a coluna que não couber. */
  function fit() {
    var b = document.getElementById('pop-board');
    if (!b) return;
    if (window.innerWidth < 1100) { b.style.height = ''; return; }
    var top = b.getBoundingClientRect().top + (document.fullscreenElement ? 0 : window.pageYOffset);
    b.style.height = Math.max(360, window.innerHeight - top - 12) + 'px';
  }
  window.addEventListener('resize', function () { fit(); sizeExts(); });

  /*
   * Tamanho dos cartões de ramal: do maior para o menor, fica o maior que cabe sem rolar.
   * Com muitos ramais (centenas) nenhum cabe e fica o menor, com rolagem.
   * Só reavalia quando muda a quantidade visível ou o espaço, não a cada atualização,
   * para os cartões não ficarem pulando de tamanho quando um ramal começa a tocar.
   */
  var SIZES = ['xl', 'l', 'm', 's', 'xs'];
  function sizeExts(force) {
    var box = document.getElementById('pop-exts'), sc = document.getElementById('pop-ext-scroll');
    if (!box || !sc) return;
    var n = box.querySelectorAll('[data-ext]').length;
    var constrained = window.innerWidth >= 1100;
    var key = n + '|' + sc.clientWidth + '|' + (constrained ? sc.clientHeight : 0);
    if (!force && S.sizeKey === key) return;
    S.sizeKey = key;
    if (!constrained) {   // tela estreita: a página rola, escolhe só pela quantidade
      box.setAttribute('data-size', n > 200 ? 's' : (n > 60 ? 'm' : 'l'));
      return;
    }
    for (var i = 0; i < SIZES.length; i++) {
      box.setAttribute('data-size', SIZES[i]);
      if (sc.scrollHeight <= sc.clientHeight + 1) return;
    }
  }


  var $ = function (id) { return document.getElementById(id); };

  $('pop-q').addEventListener('input', function () { S.q = this.value.trim().toLowerCase(); renderExts(); });
  $('pop-group').addEventListener('change', function () { S.group = this.value; renderExts(); });
  $('pop-filter').addEventListener('click', function (e) {
    var b = e.target.closest('button[data-f]');
    if (!b) return;
    S.filter = b.getAttribute('data-f');
    renderExts();
  });
  $('pop-live').addEventListener('change', function () {
    S.live = this.checked;
    if (S.live) poll(); else clearTimeout(S.timer);
    renderSub();
  });
  $('pop-full').addEventListener('click', function () {
    if (document.fullscreenElement) document.exitFullscreen();
    else if (root.requestFullscreen) root.requestFullscreen();
  });
  document.addEventListener('fullscreenchange', function () {
    $('pop-full').textContent = document.fullscreenElement ? 'Sair da tela cheia' : 'Tela cheia';
    setTimeout(function () { fit(); sizeExts(true); }, 50);
  });
  document.addEventListener('visibilitychange', function () { if (!document.hidden && S.live) poll(); });
  $('pop-exts').addEventListener('click', function (e) {
    var t = e.target.closest('[data-ext]');
    if (t) openDrawer(t.getAttribute('data-ext'), t);
  });
  $('pop-exts').addEventListener('keydown', function (e) {
    if (e.key !== 'Enter' && e.key !== ' ') return;
    var t = e.target.closest('[data-ext]');
    if (t) { e.preventDefault(); openDrawer(t.getAttribute('data-ext'), t); }
  });
  root.addEventListener('click', function (e) {
    if (e.target.closest('[data-retry]')) poll();
    if (e.target.closest('[data-close]')) closeDrawer();
    var j = e.target.closest('[data-jump]');
    if (j) openDrawer(j.getAttribute('data-jump'), null);
  });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && S.open) closeDrawer(); });

  /* ---------- dados ---------- */
  function fetchSnapshot() {
    if (DEMO && window.POP_DEMO) return Promise.resolve(window.POP_DEMO.snapshot());
    return fetch(API, { credentials: 'same-origin', cache: 'no-store', headers: { 'Accept': 'application/json' } })
      .then(function (r) {
        var ct = r.headers.get('Content-Type') || '';
        if (ct.indexOf('json') === -1) throw new Error('session'); // Issabel devolve a tela de login
        return r.json().then(function (j) {
          if (!r.ok || j.error) throw new Error(j.error || ('HTTP ' + r.status));
          return j;
        });
      });
  }

  function poll() {
    clearTimeout(S.timer);
    if (S.busy) return;
    S.busy = true;
    fetchSnapshot().then(function (d) {
      S.data = d;
      S.offset = d.now - Date.now() / 1000;
      S.lastOk = Date.now();
      S.error = null;
      render();
    }).catch(function (e) {
      S.error = e && e.message === 'session' ? 'session' : (e && e.message) || 'erro desconhecido';
      renderNotices();
      renderSub();
    }).then(function () {
      S.busy = false;
      if (S.live && !document.hidden && S.error !== 'session') S.timer = setTimeout(poll, S.error ? Math.max(POLL_MS, 5000) : POLL_MS);
    });
  }

  /* ---------- desenho ---------- */
  function render() {
    renderSub();
    renderNotices();
    renderOverview();
    renderGroups();
    renderExts();
    renderQueues();
    renderTrunks();
    renderParking();
    renderConfs();
    sizeExts();
    if (S.open) renderDrawer();
  }

  function renderSub() {
    var d = S.data, parts = [];
    var company = root.getAttribute('data-company') || (d && d.company) || '';
    if (company) parts.push(esc(company));
    if (!S.lastOk) { $('pop-sub').innerHTML = parts.concat(['Carregando…']).join(', '); return; }
    var age = Math.round((Date.now() - S.lastOk) / 1000);
    var stale = age > Math.max(10, POLL_MS / 1000 * 4);
    var txt = !S.live ? 'atualização pausada' : (age < 4 ? 'atualizado agora' : 'atualizado há ' + dur(age));
    parts.push(stale && S.live ? '<span class="stale">' + txt + '</span>' : txt);
    $('pop-sub').innerHTML = parts.join(', ');
  }

  function renderNotices() {
    var h = '', d = S.data;
    if (S.error === 'session') {
      h += '<div class="notice error" role="alert"><p>Sua sessão do Issabel expirou. Recarregue a página para entrar de novo.</p></div>';
    } else if (S.error) {
      h += '<div class="notice error" role="alert"><p>' + esc(S.error) + (S.lastOk ? '. O painel mostra a última leitura.' : '.') + '</p>' +
        '<button type="button" class="btn" data-retry>Tentar de novo</button></div>';
    }
    if (d && d.source === 'demo') {
      h += '<div class="notice demo"><p>Demonstração: ramais, filas e ligações são fictícios e mudam sozinhos a cada poucos segundos.</p></div>';
    }
    if (d && d.warnings && d.warnings.length) {
      h += '<div class="notice"><p>Parte do painel pode estar incompleta:<ul>' +
        d.warnings.map(function (w) { return '<li>' + esc(w) + '</li>'; }).join('') + '</ul></p></div>';
    }
    if ($('pop-notices').innerHTML !== h) { $('pop-notices').innerHTML = h; fit(); sizeExts(); }
  }

  function visibleAll() {
    return ((S.data && S.data.extensions) || []).filter(function (e) { return !e.hidden; });
  }

  function renderOverview() {
    var d = S.data, now = nowSrv(), st = settings();
    var exts = visibleAll();
    var up = 0, inN = 0, outN = 0, intN = 0;
    d.calls.forEach(function (c) {
      if (c.state !== 'up') return;
      up++;
      if (c.dir === 'in') inN++; else if (c.dir === 'out') outN++; else intN++;
    });
    var ringing = exts.filter(function (e) { return e.state === 'ringing'; });
    var oldestRing = null;
    ringing.forEach(function (e) {
      (e.calls || []).forEach(function (c) { if (c.state === 'ringing' && (!oldestRing || c.since < oldestRing)) oldestRing = c.since; });
    });
    var waiting = 0, oldestWait = null;
    d.queues.forEach(function (q) {
      waiting += q.waiting.length;
      q.waiting.forEach(function (w) { if (!oldestWait || w.since < oldestWait) oldestWait = w.since; });
    });
    var real = exts.filter(function (e) { return e.state !== 'virtual'; });
    var online = real.filter(function (e) { return e.online; }).length;
    var dnd = real.filter(function (e) { return e.state === 'dnd'; }).length;
    var trunks = d.trunks.filter(function (t) { return t.status !== 'disabled'; });
    var tOn = trunks.filter(function (t) { return t.status === 'online' || t.status === 'warn'; }).length;
    var tOff = trunks.filter(function (t) { return t.status === 'offline'; });

    var mix = [];
    if (inN) mix.push(plural(inN, 'entrando', 'entrando'));
    if (outN) mix.push(plural(outN, 'saindo', 'saindo'));
    if (intN) mix.push(plural(intN, 'interna', 'internas'));

    var ringLate = oldestRing && now - oldestRing > st.ring;
    var waitLate = oldestWait && now - oldestWait > st.sl;
    $('pop-overview').innerHTML =
      metric('Em ligação', nf(up), mix.join(', ') || 'nenhuma agora', '') +
      metric('Tocando', nf(ringing.length), oldestRing ? 'há ' + since(oldestRing, 'num') : 'nenhum ramal', ringLate ? 'alert' : (ringing.length ? 'ringing' : '')) +
      metric('Na fila', nf(waiting), oldestWait ? 'maior espera ' + since(oldestWait, 'num') : 'ninguém esperando', waitLate ? 'alert' : '') +
      metric('Ramais online', nf(online) + '<small>/' + nf(real.length) + '</small>', dnd ? plural(dnd, 'em não perturbe', 'em não perturbe') : 'registrados', '') +
      metric('Troncos', nf(tOn) + '<small>/' + nf(trunks.length) + '</small>',
        tOff.length ? 'fora: ' + esc(tOff.map(function (t) { return t.label; }).join(', ')) : 'todos respondendo', tOff.length ? 'alert' : '');
  }
  function metric(label, value, note, cls) {
    return '<div class="metric ' + cls + '"><dt>' + label + '</dt><dd>' + value + '</dd><dd class="note">' + note + '</dd></div>';
  }

  function renderGroups() {
    var sel = $('pop-group'), groups = S.data.groups || [];
    var sig = groups.map(function (g) { return g.id + ':' + g.label; }).join('|');
    if (sel.getAttribute('data-sig') === sig) return;
    sel.setAttribute('data-sig', sig);
    sel.innerHTML = '<option value="">Todos os grupos</option>' + groups.map(function (g) {
      return '<option value="' + esc(g.id) + '">' + esc(g.label) + '</option>';
    }).join('');
    sel.value = groups.some(function (g) { return g.id === S.group; }) ? S.group : '';
    S.group = sel.value;
    sel.hidden = !groups.length;
  }

  function groupFilter() {
    if (!S.group) return null;
    var g = (S.data.groups || []).filter(function (x) { return x.id === S.group; })[0];
    if (!g) return null;
    var set = {};
    g.exts.forEach(function (x) { set[x] = 1; });
    return set;
  }

  /* ---------- ramais ---------- */
  function mainCall(e) {
    var cs = e.calls || [];
    var order = { up: 0, app: 1, ringing: 2, dialing: 3 };
    return cs.slice().sort(function (a, b) { return (order[a.state] || 9) - (order[b.state] || 9); })[0] || null;
  }
  function viaText(c) {
    if (c.queue) return '<span class="x-via">' + esc(queueName(c.queue)) + '</span>';
    return '';
  }
  function tileState(e) {
    var c = mainCall(e), st = settings();
    var t = '', peer = '', late = false, via = '';
    if (e.state === 'ringing' && c) {
      t = since(c.since);
      late = nowSrv() - c.since > st.ring;
      var w = who(c.peer_num, c.peer_name, c.peer_ext);
      via = viaText(c);
      peer = 'de <strong>' + esc(w.main) + '</strong>' + (w.sub ? ' ' + esc(w.sub) : '');
    } else if ((e.state === 'incall' || e.state === 'hold') && c) {
      t = since(c.answered_at || c.since);
      if (c.state === 'app') peer = esc(c.app || 'Em uso');
      else { var w2 = who(c.peer_num, c.peer_name, c.peer_ext); peer = 'com <strong>' + esc(w2.main) + '</strong>' + (w2.sub && !c.peer_ext ? ' ' + esc(w2.sub) : ''); via = viaText(c); }
    } else if (e.state === 'dialing' && c) {
      t = since(c.since);
      var w3 = who(c.peer_num, c.peer_name, c.peer_ext);
      peer = c.peer_num || c.peer_ext ? 'para <strong>' + esc(w3.main) + '</strong>' : '';
    }
    return { t: t, peer: peer, late: late, via: via };
  }
  function tileClass(e, ts) { return 'x st-' + e.state + (ts.late ? ' late' : ''); }
  function tileInner(e, ts) {
    var badges = '';
    if (e.vm_new) badges += '<span class="badge vm" title="' + plural(e.vm_new, 'recado novo', 'recados novos') + ' na caixa postal">' + ICON.vm + e.vm_new + '</span>';
    if (e.ringing_too) badges += '<span class="badge" title="Outra ligação tocando">+1</span>';
    var ring = ts.late ? 'data-ring="1"' : '';
    return '<span class="x-top"><span class="x-num">' + esc(e.ext) + '</span><span class="x-name">' + esc(e.name || '') + '</span>' +
      (badges ? '<span class="x-badges">' + badges + '</span>' : '') + '</span>' +
      '<span class="x-state" ' + ring + '><b>' + (LABEL[e.state] || e.state) + '</b>' + ts.via + ts.t + '</span>' +
      (ts.peer ? '<span class="x-peer">' + ts.peer + '</span>' : '');
  }

  function renderExts() {
    if (!S.data) return;
    var all = visibleAll(), gset = groupFilter(), q = S.q;
    var base = gset ? all.filter(function (e) { return gset[e.ext]; }) : all;
    var counts = { all: base.length, free: 0, busy: 0, ringing: 0, offline: 0 };
    base.forEach(function (e) { var k = category(e.state); if (counts[k] != null) counts[k]++; });
    $('pop-filter').innerHTML = FILTERS.map(function (f) {
      return '<button type="button" data-f="' + f[0] + '" aria-pressed="' + (S.filter === f[0]) + '">' + f[1] +
        '<span class="n">' + counts[f[0]] + '</span></button>';
    }).join('');
    $('pop-ext-count').textContent = nf(all.length);

    var list = base.filter(function (e) {
      if (S.filter !== 'all' && category(e.state) !== S.filter) return false;
      if (q && (e.ext + ' ' + (e.name || '')).toLowerCase().indexOf(q) === -1) return false;
      return true;
    });

    var box = $('pop-exts');
    if (!list.length) {
      S.tiles = {};
      box.innerHTML = '<div class="empty" style="grid-column:1/-1"><b>Nenhum ramal aqui.</b>' +
        (q ? 'Nada com “' + esc(S.q) + '”. Confira o número ou o nome.' : 'Troque o filtro acima para ver os outros ramais.') + '</div>';
      return;
    }
    var stray = box.querySelector('.empty');
    if (stray) box.removeChild(stray);

    // Reconciliação por ramal: o mesmo elemento continua no lugar, então o pulso de
    // quem está tocando não reinicia a cada atualização e o foco do teclado não se perde.
    var seen = {}, prev = null;
    list.forEach(function (e) {
      var el = S.tiles[e.ext];
      if (!el) {
        el = document.createElement('div');
        el.setAttribute('role', 'button');
        el.setAttribute('tabindex', '0');
        el.setAttribute('data-ext', e.ext);
        S.tiles[e.ext] = el;
      }
      var ts = tileState(e), html = tileInner(e, ts), cls = tileClass(e, ts);
      if (el._h !== html) { el.innerHTML = html; el._h = html; }
      if (el.className !== cls) el.className = cls;
      el.setAttribute('aria-label', e.ext + ' ' + (e.name || '') + ', ' + (LABEL[e.state] || e.state) + '. Abrir detalhes');
      var want = prev ? prev.nextSibling : box.firstChild;
      if (want !== el) box.insertBefore(el, want);
      prev = el;
      seen[e.ext] = 1;
    });
    Object.keys(S.tiles).forEach(function (k) {
      if (!seen[k]) { if (S.tiles[k].parentNode) S.tiles[k].parentNode.removeChild(S.tiles[k]); delete S.tiles[k]; }
    });
    sizeExts();
  }

  /* ---------- ligações ---------- */
  var CSTATE = { up: 'Em ligação', ringing: 'Tocando', queue: 'Na fila', ivr: 'Na URA', app: 'Em uso', dialing: 'Chamando' };

  /* ---------- filas ---------- */
  var MSTATE = { free: 'livre', busy: 'em ligação', ringing: 'tocando', paused: 'em pausa', offline: 'offline', unknown: 'sem status' };

  function renderQueues() {
    var qs = S.data.queues || [], st = settings(), now = nowSrv();
    var waitingAll = 0;
    qs.forEach(function (q) { waitingAll += q.waiting.length; });
    $('pop-q-count').textContent = qs.length ? nf(qs.length) : '';
    if (!qs.length) {
      $('pop-queues').innerHTML = '<p class="empty panel">Nenhuma fila cadastrada no PABX.</p>';
      return;
    }
    $('pop-queues').innerHTML = qs.map(function (q) {
      var oldest = null;
      q.waiting.forEach(function (w) { if (!oldest || w.since < oldest) oldest = w.since; });
      var late = oldest && now - oldest > st.sl;
      var h = '<article class="queue ' + (q.waiting.length ? (late ? 'q-late' : 'q-ok') : '') + '"' +
        (oldest ? ' data-oldest="' + oldest + '" data-sl="' + st.sl + '"' : '') + '>' +
        '<div class="q-top"><b>' + esc(q.name || 'Fila ' + q.num) + '</b><span class="q-num">' + esc(q.num) + '</span>' +
        (q.talking ? '<span class="q-talk">' + plural(q.talking, 'em atendimento', 'em atendimento') + '</span>' : '') +
        '<span class="q-wait"><strong>' + q.waiting.length + '</strong> esperando</span></div>';
      if (q.waiting.length) {
        h += '<ul class="callers">' + q.waiting.slice(0, 5).map(function (w) {
          var ww = who(w.num, w.name, null);
          return '<li><span><span class="pos">' + w.pos + 'º</span>' + esc(ww.main) + (ww.sub ? ' <span class="pos">' + esc(ww.sub) + '</span>' : '') + '</span>' +
            since(w.since, now - w.since > st.sl ? 'late' : 'num') + '</li>';
        }).join('') + (q.waiting.length > 5 ? '<li><span class="pos">e mais ' + (q.waiting.length - 5) + '</span></li>' : '') + '</ul>';
      }
      if (q.members.length) {
        h += '<div class="members" aria-label="Quem atende esta fila">' + q.members.map(function (m) {
          var label = (m.ext ? m.ext + ' ' : '') + (m.name || '');
          var title = label + ': ' + (MSTATE[m.status] || m.status) + (m.pause_reason ? ' (' + m.pause_reason + ')' : '') +
            (m.calls_taken ? ', ' + plural(m.calls_taken, 'atendida', 'atendidas') : '');
          return '<span class="member m-' + m.status + '" title="' + esc(title) + '"><i></i><span>' + esc(label) +
            (m.status === 'paused' ? ' <em>' + esc(m.pause_reason || 'pausa') + '</em>' : '') + '</span></span>';
        }).join('') + '</div>';
      } else {
        h += '<p class="q-today">Ninguém atendendo esta fila agora.</p>';
      }
      var t = q.today || null, sr = q.since_reset;
      if (t) {
        h += '<p class="q-today">' +
          '<span>Atendidas <b>' + nf(t.answered) + '</b></span>' +
          '<span class="' + (t.lost ? 'bad' : '') + '">Perdidas <b>' + nf(t.lost) + '</b></span>' +
          (t.sla_pct != null ? '<span title="Atendidas em até ' + st.sl + ' segundos de espera">No prazo <b>' + t.sla_pct + '%</b></span>' : '') +
          (t.avg_wait != null ? '<span title="Espera média até atender">Espera <b>' + clock(t.avg_wait) + '</b></span>' : '') +
          '</p>';
      } else if (sr) {
        h += '<p class="q-today" title="Contagem do Asterisk desde o último reinício"><span>Atendidas <b>' + nf(sr.answered) + '</b></span>' +
          '<span class="' + (sr.lost ? 'bad' : '') + '">Perdidas <b>' + nf(sr.lost) + '</b></span></p>';
      }
      return h + '</article>';
    }).join('');
  }

  /* ---------- troncos, estacionamento, conferências ---------- */
  var TSTATE = { online: 'online', warn: 'warn', offline: 'offline', unknown: '', disabled: '' };
  function renderTrunks() {
    var ts = (S.data.trunks || []).slice().sort(function (a, b) {
      return (a.status === 'disabled') - (b.status === 'disabled');
    });
    var on = ts.filter(function (t) { return t.status === 'online' || t.status === 'warn'; }).length;
    var all = ts.filter(function (t) { return t.status !== 'disabled'; }).length;
    $('pop-trunk-count').textContent = all ? on + ' de ' + all : '';
    if (!ts.length) { $('pop-trunks').innerHTML = '<li class="empty">Nenhum tronco cadastrado.</li>'; return; }
    $('pop-trunks').innerHTML = ts.map(function (t) {
      var title = t.label + ' (' + t.channel + '): ' + t.detail + (t.rtt_ms != null ? ', ' + t.rtt_ms + ' ms' : '') +
        (t.calls ? ', ' + plural(t.calls, 'ligação', 'ligações') + ' agora' : '');
      return '<li class="t-' + t.status + '" title="' + esc(title) + '"><span class="dot ' + (TSTATE[t.status] || '') + '" aria-hidden="true"></span>' +
        '<span class="cl">' + esc(t.label) + '</span>' +
        (t.status === 'offline' ? '<span class="cn bad">fora</span>' : (t.calls ? '<span class="cn">' + t.calls + '</span>' : '')) + '</li>';
    }).join('');
  }

  /* "701, 702, 703, 704, 70001..." -> "701–704, 70001–70050" */
  function ranges(nums) {
    var n = nums.map(Number).filter(function (x) { return !isNaN(x); }).sort(function (a, b) { return a - b; });
    var out = [], a = null, p = null;
    n.forEach(function (x) {
      if (a === null) { a = p = x; return; }
      if (x === p + 1) { p = x; return; }
      out.push(a === p ? String(a) : a + '–' + p); a = p = x;
    });
    if (a !== null) out.push(a === p ? String(a) : a + '–' + p);
    return out.join(', ');
  }

  /* Só as vagas ocupadas viram cartão; as livres cabem numa linha (há PABX com 50+ vagas). */
  function renderParking() {
    var slots = S.data.parking || [], now = nowSrv();
    $('pop-park-sec').hidden = !slots.length;
    if (!slots.length) return;
    var busy = slots.filter(function (s) { return s.call; });
    var free = slots.filter(function (s) { return !s.call; });
    var h = busy.map(function (s) {
      var w = who(s.call.num, s.call.name, null);
      var late = s.call.timeout_at && now > s.call.timeout_at - 10;
      return '<div class="slot busy' + (late ? ' late' : '') + '" title="' + esc(w.main) + (s.call.by ? ', estacionada pelo ramal ' + esc(s.call.by) : '') + '">' +
        '<b>' + esc(s.space) + '</b>' + since(s.call.since) + '<span class="who">' + esc(w.main) + '</span></div>';
    }).join('');
    var freeTxt = !free.length ? 'Nenhuma vaga livre.'
      : (busy.length ? plural(free.length, 'vaga livre', 'vagas livres') : 'Nenhuma ligação estacionada, ' + plural(free.length, 'vaga livre', 'vagas livres')) +
        ': <span>' + esc(ranges(free.map(function (s) { return s.space; }))) + '</span>';
    $('pop-park').innerHTML = h + '<p class="park-free">' + freeTxt + '</p>';
  }

  function renderConfs() {
    var cs = S.data.conferences || [];
    $('pop-conf-sec').hidden = !cs.length;
    if (!cs.length) return;
    $('pop-confs').innerHTML = cs.map(function (c) {
      return '<li title="Ramal ' + esc(c.num) + '"><span class="dot ' + (c.parties ? 'online' : '') + '" aria-hidden="true"></span>' +
        '<span class="cl">' + esc(c.name || 'Sala ' + c.num) + '</span>' +
        (c.parties ? '<span class="cn">' + c.parties + '</span>' : '<span class="cn muted">vazia</span>') + '</li>';
    }).join('');
  }

  /* ---------- detalhes do ramal ---------- */
  function findExt(ext) {
    var all = (S.data && S.data.extensions) || [];
    for (var i = 0; i < all.length; i++) if (all[i].ext === ext) return all[i];
    return null;
  }
  function openDrawer(ext, from) {
    S.open = ext;
    S.returnFocus = from || S.returnFocus;
    renderDrawer();
    var b = root.querySelector('.drawer [data-close]');
    if (b) b.focus();
  }
  function closeDrawer() {
    S.open = null;
    $('pop-drawer').innerHTML = '';
    var t = S.returnFocus && S.tiles[S.returnFocus.getAttribute('data-ext')];
    if (t && t.parentNode) t.focus();
    S.returnFocus = null;
  }
  function renderDrawer() {
    var e = findExt(S.open);
    if (!e) { closeDrawer(); return; }
    var calls = (e.calls || []).map(function (c) {
      var w = who(c.peer_num, c.peer_name, c.peer_ext);
      var what = c.state === 'app' ? esc(c.app || 'Em uso') : (c.dir === 'in' ? 'De ' : 'Para ') + '<b>' + esc(w.main) + '</b>' + (w.sub ? ' <span class="sub">' + esc(w.sub) + '</span>' : '');
      var extra = [];
      if (c.queue) extra.push('fila ' + esc(queueName(c.queue)));
      if (c.trunk) extra.push(esc(trunkName(c.trunk)));
      return '<li><span>' + what + (extra.length ? '<span class="sub">pela ' + extra.join(', por ') + '</span>' : '') + '</span>' +
        '<span><span class="sub">' + (CSTATE[c.state] || c.state) + '</span>' + since(c.state === 'up' ? (c.answered_at || c.since) : c.since, 'num') + '</span></li>';
    }).join('');
    var queues = (S.data.queues || []).map(function (q) {
      var m = q.members.filter(function (x) { return x.ext === e.ext; })[0];
      if (!m) return '';
      return '<li><span>' + esc(q.name || 'Fila ' + q.num) + '<span class="sub">Fila ' + esc(q.num) + (m.dynamic ? ', entrou pelo login' : '') + '</span></span>' +
        '<span>' + (MSTATE[m.status] || m.status) + (m.pause_reason ? ' (' + esc(m.pause_reason) + ')' : '') +
        '<span class="sub">' + plural(m.calls_taken || 0, 'atendida', 'atendidas') + '</span></span></li>';
    }).join('');
    var dev = '';
    if (e.ip) dev += '<dt>Endereço</dt><dd>' + esc(e.ip) + '</dd>';
    if (e.agent) dev += '<dt>Aparelho</dt><dd>' + esc(e.agent) + '</dd>';
    if (e.rtt_ms != null) dev += '<dt>Resposta</dt><dd>' + e.rtt_ms + ' ms</dd>';
    dev += '<dt>Tecnologia</dt><dd>' + esc((e.tech || '').toUpperCase()) + '</dd>';
    if (e.vm_new != null) dev += '<dt>Caixa postal</dt><dd>' + (e.vm_new ? plural(e.vm_new, 'recado novo', 'recados novos') : 'sem recados novos') + '</dd>';

    var title = esc(e.name || 'Ramal ' + e.ext) + '<span>Ramal ' + esc(e.ext) + '</span>';
    var body =
      '<div><span class="d-state"><span class="dot s-' + e.state + '" aria-hidden="true"></span>' + (LABEL[e.state] || e.state) + '</span></div>' +
      (calls ? '<section><h3>Ligação agora</h3><ul class="d-list">' + calls + '</ul></section>' : '') +
      (queues ? '<section><h3>Filas</h3><ul class="d-list">' + queues + '</ul></section>' : '') +
      '<section><h3>Aparelho</h3><dl class="kv">' + dev + '</dl></section>';

    // A gaveta é criada uma vez ao abrir; nas atualizações só o conteúdo muda.
    // Recriar tudo repetia a animação de entrada, voltava a rolagem ao topo e tirava o foco.
    var box = $('pop-drawer'), aside = box.querySelector('.drawer');
    if (!aside || aside.getAttribute('data-ext') !== e.ext) {
      box.innerHTML =
        '<div class="scrim" data-close></div>' +
        '<aside class="drawer" role="dialog" aria-modal="true" aria-labelledby="pop-d-title" data-ext="' + esc(e.ext) + '">' +
          '<div class="d-head"><h2 id="pop-d-title"></h2><button type="button" class="btn ghost" data-close>Fechar</button></div>' +
          '<div class="d-body"></div>' +
        '</aside>';
      aside = box.querySelector('.drawer');
    }
    var h2 = aside.querySelector('#pop-d-title'), b = aside.querySelector('.d-body');
    if (h2._h !== title) { h2.innerHTML = title; h2._h = title; }
    // Os cronômetros já andam sozinhos (tick); compara sem eles para não reescrever a cada ciclo.
    var key = body.replace(/(data-since="[^"]*">)[^<]*/g, '$1');
    if (b._k !== key) { b.innerHTML = body; b._k = key; }
  }

  /* ---------- cronômetros ---------- */
  function tick() {
    if (!S.data) return;
    var now = nowSrv(), st = settings();
    var els = root.querySelectorAll('[data-since]');
    for (var i = 0; i < els.length; i++) {
      var s = parseFloat(els[i].getAttribute('data-since'));
      els[i].textContent = clock(now - s);
    }
    // Ramal que passa do limite tocando fica vermelho sem esperar o próximo ciclo.
    for (var k in S.tiles) {
      var el = S.tiles[k];
      if (el.className.indexOf('st-ringing') === -1) continue;
      var t = el.querySelector('.x-state [data-since]');
      if (!t) continue;
      var late = now - parseFloat(t.getAttribute('data-since')) > st.ring;
      if (late !== el.classList.contains('late')) el.classList.toggle('late', late);
    }
    var qs = root.querySelectorAll('.queue[data-oldest]');
    for (var j = 0; j < qs.length; j++) {
      var ql = now - parseFloat(qs[j].getAttribute('data-oldest')) > parseFloat(qs[j].getAttribute('data-sl'));
      qs[j].classList.toggle('q-late', ql);
      qs[j].classList.toggle('q-ok', !ql);
    }
    renderSub();
  }

  setInterval(tick, 1000);
  fit();
  poll();
})();
