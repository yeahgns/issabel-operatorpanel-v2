/* Painel do Operador - dados fictícios no mesmo formato do snapshot real.
   Simula a operação: ligações entram, tocam, são atendidas, esperam na fila e terminam. */
(function () {
  'use strict';

  var EXTS = [
    ['200', 'Recepção'], ['201', 'Mariana Lopes'], ['202', 'Juliana Prado', 'sip'], ['203', 'Gerson Matos'],
    ['204', 'Financeiro'], ['205', 'Samuel Castro'], ['206', 'Eduarda Vieira'], ['207', 'Rafael Teixeira'],
    ['208', 'Patrícia Dias'], ['209', 'Leandro Moura'], ['210', 'Bianca Torres', 'sip'], ['211', 'Gustavo Neves'],
    ['212', 'Gabriel Okada'], ['213', 'Vanessa Reis'], ['214', 'Igor Fontes'], ['215', 'Sara Pinheiro'],
    ['216', 'Tatiane Rocha'], ['217', 'Paulo Henrique'], ['220', 'Elias Santana'], ['250', 'Jonas Moreira'],
    ['300', 'Sala de reunião'], ['400', 'Caixa postal geral', 'custom']
  ];
  var OFFLINE = { '206': 1, '210': 1 };
  var DND = { '212': 1 };
  var PAUSED = { '215': 'Almoço' };
  var DEVICE = {
    '201': ['192.168.0.21', 'Yealink SIP-T31P', 8], '202': ['192.168.0.22', 'Grandstream GXP1610', 12],
    '205': ['10.0.0.55', 'MicroSIP 3.21', 31], '211': ['192.168.0.31', 'Yealink SIP-T31P', 7], '200': ['192.168.0.20', 'Yealink SIP-T46U', 6]
  };
  var QUEUES = [
    { num: '600', name: 'Comercial', ring: 'all', members: ['201', '202', '206', '207', '208'], base: [38, 3, 35, 9] },
    { num: '602', name: 'Suporte', ring: 'one', members: ['205', '209', '211', '214', '215'], base: [21, 1, 17, 14] },
    { num: '603', name: 'Financeiro', ring: 'all', members: ['203', '204', '213'], base: [9, 0, 9, 6] },
    { num: '604', name: 'Recepção', ring: 'all', members: ['200', '216', '217'], base: [52, 2, 50, 5] },
    { num: '610', name: 'Plantão', ring: 'all', members: ['210', '250'], base: [0, 0, 0, 0] }
  ];
  var TRUNKS = [
    { id: 't1', label: 'Operadora Vivo', channel: 'SIP/vivo-sip', tech: 'sip', status: 'online', detail: 'respondendo', rtt_ms: 23 },
    { id: 't2', label: 'WhatsApp Business', channel: 'PJSIP/whatsapp', tech: 'pjsip', status: 'online', detail: 'registrado', rtt_ms: 41 },
    { id: 't3', label: 'Operadora Claro', channel: 'SIP/claro', tech: 'sip', status: 'offline', detail: 'sem resposta da operadora', rtt_ms: null },
    { id: 't4', label: 'Backup', channel: 'SIP/backup', tech: 'sip', status: 'disabled', detail: 'desativado no PABX', rtt_ms: null }
  ];
  var NAMES = ['', '', '', 'MERCADO BOM', 'JOAO P', 'CONSTRUTORA ALFA', 'CLINICA VIDA', '', 'ANA C', 'AUTO PECAS SUL'];
  var VM = { '201': 2, '204': 1 };

  var name = {}, qByNum = {};
  EXTS.forEach(function (e) { name[e[0]] = e[1]; });
  QUEUES.forEach(function (q) { q.stats = { answered: q.base[0], lost: q.base[1], inSl: q.base[2], waitSum: q.base[0] * q.base[3] }; qByNum[q.num] = q; });

  var calls = [], parked = {}, seq = 1, lastStep = 0, started = false, conf = 0;
  function rnd(a, b) { return a + Math.random() * (b - a); }
  function pick(a) { return a[Math.floor(Math.random() * a.length)]; }
  function phoneBR() {
    var ddd = pick(['11', '11', '11', '19', '19', '21', '31', '41', '47', '51', '61']);
    return Math.random() < .8 ? ddd + '9' + String(Math.floor(rnd(10000000, 99999999))) : ddd + String(Math.floor(rnd(30000000, 39999999)));
  }

  function busyExts() {
    var b = {};
    calls.forEach(function (c) {
      if (c.ext && c.state !== 'queue' && c.state !== 'ivr') b[c.ext] = 1;
      if (c.peer && (c.state === 'up' || c.state === 'ringing')) b[c.peer] = 1;
      (c.ring || []).forEach(function (x) { b[x] = 1; });
    });
    return b;
  }
  function freeMembers(q) {
    var b = busyExts();
    return q.members.filter(function (x) { return !b[x] && !OFFLINE[x] && !DND[x] && !PAUSED[x]; });
  }

  function seed(now) {
    calls = [
      { id: 'c' + seq++, dir: 'out', ext: '205', num: '011987654321', trunk: 't1', state: 'up', since: now - 192, answered_at: now - 185, endAt: now + 140 },
      { id: 'c' + seq++, dir: 'out', ext: '209', num: '1133224455', trunk: 't1', state: 'up', since: now - 64, answered_at: now - 58, endAt: now + 50 },
      { id: 'c' + seq++, dir: 'int', ext: '211', peer: '214', state: 'up', since: now - 100, answered_at: now - 97, endAt: now + 90 },
      { id: 'c' + seq++, dir: 'in', num: '11955554444', name: '', trunk: 't1', queue: '602', state: 'queue', since: now - 31 },
      { id: 'c' + seq++, dir: 'in', num: '19996234875', name: 'CONSTRUTORA ALFA', trunk: 't1', queue: '600', state: 'ringing', since: now - 9, ringSince: now - 8, ring: ['201', '202', '207', '208'], answerAt: now + 7 },
      { id: 'c' + seq++, dir: 'in', num: '21988887777', name: 'JOAO P', trunk: 't1', direct: '204', state: 'ringing', since: now - 24, ringSince: now - 24, ring: ['204'], answerAt: now + 6 },
      { id: 'c' + seq++, dir: 'int', ext: '203', app: 'Caixa postal', state: 'app', since: now - 30, endAt: now + 18 },
      { id: 'c' + seq++, dir: 'in', num: '1932105500', name: 'CLINICA VIDA', trunk: 't2', queue: '604', ext: '216', state: 'up', since: now - 412, answered_at: now - 398, endAt: now + 70 },
      { id: 'c' + seq++, dir: 'in', num: '11977776666', name: '', trunk: 't1', queue: '600', state: 'ivr', since: now - 5, ivrEnd: now + 7 }
    ];
    parked = { '702': { num: '11933332222', name: 'MERCADO BOM', since: now - 18, timeout_at: now + 27, by: '201' } };
    conf = 3;
  }

  function step(now) {
    if (!started) { seed(now); started = true; lastStep = now; return; }
    var dt = Math.max(0, Math.min(10, now - lastStep));
    lastStep = now;
    var keep = [];
    calls.forEach(function (c) {
      var q = c.queue ? qByNum[c.queue] : null;
      if (c.state === 'ivr' && now >= c.ivrEnd) { c.state = 'queue'; c.since = now; }
      if (c.state === 'queue' && q) {
        var free = freeMembers(q);
        if (free.length) {
          c.state = 'ringing'; c.ringSince = now;
          c.ring = q.ring === 'all' ? free : [pick(free)];
          c.answerAt = now + rnd(3, Math.random() < .25 ? 28 : 14);
        } else if (now - c.since > 150) {
          q.stats.lost++;
          return; // desistiu
        }
      }
      if (c.state === 'ringing' && now >= c.answerAt) {
        var who = pick(c.ring);
        if (c.peer) { c.state = 'up'; } else { c.ext = who; c.state = 'up'; }
        c.ring = [];
        c.answered_at = now;
        c.endAt = now + rnd(35, 300);
        if (q) {
          var w = now - c.since;
          q.stats.answered++; q.stats.waitSum += w;
          if (w <= 20) q.stats.inSl++;
        }
      }
      if (c.state === 'dialing' && now >= c.answerAt) { c.state = 'up'; c.answered_at = now; c.endAt = now + rnd(30, 240); }
      if ((c.state === 'up' || c.state === 'app') && now >= c.endAt) {
        if (Math.random() < .12 && c.dir === 'in' && !Object.keys(parked).length) {
          parked['70' + (1 + Math.floor(Math.random() * 4))] = { num: c.num, name: c.name, since: now, timeout_at: now + 45, by: c.ext };
        }
        return;
      }
      keep.push(c);
    });
    calls = keep;
    Object.keys(parked).forEach(function (k) { if (now > parked[k].timeout_at) delete parked[k]; });
    if (Math.random() < dt * 0.02) conf = conf ? 0 : 3;

    var active = calls.length;
    if (active < 9 && Math.random() < Math.min(.9, dt * 0.18)) spawn(now);
  }

  function spawn(now) {
    var r = Math.random(), b = busyExts();
    var idle = EXTS.map(function (e) { return e[0]; }).filter(function (x) { return !b[x] && !OFFLINE[x] && !DND[x] && x !== '400' && x !== '300'; });
    if (r < .55) {
      var q = pick([QUEUES[0], QUEUES[0], QUEUES[1], QUEUES[1], QUEUES[2], QUEUES[3]]);
      calls.push({ id: 'c' + seq++, dir: 'in', num: phoneBR(), name: pick(NAMES), trunk: Math.random() < .8 ? 't1' : 't2', queue: q.num,
                   state: Math.random() < .5 ? 'ivr' : 'queue', since: now, ivrEnd: now + rnd(4, 10) });
    } else if (r < .68 && idle.length) {
      var d = pick(idle);
      calls.push({ id: 'c' + seq++, dir: 'in', num: phoneBR(), name: pick(NAMES), trunk: 't1', direct: d, state: 'ringing', since: now, ringSince: now, ring: [d], answerAt: now + rnd(4, 18) });
    } else if (r < .92 && idle.length) {
      calls.push({ id: 'c' + seq++, dir: 'out', ext: pick(idle), num: phoneBR(), trunk: 't1', state: 'dialing', since: now, answerAt: now + rnd(4, 12) });
    } else if (idle.length > 1) {
      var a = pick(idle), p = pick(idle.filter(function (x) { return x !== a; }));
      calls.push({ id: 'c' + seq++, dir: 'int', ext: a, peer: p, state: 'ringing', since: now, ringSince: now, ring: [p], answerAt: now + rnd(3, 9) });
    }
  }

  /* ---------- snapshot no formato do backend ---------- */
  function ref(x) { return x ? { num: x, name: name[x], ext: x } : null; }

  function extItems(x) {
    var items = [];
    calls.forEach(function (c) {
      var mineRing = (c.ring || []).indexOf(x) !== -1;
      if (c.ext === x && (c.state === 'up' || c.state === 'dialing' || c.state === 'app')) {
        var peer = c.dir === 'int' ? { num: c.peer, name: c.peer ? name[c.peer] : null, ext: c.peer } : { num: c.num, name: c.name || null, ext: null };
        items.push({ id: c.id, dir: c.dir === 'in' ? 'in' : 'out', state: c.state, since: c.since, answered_at: c.answered_at || null,
                     peer_num: peer.num || null, peer_name: peer.name, peer_ext: peer.ext || null, external: !!c.trunk,
                     queue: c.queue || null, trunk: c.trunk || null, app: c.app || null });
      } else if (c.peer === x && c.state === 'up') {
        items.push({ id: c.id, dir: 'in', state: 'up', since: c.since, answered_at: c.answered_at, peer_num: c.ext, peer_name: name[c.ext], peer_ext: c.ext,
                     external: false, queue: null, trunk: null, app: null });
      } else if (mineRing && c.state === 'ringing') {
        var from = c.dir === 'int' ? { num: c.ext, name: name[c.ext], ext: c.ext } : { num: c.num, name: c.name || null, ext: null };
        items.push({ id: c.id, dir: 'in', state: 'ringing', since: c.ringSince || c.since, answered_at: null, peer_num: from.num, peer_name: from.name,
                     peer_ext: from.ext || null, external: !!c.trunk, queue: c.queue || null, trunk: c.trunk || null, app: null });
      }
    });
    return items;
  }

  function snapshot() {
    var now = Date.now() / 1000;
    step(now);

    var extensions = EXTS.map(function (e) {
      var x = e[0], items = extItems(x), st;
      var up = items.filter(function (i) { return i.state === 'up' || i.state === 'app'; }).length;
      var ringing = items.filter(function (i) { return i.state === 'ringing'; }).length;
      var dialing = items.filter(function (i) { return i.state === 'dialing'; }).length;
      if (e[2] === 'custom') st = 'virtual';
      else if (OFFLINE[x]) st = 'offline';
      else if (up) st = 'incall';
      else if (ringing) st = 'ringing';
      else if (dialing) st = 'dialing';
      else if (DND[x]) st = 'dnd';
      else st = 'free';
      var dev = DEVICE[x] || [];
      return { ext: x, name: e[1], tech: e[2] || 'pjsip', hidden: false, state: st, online: st !== 'offline' && st !== 'virtual' ? true : (st === 'virtual' ? null : false),
               dnd: !!DND[x], ringing_too: up > 0 && ringing > 0, ip: OFFLINE[x] ? null : dev[0] || null, agent: dev[1] || null,
               rtt_ms: dev[2] != null ? dev[2] : null, vm_new: VM[x] != null ? VM[x] : (e[2] === 'custom' ? null : 0), calls: items };
    });
    var stateOf = {};
    extensions.forEach(function (e) { stateOf[e.ext] = e.state; });

    var queues = QUEUES.map(function (q) {
      var waiting = calls.filter(function (c) { return c.queue === q.num && (c.state === 'queue' || c.state === 'ringing'); })
        .sort(function (a, b) { return a.since - b.since; })
        .map(function (c, i) { return { pos: i + 1, num: c.num, name: c.name || null, since: c.since }; });
      var ringingFor = {};
      calls.forEach(function (c) { if (c.queue === q.num && c.state === 'ringing') (c.ring || []).forEach(function (x) { ringingFor[x] = 1; }); });
      var s = q.stats, base = s.answered + s.lost;
      return {
        num: q.num, name: q.name, strategy: q.ring === 'all' ? 'ringall' : 'rrmemory',
        members: q.members.map(function (x) {
          var st = stateOf[x], m;
          if (PAUSED[x]) m = 'paused';
          else if (st === 'offline') m = 'offline';
          else if (ringingFor[x]) m = 'ringing';
          else if (st === 'free') m = 'free';
          else m = 'busy';
          return { ext: x, name: name[x], status: m, paused: !!PAUSED[x], pause_reason: PAUSED[x] || null, dynamic: x === '215', calls_taken: Math.round(s.answered / q.members.length), last_call: null };
        }),
        waiting: waiting,
        today: { offered: base, answered: s.answered, lost: s.lost, abandoned: s.lost, sla_pct: base ? Math.round(s.inSl / base * 100) : null,
                 avg_wait: s.answered ? Math.round(s.waitSum / s.answered) : null, avg_talk: null },
        since_reset: null,
        talking: calls.filter(function (c) { return c.queue === q.num && c.state === 'up'; }).length
      };
    });

    var out = calls.map(function (c) {
      var from, to = [];
      if (c.dir === 'in') {
        from = { num: c.num, name: c.name || null, ext: null };
        if (c.state === 'up') to = [ref(c.ext)];
        else if (c.state === 'ringing') to = c.ring.map(ref);
      } else if (c.dir === 'out') {
        from = ref(c.ext); to = [{ num: c.num, name: null, ext: null }];
      } else {
        from = ref(c.ext);
        to = c.app ? [{ num: null, name: c.app, ext: null }] : [ref(c.peer)];
      }
      return { id: c.id, dir: c.dir, state: c.state, since: c.since, answered_at: c.answered_at || null, from: from, to: to, queue: c.queue || null, trunk: c.trunk || null };
    }).sort(function (a, b) { return a.since - b.since; });

    var tcount = {};
    calls.forEach(function (c) { if (c.trunk) tcount[c.trunk] = (tcount[c.trunk] || 0) + 1; });

    return {
      source: 'demo', now: now, version: 'demo', company: '',
      settings: { service_level_seconds: 20, long_ring_seconds: 20 },
      extensions: extensions,
      queues: queues,
      calls: out,
      trunks: TRUNKS.map(function (t) { var o = {}; for (var k in t) o[k] = t[k]; o.calls = tcount[t.id] || 0; return o; }),
      parking: ['701', '702', '703', '704'].map(function (s) { return { space: s, lot: 'default', call: parked[s] || null }; }),
      conferences: [{ num: '800', name: 'Sala de reunião', parties: conf }, { num: '801', name: 'Diretoria', parties: 0 }],
      groups: QUEUES.map(function (q) { return { id: 'q' + q.num, label: q.name, exts: q.members.slice() }; }),
      warnings: []
    };
  }

  window.POP_DEMO = { snapshot: snapshot };
})();
