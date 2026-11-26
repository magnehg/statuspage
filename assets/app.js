// Driftsstatus. Henter api.php og tegner sidene. Tekst fra kildene settes alltid med textContent.
(function () {
  'use strict';

  // Tekstene på begge språk ligger i assets/tekster.json og er lagt inn i siden (#tekster).
  var TEKSTER = JSON.parse(document.getElementById('tekster').textContent);
  var sprak = 'nb';
  // t('nøkkel', {n: 2}) – med n === 1 brukes «nøkkel_1» hvis den finnes.
  function t(nokkel, p) {
    var tab = TEKSTER[sprak] || TEKSTER.nb;
    var s = (p && p.n === 1 && tab[nokkel + '_1']) || tab[nokkel] || TEKSTER.nb[nokkel] || nokkel;
    return s.replace(/\{(\w+)\}/g, function (m, k) { return p && p[k] !== undefined ? p[k] : m; });
  }
  function nt(niva) { return t('niva.' + niva); }
  function navn(x) { return (sprak === 'en' && x.navn_en) || x.navn; }
  function knavn(h) { return (sprak === 'en' && h.kilde_navn_en) || h.kilde_navn; }
  // Tekst fra leverandørene står på norsk; merk den så skjermlesere uttaler den riktig.
  function kildetekst(tekst) { return sprak === 'nb' ? tekst : el('span', { lang: 'nb', text: tekst }); }
  var RANG = { ok: 0, melding: 1, vedlikehold: 2, redusert: 3, delvis: 4, nede: 5, ukjent: -1 };
  function fase(st) { return st ? t('fase.' + st) : ''; }
  var IKON = {
    nede: '<path d="M8 2h8l6 6v8l-6 6H8l-6-6V8z"/><path d="M15 9l-6 6M9 9l6 6"/>',
    delvis: '<path d="M12 3l10 18H2z"/><path d="M12 10v5M12 18h.01"/>',
    redusert: '<path d="M4 19a9 9 0 1 1 16 0"/><path d="M12 14l4-5"/><circle cx="12" cy="15" r="1.4"/>',
    melding: '<path d="M4 5h16v11H9l-5 4z"/><path d="M12 8v3M12 13.5h.01"/>',
    vedlikehold: '<path d="M14.7 6.3a4 4 0 0 0-5.4 5.4L3 18l3 3 6.3-6.3a4 4 0 0 0 5.4-5.4l-2.5 2.5-2.5-.5-.5-2.5z"/>',
    ok: '<circle cx="12" cy="12" r="9"/><path d="M8 12.5l2.8 2.8L16 9.8"/>',
    ukjent: '<circle cx="12" cy="12" r="9"/><path d="M9.5 9.5a2.5 2.5 0 1 1 3.5 2.3c-.6.3-1 .9-1 1.6v.6M12 17h.01"/>',
    ut: '<path d="M14 4h6v6"/><path d="M20 4l-9 9"/><path d="M18 14v6H4V6h6"/>'
  };
  var MAKS_VENT = 5 * 60 * 1000;

  var $ = function (id) { return document.getElementById(id); };
  var status = null, tidslinje = null, valgtCelle = null, tidHentet = 0, tidtaker = null;
  var sett = lesSett(), tittel = document.title, UTHEV_MS = 15 * 60 * 1000, utlopTimer = null;

  // ------------------------------------------------------------------ hjelpere
  function el(tag, attr) {
    var e = document.createElement(tag);
    if (attr) {
      Object.keys(attr).forEach(function (k) {
        var v = attr[k];
        if (v === null || v === undefined || v === false) return;
        if (k === 'class') e.className = v;
        else if (k === 'text') e.textContent = v;
        else if (k.slice(0, 2) === 'on') e.addEventListener(k.slice(2), v);
        else e.setAttribute(k, v === true ? '' : v);
      });
    }
    for (var i = 2; i < arguments.length; i++) {
      var b = arguments[i];
      if (b === null || b === undefined) continue;
      e.appendChild(typeof b === 'string' ? document.createTextNode(b) : b);
    }
    return e;
  }
  function ikon(navn) {
    var ns = 'http://www.w3.org/2000/svg';
    var s = new DOMParser().parseFromString('<svg xmlns="' + ns + '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + (IKON[navn] || IKON.ukjent) + '</svg>', 'image/svg+xml').documentElement;
    return document.importNode(s, true);
  }
  function trygg(url, reserve) {
    return /^https:\/\//i.test(url || '') ? url : (reserve || '#');
  }
  function lenke(url, klasse) {
    return el('a', { href: trygg(url), target: '_blank', rel: 'noopener', class: klasse });
  }
  var fmtDag = new Intl.DateTimeFormat('nb-NO', { timeZone: 'Europe/Oslo', year: 'numeric', month: '2-digit', day: '2-digit' });
  var fmtTid, fmtSek, fmtLang, fmtKort, fmtMnd;
  function lagFormater() {
    var l = t('_locale'), z = 'Europe/Oslo';
    fmtTid = new Intl.DateTimeFormat(l, { timeZone: z, hour: '2-digit', minute: '2-digit', hour12: false });
    fmtSek = new Intl.DateTimeFormat(l, { timeZone: z, hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: false });
    fmtLang = new Intl.DateTimeFormat(l, { timeZone: z, weekday: 'long', day: 'numeric', month: 'long' });
    fmtKort = new Intl.DateTimeFormat(l, { timeZone: z, day: 'numeric', month: 'short' });
    fmtMnd = new Intl.DateTimeFormat(l, { month: 'short', timeZone: 'UTC' });
  }
  function dagNokkel(d) { return fmtDag.format(d); }
  function naar(iso) {
    if (!iso) return '–';
    var d = new Date(iso), n = new Date();
    var igaar = new Date(n.getTime() - 86400000), imorgen = new Date(n.getTime() + 86400000);
    var k = dagNokkel(d);
    var dag = k === dagNokkel(n) ? t('i_dag') : k === dagNokkel(igaar) ? t('i_gaar') : k === dagNokkel(imorgen) ? t('i_morgen')
      : fmtKort.format(d).replace('.', '');
    return dag + ' ' + fmtTid.format(d);
  }
  function varighet(fra, til) {
    var s = Math.max(0, ((til ? new Date(til) : new Date()) - new Date(fra)) / 1000);
    if (s < 3600) return t('min', { n: Math.max(1, Math.round(s / 60)) });
    if (s < 86400) return t('timer_min', { t: Math.floor(s / 3600), m: Math.round((s % 3600) / 60) });
    var d = Math.floor(s / 86400);
    return t('dager', { n: d }) + (d < 3 ? ' ' + t('pluss_timer', { t: Math.floor((s % 86400) / 3600) }) : '');
  }
  function tom(e, tekst) { e.textContent = ''; e.appendChild(el('li', null, el('p', { class: 'tom', text: tekst }))); }

  // ------------------------------------------------------------------ tema
  function settTema(t) {
    document.documentElement.setAttribute('data-tema', t);
    try { localStorage.setItem('st_tema', t); } catch (e) {}
    document.querySelectorAll('[data-tema-valg]').forEach(function (b) {
      b.setAttribute('aria-pressed', b.getAttribute('data-tema-valg') === t ? 'true' : 'false');
    });
  }

  // ------------------------------------------------------------------ faner
  function visFane(navn, fokus) {
    var na = navn === 'na';
    $('fane-na').setAttribute('aria-selected', na ? 'true' : 'false');
    $('fane-tid').setAttribute('aria-selected', na ? 'false' : 'true');
    $('fane-na').tabIndex = na ? 0 : -1;
    $('fane-tid').tabIndex = na ? -1 : 0;
    $('panel-na').hidden = !na;
    $('panel-tid').hidden = na;
    if (fokus) (na ? $('fane-na') : $('fane-tid')).focus();
    try { sessionStorage.setItem('st_fane', navn); } catch (e) {}
    if (!na && (!tidslinje || Date.now() - tidHentet > MAKS_VENT)) hentTidslinje();
    overskrift();
  }
  function overskrift() {
    var h = $('overskrift');
    if ($('panel-tid').hidden) {
      var dato = fmtLang.format(new Date());
      h.textContent = dato.charAt(0).toUpperCase() + dato.slice(1);
    } else if (tidslinje) {
      var a = new Date(tidslinje.dager[0] + 'T12:00:00Z'), b = new Date(tidslinje.dager[tidslinje.dager.length - 1] + 'T12:00:00Z');
      h.textContent = fmtKort.format(a).replace('.', '') + ' – ' + fmtKort.format(b).replace('.', '');
    } else {
      h.textContent = t('fane_tid');
    }
  }

  // ------------------------------------------------------------------ status nå
  function hentStatus() {
    clearTimeout(tidtaker);
    return fetch('api.php?a=status', { cache: 'no-store' })
      .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
      .then(function (d) { var forrige = status; status = d; tegnStatus(forrige); planlegg(d.neste); })
      .catch(function () {
        var h = $('hentet'); h.className = 'hentet feil';
        h.textContent = t('feil_server');
        tidtaker = setTimeout(hentStatus, 60000);
      });
  }
  // Neste henting når dataene blir 5 minutter gamle, med litt tilfeldig spredning så ikke alle spør samtidig.
  function planlegg(neste) {
    var ms = new Date(neste).getTime() - Date.now() + 2000 + Math.random() * 20000;
    tidtaker = setTimeout(function () {
      if (document.hidden) return; // tas igjen når fanen blir synlig
      hentStatus();
      if (!$('panel-tid').hidden) hentTidslinje();
    }, Math.min(Math.max(ms, 30000), MAKS_VENT + 30000));
  }

  // Det du har sett, ligger i localStorage (st_sett): hendelser {id: nivå}, kilder {id: status} og når en kilde
  // byttet status. Alt som er nytt eller verre enn det du har sett, er uthevet i 15 minutter fra det dukket opp
  // på siden (sett.n for hendelser, sett.e for kilder), eller til du klikker på det eller trykker «Merk alt som sett».
  // Det som kom mens siden var lukket, uthevet i 15 minutter fra du åpner den.
  function lesSett() { try { return JSON.parse(localStorage.getItem('st_sett')); } catch (e) { return null; } }
  function lagreSett() { try { localStorage.setItem('st_sett', JSON.stringify(sett)); } catch (e) {} }
  function viktig(h) { return !(h.type === 'melding' && h.niva === 'melding'); }
  function grunnlag(d) {
    var g = { h: {}, k: {}, e: {}, n: {} };
    d.aktive.forEach(function (h) { g.h[h.id] = h.niva; });
    d.kilder.forEach(function (k) { g.k[k.id] = k.status; });
    return g;
  }
  function merkNytt(forrige, d) {
    if (!sett || !sett.h || !sett.k) { sett = grunnlag(d); lagreSett(); return []; }
    sett.e = sett.e || {};
    sett.n = sett.n || {};
    var naa = Date.now();
    var meldinger = [], kjente = {}, gamle = {}, aktiveIder = {};
    if (forrige) {
      forrige.aktive.forEach(function (h) { kjente[h.id] = h.niva; });
      forrige.kilder.forEach(function (k) { gamle[k.id] = k.status; });
    }
    d.kilder.forEach(function (k) {
      if (!(k.id in sett.k)) sett.k[k.id] = k.status;
      if (sett.k[k.id] !== k.status) {
        if (!sett.e[k.id]) sett.e[k.id] = naa;
        else if (naa - sett.e[k.id] >= UTHEV_MS) { sett.k[k.id] = k.status; delete sett.e[k.id]; }
      } else delete sett.e[k.id];
      if (forrige && gamle[k.id] && gamle[k.id] !== k.status) meldinger.push(t('kunn_status', { navn: navn(k), niva: nt(k.status).toLowerCase() }));
    });
    d.aktive.forEach(function (h) {
      aktiveIder[h.id] = true;
      if (viktig(h) && (!(h.id in sett.h) || RANG[h.niva] > RANG[sett.h[h.id]])) {
        if (!sett.n[h.id]) sett.n[h.id] = naa;
        else if (naa - sett.n[h.id] >= UTHEV_MS) { sett.h[h.id] = h.niva; delete sett.n[h.id]; }
      } else delete sett.n[h.id];
      if (forrige && viktig(h) && (!(h.id in kjente) || RANG[h.niva] > RANG[kjente[h.id]])) {
        meldinger.push(t('kunn_ny', { navn: knavn(h), tittel: h.tittel, niva: nt(h.niva) }));
      }
    });
    Object.keys(sett.h).forEach(function (id) { if (!aktiveIder[id]) delete sett.h[id]; });
    Object.keys(sett.n).forEach(function (id) { if (!aktiveIder[id]) delete sett.n[id]; });
    planleggUtlop();
    lagreSett();
    if (!forrige) {
      var n = antallUthevet(d);
      if (n) meldinger.push(t('kunn_siden_sist', { n: n }));
    }
    return meldinger;
  }
  // Tegn på nytt når den første uthevingen er 15 minutter gammel, så den forsvinner av seg selv.
  function planleggUtlop() {
    clearTimeout(utlopTimer);
    var tider = Object.keys(sett.n).map(function (k) { return sett.n[k]; }).concat(Object.keys(sett.e).map(function (k) { return sett.e[k]; }));
    if (!tider.length) return;
    var ms = Math.min.apply(null, tider) + UTHEV_MS - Date.now() + 500;
    utlopTimer = setTimeout(function () { tegnStatus(status); }, Math.max(ms, 1000));
  }
  function erNy(id) {
    var h = status.aktive.filter(function (x) { return x.id === id; })[0];
    return !!h && viktig(h) && (!(id in sett.h) || RANG[h.niva] > RANG[sett.h[id]]);
  }
  function endring(id) {
    var k = status.kilder.filter(function (x) { return x.id === id; })[0];
    return k && sett.k[id] && sett.k[id] !== k.status ? { fra: sett.k[id], tid: sett.e[id] || Date.now() } : null;
  }
  function antallUthevet(d) {
    return d.aktive.filter(function (h) { return erNy(h.id); }).length + d.kilder.filter(function (k) { return endring(k.id); }).length;
  }
  function merkSett(kilde, hendelse) {
    if (kilde === '*') { sett = grunnlag(status); }
    else {
      if (kilde) {
        var k = status.kilder.filter(function (x) { return x.id === kilde; })[0];
        if (k) { sett.k[kilde] = k.status; delete sett.e[kilde]; }
        status.aktive.forEach(function (h) { if (h.kilde === kilde) { sett.h[h.id] = h.niva; delete sett.n[h.id]; } });
      }
      if (hendelse) {
        var h = status.aktive.filter(function (x) { return x.id === hendelse; })[0];
        if (h) { sett.h[h.id] = h.niva; delete sett.n[h.id]; }
      }
    }
    lagreSett();
    setTimeout(function () { tegnStatus(status); }, 0);
  }

  function tegnStatus(forrige) {
    var d = status;
    var meldinger = merkNytt(forrige, d);
    if (meldinger.length) $('kunngjoring').textContent = meldinger.join('. ') + '.';
    var uthevet = antallUthevet(d);
    document.title = (uthevet ? '(' + uthevet + ') ' : '') + tittel;
    var teller = {};
    d.kilder.forEach(function (k) { teller[k.status] = (teller[k.status] || 0) + 1; });

    var opp = $('oppsummering');
    opp.textContent = '';
    var feil = (teller.nede || 0) + (teller.delvis || 0);
    if (feil) opp.appendChild(chip(teller.nede ? 'nede' : 'delvis', t('chip_feil', { n: feil })));
    ['redusert', 'melding', 'vedlikehold'].forEach(function (n) { if (teller[n]) opp.appendChild(chip(n, t('chip_' + n, { n: teller[n] }))); });
    opp.appendChild(chip('ok', t('chip_ok', { n: teller.ok || 0 })));
    if (teller.ukjent) opp.appendChild(chip('ukjent', t('chip_ukjent', { n: teller.ukjent })));
    if (d.kommende.length) opp.appendChild(chip('vedlikehold', t('chip_planlagt', { n: d.kommende.length })));
    if (uthevet) opp.appendChild(el('button', { type: 'button', class: 'sett-knapp', onclick: function () { merkSett('*'); } }, t('merk_sett', { n: uthevet })));
    opp.appendChild(el('span', { class: 'hentet', id: 'hentet', text: t('hentet', { tid: d.hentet ? fmtSek.format(new Date(d.hentet)) : '–' }) }));

    var kort = $('kort');
    kort.textContent = '';
    d.kilder.forEach(function (k) {
      var e = endring(k.id);
      var a = lenke(k.side, 'n-' + k.status + (e ? ' endret' : ''));
      a.setAttribute('aria-label', t('kort_aria', { navn: navn(k), niva: nt(k.status) }));
      if (e) a.addEventListener('click', function () { merkSett(k.id, null); });
      var fot = k.status === 'ukjent' ? t(k.feil ? 'fot_feil' : 'fot_ikke')
        : k.antall ? t(k.id === 'azure' ? 'fot_regioner' : 'fot_komponenter', { n: k.antall }) : t('fot_hentet', { tid: k.hentet ? fmtTid.format(new Date(k.hentet)) : '–' });
      a.appendChild(el('div', { class: 'hode' }, el('h2', { text: navn(k) }), ut()));
      a.appendChild(el('div', { class: 'status' }, ikon(k.status), el('span', { text: nt(k.status) })));
      if (e) {
        var bedre = RANG[k.status] < RANG[e.fra];
        a.appendChild(el('span', { class: 'byttet n-' + e.fra },
          el('span', { class: 'ny-merke', text: t(bedre ? 'bedre' : 'endret') }),
          t('byttet_fra', { niva: nt(e.fra).toLowerCase(), tid: fmtTid.format(new Date(e.tid)) })));
      }
      a.appendChild(el('p', null, beskrivelse(k)));
      a.appendChild(el('span', { class: 'fot', text: fot }));
      kort.appendChild(a);
    });

    // «Pågår nå» står som en egen rute i rutenettet, etter siste kort (se plasserPagar()).
    var rute = el('section', { class: 'pagar', 'aria-labelledby': 'pagarTittel' });
    var viktigeAntall = d.aktive.filter(function (h) { return !(h.type === 'melding' && h.niva === 'melding'); }).length;
    rute.appendChild(el('h2', { class: 'liten', id: 'pagarTittel', text: t('pagar_tittel') + (viktigeAntall ? ' (' + viktigeAntall + ')' : '') }));
    var aktive = el('ul', { class: 'liste', id: 'aktive' });
    rute.appendChild(aktive);
    kort.appendChild(rute);
    var viktige = d.aktive.filter(function (h) { return !(h.type === 'melding' && h.niva === 'melding'); });
    if (!viktige.length) tom(aktive, t('ingen_pagar'));
    viktige.forEach(function (h) { aktive.appendChild(rad(h, true)); });

    var kom = $('kommende');
    kom.textContent = '';
    if (!d.kommende.length) tom(kom, t('ingen_planlagt'));
    d.kommende.forEach(function (h) { kom.appendChild(rad(h, false)); });
    overskrift();
    plasserPagar();
  }
  // Ruten fyller resten av raden etter siste kort. Er det mindre enn to kolonner igjen, får den en hel rad.
  function plasserPagar() {
    var grid = $('kort'), rute = grid.querySelector('.pagar');
    if (!rute) return;
    var kol = getComputedStyle(grid).gridTemplateColumns.split(' ').filter(Boolean).length || 1;
    var kort = grid.querySelectorAll(':scope > a');
    var rest = kol - (kort.length % kol || kol);
    var span = rest >= 2 ? rest : kol;
    rute.style.gridColumn = 'span ' + span;
    kort.forEach(function (a) { a.classList.remove('ved-pagar'); });
    if (span < kol) {
      for (var i = kort.length - (kol - span); i < kort.length; i++) kort[i].classList.add('ved-pagar');
    }
    rute.classList.toggle('smal', span < 3 && kol >= 3 || kol < 2);
  }
  window.addEventListener('resize', function () { plasserPagar(); });
  function ut() { var s = ikon('ut'); s.setAttribute('class', 'ut'); return s; }
  function chip(niva, tekst) { return el('span', { class: 'chip n-' + niva }, ikon(niva), tekst); }
  function beskrivelse(k) {
    if (k.status === 'ukjent') return k.feil ? t('besk_feil', { feil: k.feil }) : t('besk_venter');
    var hendelser = (status.aktive || []).filter(function (h) { return h.kilde === k.id && h.type !== 'vedlikehold' && !(h.type === 'melding' && h.niva === 'melding'); });
    if (hendelser.length) {
      hendelser.sort(function (a, b) { return RANG[b.niva] - RANG[a.niva]; });
      var sp = el('span', null, kildetekst(hendelser[0].tittel));
      if (hendelser.length > 1) sp.appendChild(document.createTextNode(t('besk_flere', { n: hendelser.length - 1 })));
      return sp;
    }
    if (k.komponenter && k.komponenter.length) return kildetekst(k.komponenter.map(function (c) { return c.navn; }).slice(0, 3).join(', '));
    // Bare eldre eller informative driftsmeldinger: vis den nyeste med dato.
    var meldinger = (status.aktive || []).filter(function (h) { return h.kilde === k.id && h.type === 'melding'; })
      .sort(function (a, b) { return a.start < b.start ? 1 : -1; });
    if (meldinger.length) {
      var m = t('besk_siste_melding', { dato: fmtKort.format(new Date(meldinger[0].start)).replace('.', ''), tittel: '\u0000' }).split('\u0000');
      return el('span', null, m[0], kildetekst(meldinger[0].tittel), m[1] || '');
    }
    var neste = (status.kommende || []).filter(function (h) { return h.kilde === k.id; })[0];
    if (neste) {
      var b = t('besk_neste', { tid: naar(neste.start), tittel: '\u0000' }).split('\u0000');
      return el('span', null, b[0], kildetekst(neste.tittel), b[1] || '');
    }
    return oppsummering(k.oppsummering);
  }
  function oppsummering(o) {
    if (!o) return '';
    if (typeof o === 'string') return o;
    return t('ops.' + o[0], { n: o[1], m: o[2] });
  }
  function rad(h, medVarighet) {
    var ny = medVarighet && erNy(h.id);
    var a = lenke(h.url, 'n-' + h.niva + (ny ? ' ny' : ''));
    var tid = medVarighet ? naar(h.start) : naar(h.start) + (h.planlagt_til ? '–' + fmtTid.format(new Date(h.planlagt_til)) : '');
    a.appendChild(el('span', { class: 'tid', text: tid }));
    a.appendChild(el('span', { class: 'niva' }, ikon(h.niva), nt(h.niva)));
    a.appendChild(el('span', null, ny ? el('span', { class: 'ny-merke', text: t('ny') }) : null, el('strong', { text: knavn(h) }), ' – ', kildetekst(h.tittel)));
    a.appendChild(el('span', { class: 'fase', text: medVarighet ? varighet(h.start) : fase(h.status) }));
    if (ny) a.addEventListener('click', function () { merkSett(null, h.id); });
    return el('li', null, a);
  }

  // ------------------------------------------------------------------ tidslinje
  function hentTidslinje() {
    return fetch('api.php?a=tidslinje', { cache: 'no-store' })
      .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
      .then(function (d) { tidslinje = d; tidHentet = Date.now(); tegnTidslinje(); overskrift(); })
      .catch(function () {
        var r = $('kalenderramme'); r.textContent = '';
        r.appendChild(el('p', { class: 'tom luft', text: t('tl_feil') }));
      });
  }
  function tegnTidslinje() {
    var d = tidslinje, ramme = $('kalenderramme');
    var gammelScroll = ramme.querySelector('table') ? ramme.scrollLeft : null;
    ramme.textContent = '';
    var idagI = d.dager.indexOf(d.idag);
    var tabell = el('table', { class: 'kalender' });
    tabell.appendChild(el('caption', { class: 'skjult', text: t('tl_tittel') }));
    var hoderad = el('tr', null, el('th', { class: 'navn', scope: 'col', text: t('leverandor') }));
    d.dager.forEach(function (dag, i) {
      var dt = new Date(dag + 'T12:00:00Z');
      var forst = i === 0 || dag.slice(8) === '01';
      var th = el('th', { scope: 'col', class: forst ? 'mnd' : null, title: dag });
      th.textContent = i === idagI ? t('i_dag') : forst ? fmtMnd.format(dt).replace('.', '') : '';
      hoderad.appendChild(th);
    });
    tabell.appendChild(el('thead', null, hoderad));
    var kropp = el('tbody');
    d.rader.forEach(function (r) {
      var a = lenke(r.side);
      a.appendChild(el('span', { text: navn(r) }));
      a.appendChild(el('small', { text: r.feil_dager ? t('feil_dager', { n: r.feil_dager }) : t('ingen_feil_90') }));
      var tr = el('tr', null, el('th', { class: 'navn', scope: 'row' }, a));
      r.celler.forEach(function (c, i) {
        var niva = c ? c.niva : 'ok';
        var td = el('td', { class: i === idagI ? 'idag' : i > idagI ? 'framtid' : null });
        var dato = new Date(d.dager[i] + 'T12:00:00Z');
        var etikett = navn(r) + ', ' + fmtKort.format(dato) + ': ' + (c ? nt(niva) + ' (' + c.ider.length + ')' : t('ingen_hendelser'));
        var b = el('button', { type: 'button', class: 'n-' + niva + (c && niva !== 'ok' ? ' harnoe' : ''), 'aria-label': etikett, title: etikett, 'aria-pressed': 'false' });
        b.addEventListener('click', function () { visDag(r, i, b); });
        td.appendChild(b);
        tr.appendChild(td);
      });
      kropp.appendChild(tr);
    });
    tabell.appendChild(kropp);
    ramme.appendChild(tabell);
    if (gammelScroll !== null) ramme.scrollLeft = gammelScroll;
    else {
      var celle = tabell.querySelector('thead th:nth-child(' + (idagI + 2) + ')');
      if (celle) ramme.scrollLeft = Math.max(0, celle.offsetLeft - ramme.clientWidth * 0.7);
    }
    if (valgtCelle) {
      var rr = d.rader.filter(function (x) { return x.id === valgtCelle.kilde; })[0];
      if (rr) visDag(rr, valgtCelle.i, kropp.children[d.rader.indexOf(rr)].children[valgtCelle.i + 1].firstChild);
    }
  }
  function visDag(r, i, knapp) {
    document.querySelectorAll('.kalender button[aria-pressed="true"]').forEach(function (b) { b.setAttribute('aria-pressed', 'false'); });
    knapp.setAttribute('aria-pressed', 'true');
    valgtCelle = { kilde: r.id, i: i };
    var c = r.celler[i], boks = $('dagdetaljer');
    boks.hidden = false;
    boks.textContent = '';
    var dato = new Date(tidslinje.dager[i] + 'T12:00:00Z');
    boks.appendChild(el('h3', { text: navn(r) + ' – ' + fmtLang.format(dato) }));
    var ul = el('ul', { class: 'liste' });
    if (!c || !c.ider.length) {
      tom(ul, t('ingen_hendelser_dag'));
    } else {
      c.ider.map(function (id) { return tidslinje.hendelser[id]; }).filter(Boolean).forEach(function (h) {
        var a = lenke(h.url, 'n-' + h.niva);
        var slutt = h.slutt ? naar(h.slutt) : h.status === 'pagar' ? t('pagar_liten') : h.planlagt_til ? naar(h.planlagt_til) : '';
        a.appendChild(el('span', { class: 'tid', text: naar(h.start) }));
        a.appendChild(el('span', { class: 'niva' }, ikon(h.niva), nt(h.niva)));
        a.appendChild(el('span', null, kildetekst(h.tittel)));
        var tilTekst = slutt && h.status !== 'pagar' ? t('til', { tid: sprak === 'nb' ? slutt.charAt(0).toLowerCase() + slutt.slice(1) : slutt }) : '';
        a.appendChild(el('span', { class: 'fase', text: fase(h.status) + tilTekst + (h.slutt ? ' · ' + varighet(h.start, h.slutt) : '') }));
        ul.appendChild(el('li', null, a));
      });
    }
    boks.appendChild(ul);
    boks.appendChild(el('p', { class: 'notis' }, t('alt_om', { navn: navn(r) }), (function () { var a = lenke(r.side); a.textContent = r.side.replace(/^https:\/\//, '').replace(/\/$/, ''); return a; })()));
  }

  // ------------------------------------------------------------------ språk
  // Standardspråket kommer fra cf/config.php (data-sprak på <html>); er språkvalget på, huskes valget i st_sprak.
  function settSprak(s, tegn) {
    sprak = TEKSTER[s] ? s : 'nb';
    document.documentElement.lang = sprak;
    lagFormater();
    document.querySelectorAll('[data-t]').forEach(function (e) { e.textContent = t(e.getAttribute('data-t'), { n: +e.getAttribute('data-n') || 0, v: e.getAttribute('data-v') }); });
    document.querySelectorAll('[data-t-aria]').forEach(function (e) { e.setAttribute('aria-label', t(e.getAttribute('data-t-aria'))); });
    document.querySelectorAll('[data-t-title]').forEach(function (e) { e.setAttribute('title', t(e.getAttribute('data-t-title'))); });
    var k = $('sprakKnapp');
    if (k) k.setAttribute('data-til', sprak === 'nb' ? 'en' : 'nb');
    if (tegn) {
      if (status) tegnStatus(status);
      if (tidslinje) { tegnTidslinje(); }
      overskrift();
    }
  }

  // ------------------------------------------------------------------ oppstart
  document.addEventListener('DOMContentLoaded', function () {
    var valgt = null, k = $('sprakKnapp');
    if (k) { try { valgt = localStorage.getItem('st_sprak'); } catch (e) {} }
    settSprak(valgt || document.documentElement.getAttribute('data-sprak') || 'nb', false);
    if (k) k.addEventListener('click', function () {
      var ny = sprak === 'nb' ? 'en' : 'nb';
      try { localStorage.setItem('st_sprak', ny); } catch (e) {}
      settSprak(ny, true);
    });
    settTema(document.documentElement.getAttribute('data-tema'));
    document.querySelectorAll('[data-tema-valg]').forEach(function (b) {
      b.addEventListener('click', function () { settTema(b.getAttribute('data-tema-valg')); });
    });
    $('fane-na').addEventListener('click', function () { visFane('na'); });
    $('fane-tid').addEventListener('click', function () { visFane('tid'); });
    document.querySelector('.faner').addEventListener('keydown', function (e) {
      if (e.key === 'ArrowLeft' || e.key === 'ArrowRight') { e.preventDefault(); visFane($('panel-na').hidden ? 'na' : 'tid', true); }
    });
    var fane = 'na';
    try { fane = sessionStorage.getItem('st_fane') || 'na'; } catch (e) {}
    visFane(fane === 'tid' ? 'tid' : 'na');
    hentStatus();
    document.addEventListener('visibilitychange', function () {
      if (document.hidden || !status) return;
      if (Date.now() - new Date(status.neste).getTime() > 0) {
        hentStatus();
        if (!$('panel-tid').hidden) hentTidslinje();
      }
    });
  });
})();
