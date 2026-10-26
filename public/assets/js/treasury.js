/* Plano de tesouraria: interface (pt-PT). Todo o cálculo é feito no servidor (/api/treasury/*);
 * este ficheiro só pede dados com fetch e desenha. Não contém dados; não usa innerHTML com dados. */
(function () {
  'use strict';

  // ------------------------------------------------------------------ utilitários
  function $(id) { return document.getElementById(id); }
  function add(n, c) {
    if (c === null || c === undefined || c === false) return;
    if (Array.isArray(c)) { c.forEach(function (x) { add(n, x); }); return; }
    n.appendChild(c.nodeType ? c : document.createTextNode(String(c)));
  }
  function h(tag, attrs) {
    var n = document.createElement(tag);
    attrs = attrs || {};
    Object.keys(attrs).forEach(function (k) {
      var v = attrs[k];
      if (v === null || v === undefined || v === false) return;
      if (k === 'class') n.className = v;
      else if (k === 'text') n.textContent = v;
      else if (k.slice(0, 2) === 'on') n.addEventListener(k.slice(2), v);
      else if (k === 'value' || k === 'checked' || k === 'disabled' || k === 'selected') n[k] = v;
      else n.setAttribute(k, v === true ? '' : v);
    });
    for (var i = 2; i < arguments.length; i++) add(n, arguments[i]);
    return n;
  }
  function svg(tag, attrs) {
    var n = document.createElementNS('http://www.w3.org/2000/svg', tag);
    Object.keys(attrs || {}).forEach(function (k) { if (k === 'text') n.textContent = attrs[k]; else n.setAttribute(k, attrs[k]); });
    for (var i = 2; i < arguments.length; i++) add(n, arguments[i]);
    return n;
  }
  function clear(n) { while (n.firstChild) n.removeChild(n.firstChild); return n; }

  var nf = new Intl.NumberFormat('pt-PT', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  var nk = new Intl.NumberFormat('pt-PT', { maximumFractionDigits: 0 });
  function eur(n) { return nf.format(Math.abs(n) < 0.005 ? 0 : n) + ' €'; }
  function eurK(n) { return nk.format(n / 1000) + ' k€'; }
  function fmtIn(n) { return (Math.round(n * 100) / 100).toFixed(2).replace('.', ','); }
  function fmtD(iso) { return iso && iso.length === 10 ? iso.slice(8, 10) + '/' + iso.slice(5, 7) + '/' + iso.slice(0, 4) : (iso || ''); }
  function dm(iso) { return iso.slice(8, 10) + '/' + iso.slice(5, 7); }
  function sum(a) { var s = 0; a.forEach(function (v) { s += v; }); return s; }

  var SEM = '__sem';
  var TOTAL = '__total';

  function req(method, url, body, isForm) {
    var o = { method: method, headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin', cache: 'no-store' };
    if (body !== undefined && !isForm) { o.headers['Content-Type'] = 'application/json'; o.body = JSON.stringify(body); }
    if (isForm) o.body = body;
    return fetch(url, o).then(function (r) {
      if (r.status === 204) return null;
      return r.json().catch(function () { return null; }).then(function (d) {
        if (!r.ok) {
          var e = new Error((d && d.error && d.error.message) || ('Erro HTTP ' + r.status));
          e.status = r.status; e.details = d && d.error && d.error.details;
          throw e;
        }
        return d;
      });
    });
  }
  function qs(o) {
    return Object.keys(o).map(function (k) { return encodeURIComponent(k) + '=' + encodeURIComponent(o[k]); }).join('&');
  }
  function errText(e) {
    var t = e.message || 'Erro.';
    if (e.details) t += ' ' + Object.keys(e.details).map(function (k) { return e.details[k]; }).join(' ');
    return t;
  }
  function msg(container, text, ok) {
    var m = h('div', { class: ok ? 'tr-ok tr-note' : 'tr-err' , text: text });
    container.appendChild(m);
    setTimeout(function () { if (m.parentNode) m.parentNode.removeChild(m); }, 8000);
  }

  // ------------------------------------------------------------------ estado da página
  var banksSig = null;
  var st = null;      // /state
  var plan = null;    // /plan
  var ui = {
    planBank: '', planPeriod: '-1', planPage: 0, showRec: true,
    medida: 'Saldo final', chartBank: TOTAL,
    filt: { R: { bank: '', prazo: '', q: '', page: 0 }, P: { bank: '', prazo: '', q: '', page: 0 } },
    edit: { R: null, P: null },
    ent: { side: 'P', q: '', only: false, page: 0, bulk: '' },
    ioKind: 'documents', sugMsg: '', ioMsg: '', srcMsg: '', recDraft: null, recMsg: ''
  };

  function bankName(id) { var b = st.banks.filter(function (x) { return x.id === id; })[0]; return b ? (b.nome || '(sem nome)') : ''; }
  function bankOptions(first) {
    var o = first ? first.slice() : [];
    st.banks.forEach(function (b) { o.push({ value: b.id, label: b.nome || '(sem nome)' }); });
    return o;
  }
  function select(opts, value, onchange, cls) {
    var s = h('select', { class: 'form-control ' + (cls || ''), onchange: function () { onchange(s.value); } });
    opts.forEach(function (o) { s.appendChild(h('option', { value: o.value, text: o.label })); });
    s.value = value;
    if (s.value !== value && opts.length) s.value = opts[0].value;
    return s;
  }
  function field(label, control) { return h('div', { class: 'form-group' }, h('label', { text: label }), control); }
  function check(label, value, onchange) {
    var i = h('input', { type: 'checkbox', checked: !!value, onchange: function () { onchange(i.checked); } });
    return h('div', { class: 'checkbox' }, h('label', {}, i, ' ' + label));
  }
  function segmented(options, value, onchange) {
    var g = h('div', { class: 'btn-group', role: 'group' });
    options.forEach(function (o) {
      g.appendChild(h('button', { type: 'button', class: 'btn btn-default btn-sm' + (o.value === value ? ' active' : ''), onclick: function () { onchange(o.value); }, text: o.label }));
    });
    return g;
  }
  function btn(label, cls, onclick, disabled) {
    return h('button', { type: 'button', class: 'btn btn-sm ' + (cls || 'btn-default'), onclick: onclick, disabled: !!disabled, text: label });
  }
  function badge(tone, text) { return h('span', { class: 'label label-' + tone, text: text }); }
  function estadoBadge(e) {
    return e === 'falta' ? badge('danger', 'Falta de fundos') : e === 'ccc' ? badge('warning', 'Recorre à CCC') : badge('success', 'Coberto');
  }
  function table(cols, rows, align, rowClasses) {
    var thead = h('thead', {}, h('tr', {}, cols.map(function (c, i) { return h('th', { class: align && align[i] === 'end' ? 'num' : '', text: c }); })));
    var tbody = h('tbody', {}, rows.map(function (r, ri) {
      return h('tr', { class: (rowClasses && rowClasses[ri]) || '' }, r.map(function (c, i) { return h('td', { class: align && align[i] === 'end' ? 'num' : '' }, c); }));
    }));
    return h('div', { class: 'table-responsive' }, h('table', { class: 'table table-condensed table-bordered tr-table' }, thead, tbody));
  }
  function pager(page, pages, total, set) {
    return h('div', { class: 'tr-pager' },
      btn('Anterior', 'btn-default', function () { set(Math.max(0, page - 1)); }, page <= 0),
      h('span', { class: 'text-muted', text: 'Página ' + (page + 1) + ' de ' + pages + ' · ' + total + ' linhas' }),
      btn('Seguinte', 'btn-default', function () { set(Math.min(pages - 1, page + 1)); }, page >= pages - 1));
  }

  // ------------------------------------------------------------------ menu lateral
  function loadMenu() {
    fetch('/api/menu', { headers: { Accept: 'application/json' } }).then(function (r) { return r.json(); }).then(function (items) {
      var ul = $('sidebar-menu'); clear(ul);
      items.forEach(function (it) {
        var active = (it.children || []).some(function (c) { return c.url === '/treasury'; });
        var sub = h('ul', { class: 'treeview-menu' });
        (it.children || []).forEach(function (c) {
          var u = typeof c.url === 'string' && /^(\/|#)/.test(c.url) ? c.url : '#';
          sub.appendChild(h('li', { class: c.url === '/treasury' ? 'active' : '' }, h('a', { href: u }, h('i', { class: 'fa fa-angle-right' }), ' ' + c.label)));
        });
        ul.appendChild(h('li', { class: 'treeview' + (active ? ' active menu-open' : '') },
          h('a', { href: '#' }, h('i', { class: 'fa ' + String(it.icon || 'fa-circle-o').replace(/[^\w-]/g, '') }), h('span', { text: it.label }),
            h('span', { class: 'pull-right-container' }, h('i', { class: 'fa fa-angle-left pull-right' }))), sub));
        if (active) sub.style.display = 'block';
      });
    }).catch(function () { /* menu opcional */ });
  }

  // ------------------------------------------------------------------ carregamento
  function loadCore() {
    return Promise.all([req('GET', '/api/treasury/state'), req('GET', '/api/treasury/plan')]).then(function (r) { st = r[0]; plan = r[1]; });
  }
  function fail(e, where) {
    var t = e.status === 401 ? 'Sessão expirada ou sem autorização: recarregue a página e inicie sessão.' : errText(e);
    var box = where || $('tr-banner');
    clear(box);
    box.appendChild(h('div', { class: 'callout callout-danger' }, h('p', { text: t })));
  }

  /** keep: secções a não redesenhar (para não perder o foco dos campos em edição) */
  function refreshAll(keep) {
    keep = keep || {};
    return loadCore().then(function () {
      renderBanner();
      renderDashboard();
      renderPlan();
      if (!keep.saldos) renderSaldos(); else { renderOvText(); }
      renderSugestoes();
      renderEntities();
      renderDuplos();
      if (!keep.rec) renderRecorrentes();
      renderProjection();
      var sig = JSON.stringify(st.banks.map(function (b) { return [b.id, b.nome]; }));
      if (sig !== banksSig) { banksSig = sig; initDocs('R'); initDocs('P'); }
      loadDocs('R'); loadDocs('P');
      renderIo();
      renderSage();
      renderOrigem();
    }).catch(function (e) { fail(e); });
  }

  // ------------------------------------------------------------------ faixa
  function renderBanner() {
    var b = clear($('tr-banner'));
    if (st.demo) {
      b.appendChild(h('div', { class: 'callout callout-info tr-demo' },
        h('h4', { text: 'Modo demonstração' }),
        h('p', { text: 'Estão a ser usados dados fictícios. Coloque o ficheiro Sage X3 em data/private/sage-open-items.xls (ou use "Ficheiro Sage X3" mais abaixo) para ver os dados reais.' })));
    }
    b.appendChild(h('p', { class: 'tr-note' },
      h('i', { class: 'fa fa-lock' }), ' Dados confidenciais. Fonte: ficheiro Sage X3 de ' + fmtD(st.ref) + (st.sourceName ? ' (' + st.sourceName + ')' : '') + ' · início do plano ' + fmtD(plan.start) + '.'));
  }

  // ------------------------------------------------------------------ plano de pagamentos por banco
  function renderPlan() {
    var box = clear($('tr-plano'));
    var res = plan.banks;
    var s = st.settings;
    var shortBanks = res.filter(function (r) { return r.need > 0 && r.id !== SEM; });
    var sem = res.filter(function (r) { return r.id === SEM; })[0];
    if (shortBanks.length) {
      box.appendChild(h('div', { class: 'callout callout-warning' }, h('h4', { text: 'Falta de fundos no horizonte.' }),
        h('p', { text: shortBanks.map(function (r) { return r.nome + ': primeira falta a ' + (r.first ? dm(r.first) : '—') + ', necessidade máxima ' + eur(r.need); }).join(' · ') +
          (sem ? '. Sem banco atribuído: ' + (sem.pagT > 0.5 ? eur(sem.pagT) + ' de pagamentos (modo ' + s.modoP.toLowerCase() + ': escolha Sugerido, um banco de pagamento por defeito ou um banco por fornecedor) e ' : '') + eur(sem.recT) + ' de recebimentos de clientes sem banco no ficheiro.' : '.') })));
    } else {
      box.appendChild(h('div', { class: 'callout callout-success' }, h('h4', { text: 'Todos os bancos cobrem os pagamentos no horizonte.' }),
        h('p', { text: 'Recebimentos de ' + eur(plan.recTot) + ' e pagamentos de ' + eur(plan.pagTot) + ' no período.' })));
    }
    // banco dos pagamentos a fornecedores: Sugerido / Fixo / Manual
    var ctl = [field('Banco dos pagamentos a fornecedores', select([{ value: 'Sugerido', label: 'Sugerido (automático)' }, { value: 'Fixo', label: 'Fixo (banco por defeito)' }, { value: 'Manual', label: 'Manual' }], s.modoP, function (v) { ui.planPage = 0; saveSetting({ modoP: v }); }))];
    if (s.modoP === 'Sugerido') {
      ctl.push(field('Critério da sugestão', select([{ value: 'Maior folga', label: 'Maior folga depois do pagamento (por defeito)' }, { value: 'Ordem de prioridade', label: 'Ordem de prioridade dos bancos' }, { value: 'Minimizar uso de CCC', label: 'Minimizar o uso de CCC' }, { value: 'Minimizar transferências', label: 'Minimizar transferências (ajuste justo)' }], s.crit, function (v) { ui.planPage = 0; saveSetting({ crit: v }); })));
    }
    ctl.push(btn('Aceitar sugestão (por fornecedor)', 'btn-default', function () { acceptSug('fornecedor'); }, plan.sugerido.n === 0));
    ctl.push(btn('Aceitar sugestão (por documento)', 'btn-default', function () { acceptSug('documento'); }, plan.sugerido.n === 0));
    box.appendChild(h('div', { class: 'tr-controls' }, ctl));
    if (s.modoP === 'Sugerido' && s.crit === 'Ordem de prioridade') {
      var ids = plan.prio.map(function (x) { return x.id; });
      var move = function (i, d) {
        var a = ids.slice(), j = i + d;
        if (j < 0 || j >= a.length) return;
        var t = a[i]; a[i] = a[j]; a[j] = t;
        req('PUT', '/api/treasury/priority', { order: a }).then(function () { return refreshAll(); }).catch(function (e) { msg(box, errText(e)); });
      };
      box.appendChild(table(['Prioridade', 'Banco', 'Ordem'], plan.prio.map(function (x, i) {
        return [String(i + 1), x.nome, h('div', { class: 'tr-actions' }, btn('Subir', 'btn-default', function () { move(i, -1); }, i === 0), btn('Descer', 'btn-default', function () { move(i, 1); }, i === plan.prio.length - 1))];
      })));
    }
    var vis = res.filter(function (r) { return r.visivel; });
    var sg = plan.sugerido;
    var modoText = s.modoP === 'Sugerido'
      ? (sg.n > 0
        ? 'Banco sugerido para ' + sg.n + ' pagamentos (' + eur(sg.v) + '), pelo critério ' + s.crit.toLowerCase() + ': ' + vis.filter(function (r) { return r.sugN > 0; }).map(function (r) { return r.nome + ' ' + eur(r.sugT) + ' (' + r.sugN + ')'; }).join(' · ') + '. ' +
          (sg.badN > 0 ? sg.badN + ' pagamentos (' + eur(sg.badV) + ') não cabem em nenhum banco: ficam no banco com menos falta e precisam de transferência ou CCC. ' : 'Todos cabem num banco. ') +
          'Um banco atribuído a um documento ou a um fornecedor, ou o banco de pagamento por defeito, prevalece sobre a sugestão.'
        : 'Sem pagamentos a sugerir: todos têm banco atribuído.')
      : s.modoP === 'Fixo'
        ? 'Os pagamentos sem banco próprio saem do banco de pagamento por defeito' + (s.defBankP && bankName(s.defBankP) ? ': ' + bankName(s.defBankP) + '.' : '. Escolha-o nos parâmetros: por agora ficam em Sem banco atribuído.')
        : 'Só contam os bancos atribuídos a documentos ou a fornecedores; os restantes pagamentos ficam em Sem banco atribuído.';
    box.appendChild(h('p', { class: 'tr-note', text: modoText }));
    box.appendChild(h('div', { id: 'tr-sugmsg' }));
    if (ui.sugMsg) box.appendChild(h('p', { class: 'tr-note', text: ui.sugMsg }));
    box.appendChild(table(['Banco', 'Saldo inicial', 'Recebimentos', 'Pagamentos', 'Dos quais sugeridos', 'Saldo final', 'Saldo mínimo', '1.ª falta', 'Necessidade máx.', 'Estado'],
      vis.map(function (r) {
        return [r.nome, eur(r.saldo), eur(r.recT), eur(r.pagT), r.sugN > 0 ? r.sugN + ' · ' + eur(r.sugT) : '—', eur(r.final), eur(r.min), r.first ? dm(r.first) : '—', r.need > 0 ? eur(r.need) : '—', estadoBadge(r.estado)];
      }), ['start', 'end', 'end', 'end', 'end', 'end', 'end', 'end', 'end', 'start']));

    if (!ui.planBank) ui.planBank = plan.defaultBank;
    if (!res.some(function (r) { return r.id === ui.planBank; })) ui.planBank = plan.defaultBank;
    var periodOpts = [{ value: '-1', label: 'Todos' }].concat(plan.periods.map(function (p, i) { return { value: String(i), label: p.label }; }));
    if (!periodOpts.some(function (o) { return o.value === ui.planPeriod; })) ui.planPeriod = '-1';
    box.appendChild(h('div', { class: 'tr-controls' },
      field('Banco em detalhe', select(res.map(function (r) { return { value: r.id, label: r.nome }; }), ui.planBank, function (v) { ui.planBank = v; ui.planPage = 0; loadPlanDetail(); })),
      field('Período', select(periodOpts, ui.planPeriod, function (v) { ui.planPeriod = v; ui.planPage = 0; loadPlanDetail(); })),
      check('Mostrar recebimentos', ui.showRec, function (v) { ui.showRec = v; ui.planPage = 0; loadPlanDetail(); })));
    box.appendChild(h('div', { id: 'tr-plan-detail' }));
    loadPlanDetail();
  }

  function acceptSug(mode) {
    if (mode === 'fornecedor' && !window.confirm('Aceitar a sugestão por fornecedor? Cada fornecedor fica com um só banco (atribuição manual) e perde a precisão por documento.')) return;
    req('POST', '/api/treasury/suggestions/accept', { mode: mode }).then(function (r) { ui.sugMsg = r.message; return refreshAll(); }).catch(function (e) { msg($('tr-sugmsg'), errText(e)); });
  }

  function loadPlanDetail() {
    var box = $('tr-plan-detail');
    if (!box) return;
    req('GET', '/api/treasury/plan/detail?' + qs({ bank: ui.planBank, period: ui.planPeriod, rec: ui.showRec ? 1 : 0, page: ui.planPage })).then(function (d) {
      clear(box);
      if (!d.bank) return;
      if (d.rows.length) {
        var classes = d.rows.map(function (r) { return r.k === 'tot' ? 'tr-total' : ''; });
        var rows = d.rows.map(function (r) {
          if (r.k === 'tot') {
            return ['Total ' + r.label, '', '', '', '', eur(r.pag), eur(r.rec), eur(r.saldo),
              r.estado === 'neg' ? badge('danger', 'Saldo negativo') : r.estado === 'ccc' ? badge('warning', 'Usa CCC') : badge('success', 'OK'), ''];
          }
          var origem = r.sug ? h('em', { text: 'Sugerido: ' + (r.sug.ok ? 'folga de ' + eur(r.sug.head) + ' depois do pagamento' : 'nenhum banco cobre; faltam ' + eur(-r.sug.head) + ' (transferência ou CCC)') })
            : r.tipo === 'P' ? (r.origemBanco === 'documento' ? 'Banco do documento' : r.origemBanco === 'fornecedor' ? 'Banco do fornecedor' : 'Banco por defeito') : '';
          return [fmtD(r.date) + (r.atraso ? ' · vencido' : '') + (r.alterada ? ' · data alterada' : ''), r.semanaIso,
            (r.rec ? '↻ ' : '') + (r.tipo === 'R' ? 'Rec. ' : 'Pag. ') + r.tdoc, r.doc || '—', r.sug ? h('em', { text: r.parte || '—' }) : (r.parte || '—'),
            r.tipo === 'P' ? eur(r.valor) : '', r.tipo === 'R' ? eur(r.valor) : '', eur(r.saldo),
            r.estado === 'falta' ? badge('danger', 'Falta de fundos') : r.estado === 'ccc' ? badge('warning', 'Usa CCC') : '', origem];
        });
        box.appendChild(table(['Data', 'Semana ISO', 'Tipo', 'Documento', 'Entidade', 'Pagamento', 'Recebimento', 'Saldo após', 'Estado', 'Origem do banco'], rows,
          ['start', 'start', 'start', 'start', 'start', 'end', 'end', 'end', 'start', 'start'], classes));
      } else {
        box.appendChild(h('p', { class: 'text-muted', text: 'Sem movimentos para o filtro escolhido.' }));
      }
      if (d.pages > 1) box.appendChild(pager(d.page, d.pages, d.total, function (p) { ui.planPage = p; loadPlanDetail(); }));
      var b = d.bank;
      box.appendChild(h('p', { class: 'tr-note', text: b.nome + ': saldo inicial ' + eur(b.saldo) + (st.settings.useCCC && b.ccc ? ' mais CCC ' + eur(b.ccc) : '') +
        '. No mesmo dia os recebimentos entram antes dos pagamentos; o saldo após cada pagamento mostra onde faltam fundos. Créditos aparecem com sinal negativo na coluna do seu lado.' }));
    }).catch(function (e) { fail(e, box); });
  }


  // ------------------------------------------------------------------ dashboard
  function card(title, value, sub) {
    return h('div', { class: 'tr-card' }, h('div', { class: 'tr-card-t', text: title }), h('div', { class: 'tr-card-v', text: value }), h('div', { class: 'tr-card-s', text: sub }));
  }
  function renderDashboard() {
    var box = $('tr-dashboard');
    req('GET', '/api/treasury/dashboard').then(function (d) {
      clear(box);
      var cap = $('tr-dash-caption');
      if (cap) cap.textContent = 'Indicadores em direto: mudam com os parâmetros, os bancos, os documentos e as atribuições desta página. Início em ' + fmtD(d.start) + ', horizonte de ' + d.nPeriods + ' ' + d.perLabel + '.';
      var R = d.side.R, P = d.side.P;
      var nShort = d.shortBanks.length;
      box.appendChild(h('div', { class: 'tr-cards' },
        card('Saldo total', eur(d.saldo0), d.nBanks + ' bancos'),
        card('CCC disponível', eur(d.ccc), d.useCCC ? 'conta como fundos disponíveis' : 'não conta como fundos (ver parâmetros)'),
        card('Liquidez total', eur(d.saldo0 + d.ccc), 'saldo mais CCC'),
        card('Recebimentos no horizonte', eur(d.recH), 'no plano, depois das regras de vencidos'),
        card('Pagamentos no horizonte', eur(d.pagH), 'no plano, depois das regras de vencidos'),
        card('Saldo final do horizonte', eur(d.saldoFinal), 'todos os bancos'),
        card('Saldo projetado mínimo', eur(d.minV), d.minI < 0 ? 'no início, ' + fmtD(d.start) : d.minLabel),
        card('Bancos com falta de fundos', nShort + ' de ' + d.nReal, nShort ? d.shortBanks.map(function (r) { return r.nome; }).join(', ') : 'nenhum banco em falta'),
        card('Pagamentos com banco sugerido', eur(d.sug.v), d.sug.n + ' pagamentos; sem banco que os cubra: ' + d.sug.badN + ' (' + eur(d.sug.badV) + ')'),
        card('Sem banco atribuído', eur(d.sem.pagT), 'pagamentos; recebimentos de clientes sem banco: ' + eur(d.sem.recT)),
        card('Vencidos a receber', eur(R.ov), R.ovDocs + ' documentos · ' + R.pctOv + ' do aberto, líquido de compensação'),
        card('Vencidos a pagar', eur(P.ov), P.ovDocs + ' documentos · ' + P.pctOv + ' do aberto, líquido de compensação'),
        card('Prazo médio de recebimento', Math.round(R.avgDias) + ' dias', 'até ao vencimento, ponderado pelo valor; vencidos há ' + Math.round(R.avgVencido) + ' dias em média'),
        card('Prazo médio de pagamento', Math.round(P.avgDias) + ' dias', 'até ao vencimento, ponderado pelo valor; vencidos há ' + Math.round(P.avgVencido) + ' dias em média')));
      var nP = d.periods.length;
      var labels = d.periods.map(function (_, i) { return labelAt(i, nP, d.periods); });
      var serRP = [{ name: 'Recebimentos', values: d.dRec, color: '#00a65a' }, { name: 'Pagamentos', values: d.dPag, color: '#dd4b39' }];
      var bl = d.banks.map(function (b) { return b.nome; });
      var serB = [{ name: 'Saldo inicial', values: d.banks.map(function (b) { return b.saldo; }) }, { name: 'CCC disponível', values: d.banks.map(function (b) { return b.ccc; }) }, { name: 'Saldo final projetado', values: d.banks.map(function (b) { return b.final; }) }];
      var serA = [{ name: 'Vencidos a receber', values: R.aging, color: '#00a65a' }, { name: 'Vencidos a pagar', values: P.aging, color: '#dd4b39' }];
      box.appendChild(h('div', { class: 'tr-charts' },
        h('div', { class: 'tr-chart' }, h('h5', { text: 'Saldo projetado, todos os bancos' }), lineChart(d.dClose, labels)),
        h('div', { class: 'tr-chart' }, h('h5', { text: 'Recebimentos e pagamentos' }), colChart(labels, serRP), legend(serRP)),
        h('div', { class: 'tr-chart' }, h('h5', { text: 'Saldos por banco' }), colChart(bl, serB), legend(serB.map(function (x, i) { return { name: x.name, color: PAL[i] }; }))),
        h('div', { class: 'tr-chart' }, h('h5', { text: 'Antiguidade dos vencidos' }), colChart(d.bands, serA), legend(serA))));
      if (d.shortBanks.length) {
        box.appendChild(table(['Banco em falta', 'Saldo inicial', 'Saldo mínimo', '1.ª falta', 'Períodos em falta', 'Necessidade máx.'],
          d.shortBanks.map(function (r) { return [r.nome, eur(r.saldo), eur(r.min), r.first ? fmtD(r.first) : '—', r.periodos + ' de ' + d.nPeriods, eur(r.need)]; }), ['start', 'end', 'end', 'start', 'end', 'end']));
      } else {
        box.appendChild(h('div', { class: 'callout callout-success' }, h('h4', { text: 'Sem faltas de fundos.' }), h('p', { text: 'Nenhum banco fica negativo no horizonte com os parâmetros atuais.' })));
      }
      box.appendChild(table(['Antiguidade dos vencidos', 'A receber', 'Docs', 'A pagar', 'Docs'],
        d.bands.map(function (b, i) { return [b, eur(R.aging[i]), String(R.agingN[i]), eur(P.aging[i]), String(P.agingN[i])]; }), ['start', 'end', 'end', 'end', 'end']));
      var topRows = function (a) { return a.map(function (e) { return [String(e.n), e.nome, eur(e.v), e.pct, e.ov > 0 ? eur(e.ov) : '—']; }); };
      box.appendChild(h('div', { class: 'tr-charts' },
        h('div', {}, h('h4', { text: 'Dez maiores clientes em aberto' }), h('p', { class: 'tr-note', text: 'Débitos em aberto depois da compensação: ' + eur(R.tot) + ' em ' + R.docs + ' documentos.' }),
          table(['#', 'Cliente', 'Em aberto', '% do total', 'Vencido'], topRows(d.topR), ['start', 'start', 'end', 'end', 'end']),
          h('p', { class: 'tr-note', text: 'Os dez maiores somam ' + eur(d.topRSum) + ', ' + d.topRPct + ' do total a receber.' })),
        h('div', {}, h('h4', { text: 'Dez maiores fornecedores em aberto' }), h('p', { class: 'tr-note', text: 'Débitos em aberto depois da compensação: ' + eur(P.tot) + ' em ' + P.docs + ' documentos.' }),
          table(['#', 'Fornecedor', 'Em aberto', '% do total', 'Vencido'], topRows(d.topP), ['start', 'start', 'end', 'end', 'end']),
          h('p', { class: 'tr-note', text: 'Os dez maiores somam ' + eur(d.topPSum) + ', ' + d.topPPct + ' do total a pagar.' }))));
    }).catch(function (e) { fail(e, box); });
  }

  // ------------------------------------------------------------------ sugestões de otimização
  function renderSugestoes() {
    var box = $('tr-sugestoes');
    req('GET', '/api/treasury/suggestions').then(function (d) {
      clear(box);
      box.appendChild(h('div', { class: 'callout callout-info' }, h('h4', { text: 'Sugestões, não decisões.' }),
        h('p', { text: 'Os impactos são estimativas em euros e não se somam: várias sugestões atuam sobre os mesmos fundos. Não incluem juros, comissões nem penalizações.' })));
      if (d.items.length) {
        box.appendChild(table(['#', 'Sugestão', 'Impacto', 'Fundamentação', 'Estado'], d.items.map(function (x, i) { return [String(i + 1), x.t, eur(x.imp), x.why, 'Sugestão']; }), ['start', 'start', 'end', 'start', 'start']));
      } else {
        box.appendChild(h('p', { text: 'Sem sugestões neste momento: nenhum banco em falta, sem pagamentos sem banco, sem créditos por compensar nem concentrações relevantes.' }));
      }
    }).catch(function (e) { fail(e, box); });
  }

  // ------------------------------------------------------------------ entidades nos dois lados
  function renderDuplos() {
    var box = clear($('tr-duplos'));
    var dual = st.dual || [];
    var sup = dual.filter(function (e) { return e.classe === 'F'; }).length;
    box.appendChild(h('p', { text: dual.length + ' entidades aparecem como cliente e como fornecedor. ' + sup + ' tratadas como fornecedor: a conta de cliente não aparece em recebimentos e entra nos pagamentos como crédito, de modo que se paga a diferença. ' + (dual.length - sup) + ' tratadas como cliente: mantêm-se duas entradas, sem compensação entre si.' }));
    box.appendChild(h('p', { class: 'tr-note', text: 'Classificação automática: fornecedor quando o valor absoluto dos documentos de fornecedor é igual ou superior ao dos de cliente. Pode alterá-la por entidade.' }));
    box.appendChild(h('p', { class: 'tr-note', text: 'Banco: só os clientes têm banco (ficheiro de clientes e bancos). Uma entidade tratada como fornecedor paga-se pelo banco de pagamento dos fornecedores (o sugerido, o por defeito, ou o que atribuir a esse fornecedor), e o banco do ficheiro não se aplica à sua conta de cliente compensada. Tratada como cliente, a conta de cliente usa o banco do ficheiro e a de fornecedor fica sem banco.' }));
    box.appendChild(h('div', { id: 'tr-duplos-msg' }));
    var rows = dual.map(function (e) {
      var f = e.classe === 'F';
      var sel = select([{ value: 'F', label: 'Fornecedor (a pagar pela diferença)' }, { value: 'C', label: 'Cliente (duas entradas)' }], e.classe, function (v) {
        req('PUT', '/api/treasury/dual/' + encodeURIComponent(e.terc), { classe: v }).then(function () { return refreshAll(); }).catch(function (er) { msg($('tr-duplos-msg'), errText(er)); });
      });
      return [e.nome, e.terc, e.nR + ' · ' + eur(e.netR), e.nP + ' · ' + eur(e.netP), e.banco || '—', sel,
        f ? (e.diff >= 0 ? 'A pagar ' + eur(e.diff) : 'Crédito de ' + eur(-e.diff)) : 'A receber ' + eur(e.netR) + ' e a pagar ' + eur(e.netP)];
    });
    if (rows.length) box.appendChild(table(['Entidade', 'Código', 'A receber (docs · líquido)', 'A pagar (docs · líquido)', 'Banco no ficheiro de clientes', 'Tratamento', 'Resultado'], rows, ['start', 'start', 'end', 'end', 'start', 'start', 'start']));
    box.appendChild(h('div', { class: 'tr-controls' }, btn('Repor classificação automática', 'btn-default', function () {
      req('DELETE', '/api/treasury/dual').then(function () { return refreshAll(); }).catch(function (er) { msg($('tr-duplos-msg'), errText(er)); });
    })));
  }

  // ------------------------------------------------------------------ movimentos recorrentes
  var CATS = ['Renda', 'Salários', 'Impostos/IVA/IRC/Seg. Social', 'Seguros', 'Outros'];
  var FREQS = ['Semanal', 'Mensal', 'Trimestral', 'Anual'];
  var FDS = ['Manter', 'Adiantar para dia útil', 'Adiar para dia útil'];
  var WDN = ['segunda', 'terça', 'quarta', 'quinta', 'sexta', 'sábado', 'domingo'];

  function renderRecorrentes() {
    var box = $('tr-recorrentes');
    req('GET', '/api/treasury/recurring').then(function (d) {
      clear(box);
      var rd = ui.recDraft;
      box.appendChild(h('div', { class: 'tr-controls' },
        btn('Adicionar movimento recorrente', 'btn-primary', function () {
          ui.recMsg = '';
          ui.recDraft = { id: null, desc: '', cat: CATS[0], tipo: 'P', valor: '', banco: SEM, freq: 'Mensal', dia: '1', ini: fmtD(d.start), fim: '', fds: FDS[0] };
          renderRecorrentes();
        }),
        check('Incluir movimentos recorrentes', d.incRec, function (v) { saveSetting({ incRec: v }); })));
      if (rd) {
        var inp = function (k, ph) { return h('input', { class: 'form-control', value: rd[k], placeholder: ph, oninput: function (e) { rd[k] = e.target.value; } }); };
        var sl = function (k, opts) { return select(opts, rd[k], function (v) { rd[k] = v; }); };
        var bo = [{ value: SEM, label: 'Sem banco (usa o banco por defeito de pagamento ou recebimento)' }].concat(bankOptions());
        var diaCtl = rd.freq === 'Semanal'
          ? field('Dia da semana', sl('dia', WDN.map(function (w, i) { return { value: String(i), label: w }; })))
          : field('Dia do mês', sl('dia', Array.apply(null, Array(31)).map(function (_, i) { return { value: String(i + 1), label: String(i + 1) }; })));
        box.appendChild(h('div', { class: 'tr-controls' },
          field('Descrição', inp('desc', 'Ex.: renda do armazém')),
          field('Categoria', sl('cat', CATS.map(function (c) { return { value: c, label: c }; }))),
          field('Tipo', sl('tipo', [{ value: 'P', label: 'Pagamento' }, { value: 'R', label: 'Recebimento' }])),
          field('Valor (€)', inp('valor', '0,00')),
          field('Banco', sl('banco', bo))));
        box.appendChild(h('div', { class: 'tr-controls' },
          field('Frequência', select(FREQS.map(function (f) { return { value: f, label: f }; }), rd.freq, function (v) {
            var was = rd.freq; rd.freq = v;
            rd.dia = v === 'Semanal' ? (was === 'Semanal' ? rd.dia : '0') : (was === 'Semanal' ? '1' : rd.dia);
            renderRecorrentes();
          })),
          diaCtl,
          field('Data de início', inp('ini', 'DD/MM/AAAA')),
          field('Data de fim (opcional)', inp('fim', 'Sem fim')),
          field('Fim de semana', sl('fds', FDS.map(function (f) { return { value: f, label: f }; })))));
        box.appendChild(h('div', { class: 'tr-controls' },
          btn('Guardar', 'btn-primary', function () {
            var body = { desc: rd.desc, cat: rd.cat, tipo: rd.tipo, valor: rd.valor, banco: rd.banco === SEM ? '' : rd.banco, freq: rd.freq, dia: Number(rd.dia), ini: rd.ini, fim: rd.fim, fds: rd.fds };
            var p = rd.id ? req('PUT', '/api/treasury/recurring/' + encodeURIComponent(rd.id), body) : req('POST', '/api/treasury/recurring', body);
            p.then(function () { ui.recDraft = null; ui.recMsg = ''; return refreshAll(); }).catch(function (e) { ui.recMsg = errText(e); renderRecorrentes(); });
          }),
          btn('Cancelar', 'btn-default', function () { ui.recDraft = null; ui.recMsg = ''; renderRecorrentes(); }),
          (rd.freq !== 'Semanal' && Number(rd.dia) > 28) ? h('span', { class: 'text-muted', text: 'Nos meses sem este dia usa-se o último dia do mês.' }) : null));
        if (ui.recMsg) box.appendChild(h('p', { class: 'tr-err', text: ui.recMsg }));
      }
      if (d.items.length) {
        var rows = d.items.map(function (r) {
          var estado = !r.ativo ? badge('warning', 'Desligado') : !d.incRec ? badge('warning', 'Desligado (global)') : badge('success', 'Ativo');
          var dts = r.datas.join(', ') + (r.maisDatas ? '…' : '');
          return [estado, r.desc, r.cat, r.tipo === 'R' ? 'Recebimento' : 'Pagamento', eur(r.valor), r.bancoValido ? bankName(r.banco) : 'Por defeito', r.freq,
            r.freq === 'Semanal' ? (WDN[r.dia] || String(r.dia)) : String(r.dia), fmtD(r.ini), r.fim ? fmtD(r.fim) : '—', r.fds, r.ocorrencias === 0 ? '0' : r.ocorrencias + ' · ' + dts,
            h('div', { class: 'tr-actions' },
              btn('Editar', 'btn-default', function () {
                ui.recMsg = '';
                ui.recDraft = { id: r.id, desc: r.desc, cat: r.cat, tipo: r.tipo, valor: fmtIn(r.valor), banco: r.bancoValido ? r.banco : SEM, freq: r.freq, dia: String(r.dia), ini: fmtD(r.ini), fim: r.fim ? fmtD(r.fim) : '', fds: r.fds };
                renderRecorrentes();
              }),
              btn(r.ativo ? 'Desligar' : 'Ligar', 'btn-default', function () {
                req('PUT', '/api/treasury/recurring/' + encodeURIComponent(r.id), { ativo: !r.ativo }).then(function () { return refreshAll(); }).catch(function (e) { fail(e, box); });
              }),
              btn('Apagar', 'btn-default', function () {
                if (!window.confirm('Apagar este movimento recorrente?')) return;
                req('DELETE', '/api/treasury/recurring/' + encodeURIComponent(r.id)).then(function () { if (ui.recDraft && ui.recDraft.id === r.id) ui.recDraft = null; return refreshAll(); }).catch(function (e) { fail(e, box); });
              }))];
        });
        box.appendChild(table(['Estado', 'Descrição', 'Categoria', 'Tipo', 'Valor', 'Banco', 'Frequência', 'Dia', 'Início', 'Fim', 'Fim de semana', 'No horizonte', 'Ações'], rows,
          ['start', 'start', 'start', 'start', 'end', 'start', 'start', 'start', 'start', 'start', 'start', 'start', 'start']));
      } else {
        box.appendChild(h('p', { text: 'Ainda não há movimentos recorrentes. Adicione rendas, salários, impostos ou seguros, ou importe-os em Importar e exportar, modo Recorrentes.' }));
      }
      box.appendChild(h('p', { class: 'tr-note', text: d.items.length + (d.items.length === 1 ? ' movimento (' : ' movimentos (') + d.ativos + (d.ativos === 1 ? ' ativo). ' : ' ativos). ') +
        (d.incRec ? 'No horizonte geram ' + d.nP + ' pagamentos (' + eur(d.vP) + ') e ' + d.nR + ' recebimentos (' + eur(d.vR) + '), a contar a partir de ' + fmtD(d.start) + '.' : 'Estão desligados globalmente: não entram no plano.') }));
    }).catch(function (e) { fail(e, box); });
  }

  // ------------------------------------------------------------------ saldos e parâmetros
  function renderSaldos() {
    var box = clear($('tr-saldos'));
    box.appendChild(h('div', { id: 'tr-banktable' }));
    box.appendChild(h('div', { id: 'tr-bankmsg' }));
    box.appendChild(h('div', { id: 'tr-settings' }));
    box.appendChild(h('div', { id: 'tr-ovtext' }));
    renderBankTable();
    renderSettings();
    renderOvText();
  }

  function renderBankTable() {
    var box = clear($('tr-banktable'));
    var rows = st.banks.map(function (b) {
      var nome = h('input', { class: 'form-control', value: b.nome });
      var saldo = h('input', { class: 'form-control', value: fmtIn(b.saldo), placeholder: '0,00' });
      var ccc = h('input', { class: 'form-control', value: fmtIn(b.ccc), placeholder: '0,00' });
      var status = h('span', { class: 'tr-err' });
      var save = function (inp, key) {
        return function () {
          var body = {}; body[key] = inp.value;
          req('PUT', '/api/treasury/banks/' + encodeURIComponent(b.id), body).then(function (nb) {
            clear(status);
            var cur = st.banks.filter(function (x) { return x.id === b.id; })[0] || b;
            cur.nome = nb.nome; cur.saldo = nb.saldo; cur.ccc = nb.ccc;
            saldo.value = fmtIn(nb.saldo); ccc.value = fmtIn(nb.ccc);
            updateBankTotals();
            if (key === 'nome') renderSettings();
            refreshAll({ saldos: true });
          }).catch(function (e) { status.textContent = e.details ? Object.keys(e.details).map(function (k) { return e.details[k]; }).join(' ') : errText(e); });
        };
      };
      nome.addEventListener('change', save(nome, 'nome'));
      saldo.addEventListener('change', save(saldo, 'saldo'));
      ccc.addEventListener('change', save(ccc, 'ccc'));
      return [nome, saldo, ccc, status, btn('Remover', 'btn-default', function () {
        if (!window.confirm('Remover o banco "' + (b.nome || '') + '"? As atribuições a este banco ficam sem banco.')) return;
        req('DELETE', '/api/treasury/banks/' + encodeURIComponent(b.id)).then(function () { return refreshAll(); }).catch(function (e) { msg($('tr-bankmsg'), errText(e)); });
      })];
    });
    rows.push([h('strong', { text: 'Total' }), h('span', { id: 'tr-tot-saldo', text: eur(sum(st.banks.map(function (b) { return b.saldo; }))) }), h('span', { id: 'tr-tot-ccc', text: eur(sum(st.banks.map(function (b) { return b.ccc; }))) }), '', '']);
    box.appendChild(table(['Banco', 'Saldo inicial (€)', 'CCC disponível (€)', 'Validação', 'Ações'], rows));
    box.appendChild(h('div', { class: 'tr-controls' }, btn('Adicionar banco', 'btn-default', function () {
      req('POST', '/api/treasury/banks', { nome: 'Novo banco', saldo: '0,00', ccc: '0,00' }).then(function () { return refreshAll(); }).catch(function (e) { msg($('tr-bankmsg'), errText(e)); });
    })));
  }
  function updateBankTotals() {
    var a = $('tr-tot-saldo'), c = $('tr-tot-ccc');
    if (a) a.textContent = eur(sum(st.banks.map(function (b) { return b.saldo; })));
    if (c) c.textContent = eur(sum(st.banks.map(function (b) { return b.ccc; })));
  }

  function saveSetting(patch) {
    return req('PUT', '/api/treasury/settings', patch).then(function (s) { st.settings = s; return refreshAll({ saldos: true }); })
      .catch(function (e) { msg($('tr-bankmsg'), errText(e)); return refreshAll(); });
  }

  function renderSettings() {
    var s = st.settings;
    var box = clear($('tr-settings'));
    var ruleOpts = [{ value: '0', label: 'No dia de início' }, { value: '7', label: '7 dias após o início' }, { value: '14', label: '14 dias após o início' }, { value: '30', label: '30 dias após o início' }, { value: 'x', label: 'Fora do plano' }];
    if (!ruleOpts.some(function (o) { return o.value === s.ruleR; })) ruleOpts.push({ value: s.ruleR, label: s.ruleR + ' dias após o início' });
    var ruleOptsP = ruleOpts.slice();
    if (!ruleOptsP.some(function (o) { return o.value === s.ruleP; })) ruleOptsP.push({ value: s.ruleP, label: s.ruleP + ' dias após o início' });
    var inicio = h('input', { class: 'form-control', value: s.inicio ? fmtD(s.inicio) : '', placeholder: fmtD(st.ref) + ' (data do Sage)', style: 'width:200px' });
    inicio.addEventListener('change', function () { saveSetting({ inicio: inicio.value }); });
    box.appendChild(h('div', { class: 'tr-controls' },
      field('Data de início', inicio),
      field('Horizonte', select(['4', '6', '8', '10', '12', '16', '20', '26'].map(function (w) { return { value: w, label: w + ' semanas' }; }), String(s.horiz), function (v) { saveSetting({ horiz: v }); })),
      field('Vista', segmented([{ value: 'Semanal', label: 'Semanal' }, { value: 'Diária', label: 'Diária' }], s.vista, function (v) { saveSetting({ vista: v }).then(renderSettings); })),
      check('Contar CCC como fundos disponíveis', s.useCCC, function (v) { saveSetting({ useCCC: v }); })));
    box.appendChild(h('div', { class: 'tr-controls' },
      field('Recebimentos vencidos', select(ruleOpts, s.ruleR, function (v) { saveSetting({ ruleR: v }); })),
      field('Pagamentos vencidos', select(ruleOptsP, s.ruleP, function (v) { saveSetting({ ruleP: v }); })),
      field('Banco de pagamento por defeito (fornecedores)', select(bankOptions([{ value: '', label: 'Nenhum (sem banco atribuído)' }]), s.defBankP, function (v) { saveSetting({ defBankP: v }); })),
      field('Banco de recebimento por defeito (clientes sem banco)', select(bankOptions([{ value: '', label: 'Nenhum (sem banco atribuído)' }]), s.defBankR, function (v) { saveSetting({ defBankR: v }); }))));
    box.appendChild(h('div', { class: 'tr-controls' },
      check('Compensar créditos com documentos da mesma entidade', s.netting, function (v) { saveSetting({ netting: v }); }),
      check('Incluir pendentes de aprovação', s.incPend, function (v) { saveSetting({ incPend: v }); }),
      check('Incluir pagamentos fora do Sage', s.incOut, function (v) { saveSetting({ incOut: v }); }),
      check('Incluir movimentos recorrentes', s.incRec, function (v) { saveSetting({ incRec: v }); })));
  }

  function renderOvText() {
    var box = $('tr-ovtext');
    if (!box) return;
    clear(box);
    var p = plan;
    if (!st.startValid) box.appendChild(h('p', { class: 'tr-err', text: 'Data de início inválida; a usar ' + fmtD(st.ref) + '.' }));
    box.appendChild(h('p', { class: 'tr-note', text: 'Vencidos tratados pelas regras acima: ' + eur(p.ovR) + ' de recebimentos e ' + eur(p.ovP) + ' de pagamentos (líquidos). ' +
      (p.vencFora > 0 ? p.vencFora + ' vencidos (' + eur(p.vencForaV) + ') ficam fora do plano. ' : '') +
      (p.fora > 0 ? p.fora + ' documentos (' + eur(p.foraV) + ') vencem depois do horizonte. ' : '') +
      (p.compens > 0 ? p.compens + ' linhas ficam a zero por compensação. ' : '') +
      (p.invalid > 0 ? p.invalid + ' documentos sem data ou valor válidos não entram.' : '') }));
  }

  // ------------------------------------------------------------------ banco por entidade
  function renderEntities() {
    var box = clear($('tr-entidades'));
    var e = ui.ent;
    var q = h('input', { class: 'form-control', value: e.q, placeholder: 'Nome ou código' });
    var timer = null;
    q.addEventListener('input', function () { clearTimeout(timer); timer = setTimeout(function () { e.q = q.value; e.page = 0; loadEntities(); }, 350); });
    box.appendChild(h('div', { class: 'tr-controls' },
      field('Lado', segmented([{ value: 'P', label: 'Fornecedores' }, { value: 'R', label: 'Clientes' }], e.side, function (v) { e.side = v; e.page = 0; renderEntities(); })),
      field('Pesquisar', q),
      check('Só sem banco', e.only, function (v) { e.only = v; e.page = 0; loadEntities(); })));
    var bulkSel = select(bankOptions([{ value: '', label: 'Escolher banco' }]), e.bulk, function (v) { e.bulk = v; });
    box.appendChild(h('div', { class: 'tr-controls', id: 'tr-bulk' }, field('Banco a aplicar', bulkSel)));
    box.appendChild(h('div', { id: 'tr-ent-msg' }));
    box.appendChild(h('div', { id: 'tr-ent-table' }));
    loadEntities();
  }

  function bulkApply(mode) {
    var e = ui.ent;
    if (mode !== 'clear' && !e.bulk) return;
    req('POST', '/api/treasury/entities/bulk', { side: e.side, q: e.q, only: e.only, mode: mode, banco: e.bulk }).then(function () { return refreshAll(); })
      .catch(function (er) { msg($('tr-ent-table'), errText(er)); });
  }

  function loadEntities() {
    var e = ui.ent;
    var box = $('tr-ent-table');
    if (!box) return;
    req('GET', '/api/treasury/entities?' + qs({ side: e.side, q: e.q, only: e.only ? 1 : 0, page: e.page })).then(function (d) {
      clear(box);
      var bulk = $('tr-bulk');
      while (bulk.childNodes.length > 1) bulk.removeChild(bulk.lastChild);
      bulk.appendChild(btn('Aplicar às sem banco (' + d.toApplyEmpty + ')', 'btn-default', function () { bulkApply('empty'); }, !e.bulk));
      bulk.appendChild(btn('Aplicar a todas do filtro (' + d.total + ')', 'btn-default', function () { bulkApply('all'); }, !e.bulk));
      bulk.appendChild(btn('Limpar as do filtro', 'btn-default', function () { bulkApply('clear'); }));
      bulk.appendChild(btn('Repor bancos dos clientes (ficheiro)', 'btn-default', function () {
        if (!window.confirm('Repor os bancos de todos os clientes a partir do ficheiro de clientes e bancos? As atribuições feitas à mão aos clientes perdem-se.')) return;
        req('POST', '/api/treasury/clientes-bancos/reset').then(function (r) { return refreshAll().then(function () { msg($('tr-ent-msg'), r.message, true); }); }).catch(function (er) { msg($('tr-ent-msg'), errText(er)); });
      }));
      var rows = d.items.map(function (x) {
        var sel = select(bankOptions([{ value: '', label: 'Sem banco' }]), x.banco, function (v) {
          req('PUT', '/api/treasury/entities/' + encodeURIComponent(x.key), { banco: v }).then(function () { return refreshAll(); }).catch(function (er) { msg(box, errText(er)); });
        });
        return [x.nome, x.key.slice(1), String(x.n), eur(x.net), x.ov > 0 ? eur(x.ov) : '—', sel];
      });
      box.appendChild(table(['Entidade', 'Código', 'Docs', e.side === 'R' ? 'A receber (líquido)' : 'A pagar (líquido)', 'Vencido', 'Banco'], rows, ['start', 'start', 'end', 'end', 'end', 'start']));
      box.appendChild(pager(d.page, d.pages, d.total, function (p) { e.page = p; loadEntities(); }));
      box.appendChild(h('p', { class: 'tr-note', text: d.cliSem.count === 0 ? 'Todos os clientes com documentos em aberto têm banco.' :
        d.cliSem.count + ' clientes com documentos em aberto sem banco no ficheiro (' + eur(d.cliSem.net) + ' a receber, líquido) ficam em Sem banco atribuído até lhes dar banco: ' +
        d.cliSem.nomes.map(function (x) { return x.nome + ' (' + x.codigo + ')'; }).join('; ') + (d.cliSem.count > d.cliSem.nomes.length ? '; e mais ' + (d.cliSem.count - d.cliSem.nomes.length) + '.' : '.') }));
      box.appendChild(h('p', { class: 'tr-note', text: d.assigned + ' de ' + d.entities + ' entidades têm banco atribuído. Para atribuir em massa por Excel, use a exportação "Bancos por entidade".' }));
    }).catch(function (er) { fail(er, box); });
  }

  // ------------------------------------------------------------------ projeção
  function lineChart(points, labels) {
    var W = 560, H = 230, L = 58, R = 10, T = 10, B = 26;
    var vals = points.map(function (p) { return p; });
    var mn = Math.min.apply(null, [0].concat(vals)), mx = Math.max.apply(null, [0].concat(vals));
    var span = Math.max(1, mx - mn), ceil = mx + span * 0.08, floor = mn < 0 ? mn - span * 0.08 : 0;
    var y = function (v) { return T + (ceil - v) / (ceil - floor) * (H - T - B); };
    var x = function (i) { return L + (points.length === 1 ? 0.5 : i / (points.length - 1)) * (W - L - R); };
    var s = svg('svg', { viewBox: '0 0 ' + W + ' ' + H, role: 'img', 'aria-label': 'Saldo final por período' });
    for (var t = 0; t <= 4; t++) {
      var v = floor + (ceil - floor) * t / 4;
      s.appendChild(svg('line', { x1: L, x2: W - R, y1: y(v), y2: y(v), stroke: '#e5e5e5' }));
      s.appendChild(svg('text', { x: L - 4, y: y(v) + 3, 'text-anchor': 'end', text: eurK(v) }));
    }
    if (floor < 0) {
      s.appendChild(svg('line', { x1: L, x2: W - R, y1: y(0), y2: y(0), stroke: '#999', 'stroke-dasharray': '4 3' }));
      s.appendChild(svg('text', { x: L + 2, y: y(0) - 3, text: '0 €' }));
    }
    s.appendChild(svg('polyline', { points: points.map(function (v, i) { return x(i) + ',' + y(v); }).join(' '), fill: 'none', stroke: '#3c8dbc', 'stroke-width': 2 }));
    points.forEach(function (v, i) { s.appendChild(svg('circle', { cx: x(i), cy: y(v), r: 2.5, fill: v < 0 ? '#dd4b39' : '#3c8dbc' }, svg('title', { text: labels[i] + ': ' + eur(v) }))); });
    labels.forEach(function (l, i) { if (l) s.appendChild(svg('text', { x: x(i), y: H - 8, 'text-anchor': 'middle', text: l })); });
    return s;
  }
  var PAL = ['#3c8dbc', '#00a65a', '#f39c12', '#dd4b39'];
  function colChart(labels, series) {
    var W = 560, H = 230, L = 58, R = 10, T = 10, B = 26;
    var all = [].concat.apply([], series.map(function (s) { return s.values; }));
    var mx = Math.max.apply(null, [1].concat(all)) * 1.08;
    var mnRaw = Math.min.apply(null, [0].concat(all));
    var mn = mnRaw < 0 ? mnRaw * 1.08 : 0;
    var y = function (v) { return T + (mx - v) / (mx - mn) * (H - T - B); };
    var n = labels.length, gw = (W - L - R) / n, bw = Math.max(2, gw / (series.length + 1));
    var s = svg('svg', { viewBox: '0 0 ' + W + ' ' + H, role: 'img' });
    for (var t = 0; t <= 4; t++) {
      var v = mn + (mx - mn) * t / 4;
      s.appendChild(svg('line', { x1: L, x2: W - R, y1: y(v), y2: y(v), stroke: '#e5e5e5' }));
      s.appendChild(svg('text', { x: L - 4, y: y(v) + 3, 'text-anchor': 'end', text: eurK(v) }));
    }
    series.forEach(function (se, si) {
      se.values.forEach(function (v, i) {
        var top = y(Math.max(0, v)), bot = y(Math.min(0, v));
        s.appendChild(svg('rect', { x: L + i * gw + (gw - bw * series.length) / 2 + si * bw, y: top, width: Math.max(1, bw - 1), height: Math.max(0, bot - top), fill: se.color || PAL[si % PAL.length] }, svg('title', { text: se.name + ' ' + labels[i] + ': ' + eur(v) })));
      });
    });
    labels.forEach(function (l, i) { if (l) s.appendChild(svg('text', { x: L + i * gw + gw / 2, y: H - 8, 'text-anchor': 'middle', text: l.length > 16 ? l.slice(0, 15) + '…' : l })); });
    return s;
  }
  function legend(series) {
    return h('div', { class: 'tr-legend' }, series.map(function (se, si) { return h('span', {}, h('i', { style: 'background:' + (se.color || PAL[si % PAL.length]) }), se.name); }));
  }
  function labelAt(i, nP, periods) {
    if (nP <= 10) return periods[i].short;
    var step = Math.max(1, Math.round((nP - 1) / 5));
    return i === 0 || i === nP - 1 || (i % step === 0 && nP - 1 - i >= step / 2) ? periods[i].short : '';
  }

  function renderProjection() {
    var box = clear($('tr-projecao'));
    var res = plan.banks, per = plan.periods, nP = per.length;
    if (ui.chartBank !== TOTAL && !res.some(function (r) { return r.id === ui.chartBank; })) ui.chartBank = TOTAL;
    var chartRes = res.filter(function (r) { return r.id === ui.chartBank; })[0];
    var col = function (k) { return per.map(function (_, i) { return chartRes ? chartRes[k][i] : sum(res.map(function (r) { return r[k][i]; })); }); };
    var labels = per.map(function (_, i) { return labelAt(i, nP, per); });
    box.appendChild(h('div', { class: 'tr-controls' }, field('Gráficos', select([{ value: TOTAL, label: 'Todos os bancos' }].concat(res.map(function (r) { return { value: r.id, label: r.nome }; })), ui.chartBank, function (v) { ui.chartBank = v; renderProjection(); }))));
    box.appendChild(h('div', { class: 'tr-charts' },
      h('div', { class: 'tr-chart' }, h('h5', { text: 'Saldo final' }), lineChart(col('close'), labels)),
      h('div', { class: 'tr-chart' }, h('h5', { text: 'Recebimentos e pagamentos' }), colChart(labels, [{ name: 'Recebimentos', values: col('rec'), color: '#00a65a' }, { name: 'Pagamentos', values: col('pag'), color: '#dd4b39' }]),
        h('div', { class: 'tr-legend' }, h('span', {}, h('i', { style: 'background:#00a65a' }), 'Recebimentos'), h('span', {}, h('i', { style: 'background:#dd4b39' }), 'Pagamentos')))));
    box.appendChild(h('div', { class: 'tr-controls' }, segmented(['Saldo final', 'Recebimentos', 'Pagamentos', 'Líquido'].map(function (m) { return { value: m, label: m }; }), ui.medida, function (v) { ui.medida = v; renderProjection(); })));
    var vis = res.filter(function (r) { return r.visivel; });
    var valOf = function (r, i) { return ui.medida === 'Saldo final' ? r.close[i] : ui.medida === 'Recebimentos' ? r.rec[i] : ui.medida === 'Pagamentos' ? r.pag[i] : r.rec[i] - r.pag[i]; };
    var rows = per.map(function (p, i) {
      var alert = res.filter(function (r) { return r.close[i] + r.lim < 0; }).map(function (r) { return r.nome; });
      return [p.label].concat(vis.map(function (r) { return eur(valOf(r, i)); }), [eur(sum(res.map(function (r) { return valOf(r, i); }))), alert.length ? badge('danger', alert.join(', ')) : '']);
    });
    if (ui.medida === 'Saldo final') {
      rows.unshift(['Saldo inicial ' + dm(plan.start)].concat(vis.map(function (r) { return eur(r.saldo); }), [eur(sum(res.map(function (r) { return r.saldo; }))), '']));
    }
    box.appendChild(table(['Período'].concat(vis.map(function (r) { return r.nome; }), ['Total', 'Bancos em falta']), rows, ['start'].concat(vis.map(function () { return 'end'; }), ['end', 'start'])));
  }

  // ------------------------------------------------------------------ documentos (recebimentos / pagamentos)
  function prazoTxt(d) {
    if (d === null || d === undefined) return '';
    return d < 0 ? 'vencido há ' + (-d) + ' d' : d === 0 ? 'vence no início' : 'em ' + d + ' d';
  }
  function notaOf(it) {
    var a = [];
    if (it.flag.indexOf('p') >= 0) a.push('Pendente aprovação');
    if (it.flag.indexOf('u') >= 0) a.push('USD, sem conversão');
    if (it.origem !== 'Sage X3') a.push(it.origem);
    if (it.overridden) a.push('alterado');
    return a.join(' · ') || '—';
  }

  function initDocs(tipo) {
    var box = clear($('tr-docs-' + tipo));
    var f = ui.filt[tipo];
    var q = h('input', { class: 'form-control', value: f.q, placeholder: 'Documento, entidade ou tipo' });
    var timer = null;
    q.addEventListener('input', function () { clearTimeout(timer); timer = setTimeout(function () { f.q = q.value; f.page = 0; loadDocs(tipo); }, 350); });
    box.appendChild(h('div', { class: 'tr-controls' },
      btn(tipo === 'R' ? 'Novo recebimento' : 'Novo pagamento', 'btn-primary', function () {
        ui.edit[tipo] = { id: null, isNew: true, kind: 'manual', draft: { doc: '', parte: '', venc: fmtD(plan.start), dataPlan: '', valor: '', banco: '' } };
        f.page = 0; loadDocs(tipo);
      }),
      field('Banco', select(bankOptions([{ value: '', label: 'Todos' }, { value: SEM, label: 'Sem banco' }]), f.bank, function (v) { f.bank = v; f.page = 0; loadDocs(tipo); })),
      field('Prazo', select([{ value: '', label: 'Todos' }, { value: 'venc', label: 'Vencidos' }, { value: 'avenc', label: 'A vencer' }, { value: 'cred', label: 'Créditos e adiantamentos' }, { value: 'excl', label: 'Excluídos (Sage)' }], f.prazo, function (v) { f.prazo = v; f.page = 0; loadDocs(tipo); })),
      field('Pesquisar', q)));
    box.appendChild(h('div', { id: 'tr-docs-tbl-' + tipo }));
  }

  function loadDocs(tipo) {
    if (!$('tr-docs-tbl-' + tipo)) initDocs(tipo);
    var box = $('tr-docs-tbl-' + tipo);
    var f = ui.filt[tipo];
    req('GET', '/api/treasury/open-items?' + qs({ tipo: tipo, bank: f.bank, prazo: f.prazo, q: f.q, page: f.page })).then(function (d) {
      clear(box);
      var ed = ui.edit[tipo];
      var rows = [];
      var items = d.items;
      if (ed && ed.isNew) rows.push(editRow(tipo, ed, { tdoc: 'Man', origem: 'Manual', flag: '' }));
      items.forEach(function (it) {
        if (ed && !ed.isNew && ed.id === it.id) rows.push(editRow(tipo, ed, it));
        else rows.push(viewRow(tipo, it));
      });
      if (rows.length) {
        box.appendChild(table(['Tipo', 'Documento', tipo === 'R' ? 'Cliente' : 'Fornecedor', 'Vencimento', 'Semana ISO (venc.)', 'Data planeada', 'Semana ISO (planeada)', 'Valor', 'Líquido', 'Banco', 'Nota', 'Ações'], rows,
          ['start', 'start', 'start', 'start', 'start', 'start', 'start', 'end', 'end', 'start', 'start', 'start']));
      } else {
        box.appendChild(h('p', { class: 'text-muted', text: 'Nenhum documento corresponde ao filtro atual.' }));
      }
      box.appendChild(pager(d.page, d.pages, d.total, function (p) { f.page = p; loadDocs(tipo); }));
      var s = d.summary;
      box.appendChild(h('p', { class: 'tr-note', text: s.count + ' documentos, líquido ' + eur(s.liquido) + '. ' + s.semCount + ' sem banco (' + eur(s.semValor) + ').' + (s.excluidos ? ' ' + s.excluidos + ' excluídos do plano (filtro "Excluídos").' : '') }));
    }).catch(function (e) { fail(e, box); });
  }

  function viewRow(tipo, it) {
    var banco;
    if (it.bancoOrigem === 'sugerido' || it.bancoOrigem === 'sugerido-sem-fundos') banco = h('em', { text: bankName(it.bancoEfetivo) + ' (sugerido' + (it.bancoOrigem === 'sugerido' ? '' : ', sem fundos') + ')' });
    else if (it.bancoEfetivo) banco = bankName(it.bancoEfetivo) + (it.bancoOrigem === 'documento' ? '' : it.bancoOrigem === 'entidade' ? ' (entidade)' : ' (defeito)');
    else banco = badge('warning', 'Sem banco');
    var acts = [];
    if (it.recorrente) {
      acts.push(h('span', { class: 'text-muted', text: 'em Movimentos recorrentes' }));
    } else if (it.editable === 'xs') {
      acts.push(btn('Editar', 'btn-default', function () {}, true));
    } else if (it.excluded) {
      acts.push(btn('Reativar', 'btn-default', function () { sageChange(tipo, it.id, { excluded: false }); }));
    } else {
      acts.push(btn('Editar', 'btn-default', function () {
        ui.edit[tipo] = { id: it.id, isNew: false, kind: it.editable, draft: { doc: it.doc, parte: it.parte, venc: fmtD(it.venc), dataPlan: it.dataPlan ? fmtD(it.dataPlan) : '', valor: fmtIn(it.valor), banco: it.banco } };
        loadDocs(tipo);
      }));
      if (it.editable === 'manual') acts.push(btn('Apagar', 'btn-default', function () {
        if (!window.confirm('Apagar esta entrada manual?')) return;
        req('DELETE', '/api/treasury/manual-entries/' + encodeURIComponent(it.id)).then(function () { return refreshAll(); }).catch(function (e) { msg($('tr-docs-tbl-' + tipo), errText(e)); });
      }));
      else {
        acts.push(btn('Excluir', 'btn-default', function () { if (window.confirm('Excluir este documento do plano? (o ficheiro Sage não é alterado)')) sageChange(tipo, it.id, { excluded: true }); }));
        if (it.overridden) acts.push(btn('Repor', 'btn-default', function () { req('DELETE', '/api/treasury/overrides/' + encodeURIComponent(it.id)).then(function () { return refreshAll(); }); }));
      }
    }
    return [it.recorrente ? '↻ ' + it.tdoc : it.tdoc, it.doc || '—', it.parte || '—', fmtD(it.venc) + ' · ' + prazoTxt(it.prazoDias), it.semanaIso || '—', it.dataPlan ? fmtD(it.dataPlan) + ' (alterada)' : '—', it.dataPlan ? it.semanaIsoPlan : '—',
      eur(it.valor), it.liquido !== null ? eur(it.liquido) : '—', banco, it.recorrente ? h('strong', { text: '↻ ' + notaOf(it) }) : notaOf(it), h('div', { class: 'tr-actions' }, acts)];
  }

  function sageChange(tipo, id, patch) {
    return req('PUT', '/api/treasury/overrides/' + encodeURIComponent(id), patch).then(function () { ui.edit[tipo] = null; return refreshAll(); })
      .catch(function (e) { msg($('tr-docs-tbl-' + tipo), errText(e)); });
  }

  function editRow(tipo, ed, it) {
    var d = ed.draft, isSage = ed.kind === 'sage';
    var inp = function (k, ph, ro) { return h('input', { class: 'form-control', value: d[k], placeholder: ph, disabled: !!ro, oninput: function (e) { d[k] = e.target.value; } }); };
    var status = h('div', { class: 'tr-err' });
    var save = function () {
      var body;
      if (isSage) body = { venc: d.venc, dataPlan: d.dataPlan, valor: d.valor, banco: d.banco };
      else body = { tipo: tipo, doc: d.doc, parte: d.parte, venc: d.venc, dataPlan: d.dataPlan, valor: d.valor, banco: d.banco };
      var p = isSage ? req('PUT', '/api/treasury/overrides/' + encodeURIComponent(ed.id), body)
        : ed.isNew ? req('POST', '/api/treasury/manual-entries', body) : req('PUT', '/api/treasury/manual-entries/' + encodeURIComponent(ed.id), body);
      p.then(function () { ui.edit[tipo] = null; return refreshAll(); }).catch(function (e) { status.textContent = errText(e); });
    };
    var cancel = function () { ui.edit[tipo] = null; loadDocs(tipo); };
    return [it.tdoc, inp('doc', 'Documento', isSage), inp('parte', tipo === 'R' ? 'Cliente' : 'Fornecedor', isSage), inp('venc', 'DD/MM/AAAA'), '', inp('dataPlan', 'Igual ao vencimento'), '', inp('valor', '0,00'), '',
      select(bankOptions([{ value: '', label: 'Da entidade ou sem banco' }]), d.banco, function (v) { d.banco = v; }), notaOf(it),
      h('div', {}, h('div', { class: 'tr-actions' }, btn('Guardar', 'btn-primary', save), btn('Cancelar', 'btn-default', cancel)), status)];
  }

  // ------------------------------------------------------------------ importar / exportar
  function renderIo() {
    var box = clear($('tr-io'));
    var K = ui.ioKind;
    var texts = {
      documents: 'Formato: tipo;tipo_doc;documento;codigo;entidade;vencimento;valor;banco;data_planeada;origem;estado. Tipo Recebimento ou Pagamento, datas DD/MM/AAAA, valores 1234,56 com sinal (negativo = crédito). O banco só preenche o documento; o banco da entidade vem de Bancos por entidade. As duas últimas colunas (semana ISO) são calculadas e ignoradas na importação. As linhas importadas passam a ser entradas manuais (os documentos do Sage vêm só do ficheiro Sage). Aceita colagem do Excel.',
      plan: 'Só exportação. Colunas: banco;banco_origem;documento;fornecedor;vencimento;semana_iso_venc;data_prevista;semana_iso_prevista;valor;saldo_apos;nota, uma linha por pagamento, ordenada por banco e data prevista. banco_origem indica se o banco é sugerido, do documento, do fornecedor ou por defeito.',
      recurring: 'Formato: descricao;categoria;tipo;valor;banco;frequencia;dia;inicio;fim;fim_de_semana;ativo. Categoria: Renda, Salários, Impostos/IVA/IRC/Seg. Social, Seguros ou Outros. Tipo Recebimento ou Pagamento, valor positivo. Frequência Semanal, Mensal, Trimestral ou Anual. Dia: dia da semana (segunda a domingo) se semanal, senão dia do mês (1 a 31; nos meses mais curtos vale o último dia). Datas DD/MM/AAAA, fim opcional. Fim de semana: Manter, Adiantar ou Adiar. Ativo: sim ou não.',
      balances: 'Formato: banco;saldo;ccc_disponivel. "Substituir tudo" remove os bancos que não estiverem na lista.',
      entities: 'Formato: codigo;entidade;banco. O código é C ou F seguido do código Sage (por exemplo F1010). Banco em branco remove a atribuição.' };
    var ph = { documents: 'Pagamento;FFO;FFOE012611/00001;F1010;Fornecedor Exemplo, Lda.;30/11/2026;1500,00;Banco Exemplo A;;Manual;',
      recurring: 'Renda do armazém;Renda;Pagamento;2500,00;Banco Exemplo A;Mensal;1;01/11/2026;;Adiar para dia útil;sim',
      balances: 'Banco Exemplo A;25000,00;50000,00', entities: 'F1010;Fornecedor Exemplo, Lda.;Banco Exemplo A', plan: '' };
    var exp = h('textarea', { readonly: true, 'aria-label': 'Exportação' });
    var imp = h('textarea', { placeholder: ph[K], 'aria-label': 'Importação', disabled: K === 'plan' });
    var out = h('div', {});
    if (ui.ioMsg) { msg(out, ui.ioMsg, true); ui.ioMsg = ''; }
    var load = function () {
      req('GET', '/api/treasury/export?kind=' + K).then(function (d) { exp.value = d.text; }).catch(function (e) { fail(e, out); });
    };
    var doImport = function (replace) {
      if (replace && !window.confirm('Substituir tudo? Esta ação altera os dados guardados.')) return;
      req('POST', '/api/treasury/import', { kind: K, text: imp.value, replace: replace }).then(function (r) { clear(out); msg(out, r.message, true); return refreshAll(); })
        .catch(function (e) { clear(out); msg(out, errText(e)); });
    };
    box.appendChild(h('div', { class: 'tr-controls' },
      segmented([{ value: 'documents', label: 'Documentos' }, { value: 'recurring', label: 'Recorrentes' }, { value: 'balances', label: 'Saldos' }, { value: 'entities', label: 'Bancos por entidade' }, { value: 'plan', label: 'Plano por banco' }], K, function (v) { ui.ioKind = v; renderIo(); }),
      btn('Copiar exportação', 'btn-default', function () {
        if (navigator.clipboard) navigator.clipboard.writeText(exp.value).then(function () { msg(out, 'Exportação copiada para a área de transferência.', true); }, function () { msg(out, 'Não foi possível copiar: selecione o texto e use Ctrl+C.'); });
        else msg(out, 'Não foi possível copiar: selecione o texto e use Ctrl+C.');
      }),
      btn('Descarregar CSV', 'btn-default', function () {
        var a = h('a', { href: URL.createObjectURL(new Blob(['\ufeff' + exp.value], { type: 'text/csv;charset=utf-8' })), download: 'tesouraria-' + K + '.csv' });
        document.body.appendChild(a); a.click(); document.body.removeChild(a);
      }),
      btn('Repor dados do Sage', 'btn-default', function () {
        if (!window.confirm('Repor dados do Sage? Remove as alterações a documentos do Sage, o tratamento das entidades dos dois lados, os movimentos recorrentes, a prioridade dos bancos e repõe os bancos dos clientes a partir do ficheiro. Os saldos e CCC dos bancos e as entradas manuais mantêm-se.')) return;
        req('POST', '/api/treasury/reset').then(function (r) { ui.sugMsg = ''; ui.ioMsg = r.message; return refreshAll(); }).catch(function (e) { clear(out); msg(out, errText(e)); });
      })));
    box.appendChild(h('p', { class: 'tr-note', text: texts[K] }));
    box.appendChild(h('div', { class: 'row tr-io' },
      h('div', { class: 'col-md-6' }, h('h4', { text: 'Exportar (copiar para o Excel)' }), exp),
      h('div', { class: 'col-md-6' }, h('h4', { text: 'Importar (colar aqui)' }), imp,
        h('div', { class: 'tr-controls', style: 'margin-top:8px' }, btn('Acrescentar', 'btn-primary', function () { doImport(false); }, K === 'plan'), btn('Substituir tudo', 'btn-default', function () { doImport(true); }, K === 'plan')))));
    box.appendChild(out);
    box.appendChild(h('p', { class: 'tr-note', text: 'As alterações ficam guardadas no servidor (pasta privada). Exporte para ter uma cópia de segurança ou para trabalhar no Excel e cole de novo para continuar.' }));
    load();
  }

  // ------------------------------------------------------------------ ficheiro Sage
  function renderSage() {
    var box = clear($('tr-sage'));
    var file = h('input', { type: 'file', accept: '.xls,.xlsx', class: 'form-control', style: 'max-width:420px' });
    var out = h('div', {});
    box.appendChild(h('p', {}, st.demo ? 'Sem ficheiro privado: a mostrar dados fictícios de demonstração.' :
      'Ficheiro atual: ' + st.sourceName + ' · data de referência ' + fmtD(st.ref) + ' · ' + st.rows + ' documentos · importado em ' + (st.importedAt ? new Date(st.importedAt).toLocaleString('pt-PT') : '—') + '.'));
    if (st.warnings && st.warnings.length) box.appendChild(h('ul', { class: 'tr-err' }, st.warnings.map(function (w) { return h('li', { text: w }); })));
    box.appendChild(h('div', { class: 'tr-controls' }, field('Substituir pelo mapa de itens em aberto do Sage X3 (.xls ou .xlsx)', file),
      btn('Carregar e substituir', 'btn-primary', function () {
        if (!file.files.length) { msg(out, 'Escolha primeiro um ficheiro.'); return; }
        if (!window.confirm('Substituir o ficheiro Sage atual? (fica uma cópia .bak no servidor). Bancos, entradas manuais e atribuições mantêm-se.')) return;
        var fd = new FormData(); fd.append('file', file.files[0]);
        req('POST', '/api/treasury/sage-file', fd, true).then(function (r) {
          clear(out); msg(out, 'Ficheiro importado: ' + r.rows + ' documentos, referência ' + fmtD(r.ref) + (r.warnings.length ? '. Avisos: ' + r.warnings.join(' ') : '.'), true); return refreshAll();
        }).catch(function (e) { clear(out); msg(out, errText(e)); });
      }),
      btn('Repor alterações aos documentos Sage', 'btn-default', function () {
        if (!window.confirm('Remover todas as alterações feitas a documentos do Sage (bancos, datas planeadas, exclusões)?')) return;
        req('DELETE', '/api/treasury/overrides').then(function (r) { clear(out); msg(out, r.removed + ' alterações removidas.', true); return refreshAll(); }).catch(function (e) { msg(out, errText(e)); });
      })));
    box.appendChild(out);
    box.appendChild(h('p', { class: 'tr-note', text: 'O ficheiro é guardado só na pasta privada do servidor (data/private), nunca na imagem Docker nem no repositório. Se substituir o ficheiro diretamente na pasta, é relido automaticamente no pedido seguinte.' }));
    var cl = st.clientes || {};
    var cfile = h('input', { type: 'file', accept: '.xlsx,.xls', class: 'form-control', style: 'max-width:420px' });
    var cout = h('div', {});
    if (ui.srcMsg) { msg(cout, ui.srcMsg, true); ui.srcMsg = ''; }
    box.appendChild(h('hr', {}));
    box.appendChild(h('h4', { text: 'Clientes e bancos' }));
    box.appendChild(h('p', {}, cl.source === 'privado' || cl.source === 'amostra'
      ? (cl.source === 'amostra' ? 'Dados fictícios de demonstração. ' : '') + 'Ficheiro atual: ' + (cl.sourceName || 'clientes-bancos') + ' · ' + cl.count + ' clientes com banco' + (cl.ignored ? ' (' + cl.ignored + ' linhas ignoradas)' : '') + ' · importado em ' + (cl.importedAt ? new Date(cl.importedAt).toLocaleString('pt-PT') : '—') + '.'
      : 'Sem ficheiro de clientes e bancos: nenhum cliente tem banco atribuído. Carregue o ficheiro (colunas BPCNUM_0 = Terc. do Sage e DES_0 = banco) ou coloque-o em data/private/clientes-bancos.xlsx.'));
    if (cl.warnings && cl.warnings.length) box.appendChild(h('ul', { class: 'tr-err' }, cl.warnings.map(function (w) { return h('li', { text: w }); })));
    box.appendChild(h('div', { class: 'tr-controls' }, field('Carregar ficheiro de clientes e bancos (.xlsx)', cfile),
      btn('Carregar e repor bancos dos clientes', 'btn-primary', function () {
        if (!cfile.files.length) { msg(cout, 'Escolha primeiro um ficheiro.'); return; }
        if (!window.confirm('Substituir o ficheiro de clientes e bancos e repor os bancos de todos os clientes? As atribuições feitas à mão aos clientes perdem-se; bancos novos são criados com saldo e CCC a 0.')) return;
        var fd = new FormData(); fd.append('file', cfile.files[0]);
        req('POST', '/api/treasury/clientes-bancos', fd, true).then(function (r) { ui.srcMsg = r.message; return refreshAll(); }).catch(function (e) { clear(cout); msg(cout, errText(e)); });
      })));
    box.appendChild(cout);
    box.appendChild(h('p', { class: 'tr-note', text: 'Tal como o ficheiro Sage, o ficheiro de clientes e bancos fica só na pasta privada do servidor, nunca na imagem Docker nem no repositório.' }));
  }

  // ------------------------------------------------------------------ regras e origem
  function renderOrigem() {
    var box = clear($('tr-origem'));
    var S = st.sageSummary;
    var rowOf = function (label, x) { return [label, String(x.n), x.nPos + ' · ' + eur(x.pos), x.nNeg + ' · ' + eur(x.neg), eur(x.net), eur(x.ovPos), eur(x.ovNeg)]; };
    box.appendChild(h('p', { class: 'text-muted', text: 'Ficheiro Sage X3 de itens em aberto, data de referência ' + fmtD(st.ref) + ', divisa EUR.' }));
    box.appendChild(table(['Sage X3', 'Documentos', 'Débitos abertos', 'Créditos e adiantamentos', 'Líquido', 'Vencido (débitos)', 'Vencido (créditos)'],
      [rowOf('Clientes, a receber', S.R), rowOf('Fornecedores, a pagar (inclui contas de cliente compensadas)', S.P)], ['start', 'end', 'end', 'end', 'end', 'end', 'end']));
    box.appendChild(h('p', { class: 'tr-note', text: 'Dos fornecedores, ' + S.X.n + ' linhas são contas de cliente de ' + st.supCount + ' entidades tratadas como fornecedor (créditos de ' + eur(S.X.neg) + ' e débitos de ' + eur(S.X.pos) + ', líquido a abater ' + eur(-S.X.net) + '). Estas linhas já não aparecem em recebimentos.' }));
    var rules = [
      ['Cliente ou fornecedor', 'Pela conta coletiva (Colect.): CGMN, CGUE e CGPT são clientes e FGMN, FGUE e FGPT são fornecedores. Se um fornecedor aparecer dos dois lados, aparece a pagar pela diferença. Se um cliente aparecer dos dois lados, mantém-se com duas entradas.'],
      ['Valor', 'Usa Valor a pagar, o saldo em aberto depois de pagamentos parciais, e não o valor original do documento. Em fornecedores o sinal é invertido para que a pagar seja positivo.'],
      ['Créditos e adiantamentos', 'Notas de crédito (NCC, NCF, NCR), recebimentos por regularizar (REC), pagamentos por regularizar (PAG) e outros documentos com sinal contrário ficam como linhas negativas na sua lista. Com a compensação ligada, abatem aos documentos mais antigos da mesma entidade; o que sobrar fica como crédito.'],
      ['Vencimento', 'Usa Data venc. O prazo é a data de início menos Data venc.; antes do início é vencido, e a regra escolhida (no início, mais dias ou fora do plano) vale para clientes e fornecedores em separado. Uma data planeada manual prevalece.'],
      ['Pendentes de aprovação', 'Os ' + st.counts.pendentes + ' documentos de fornecedores com estado Pendente Aprovação entram por defeito e podem ser retirados.' + (st.counts.usd ? ' ' + st.counts.usd + ' documento(s) em divisa diferente de EUR contam como euros, sem conversão.' : '')],
      ['Fora do Sage', 'Financiamentos, encargos, salários e prestações que não estão no Sage são entradas manuais com banco e data próprios (origem Fora do Sage). Podem ser retirados em Incluir pagamentos fora do Sage.'],
      ['Banco sugerido para pagamentos', 'Seletor Banco dos pagamentos a fornecedores, no Plano de pagamentos por banco. Sugerido (por defeito): cada pagamento a fornecedor, manual ou recorrente sem banco é atribuído a um banco pelo algoritmo abaixo; Fixo: usa o banco de pagamento por defeito dos parâmetros; Manual: só contam os bancos atribuídos a documentos ou fornecedores e o resto fica em Sem banco atribuído. Os itens Fora do Sage têm banco próprio e ficam como estão, e um banco atribuído a um documento ou a um fornecedor, ou o banco de pagamento por defeito se estiver escolhido, prevalece sempre sobre a sugestão. Algoritmo: 1) fixam-se primeiro os recebimentos de cada banco e os pagamentos que já têm banco; 2) os pagamentos por sugerir são ordenados por vencidos primeiro, depois pela data prevista, depois pelo valor decrescente e, em caso de empate, pelo identificador do documento, por isso o resultado é sempre o mesmo; 3) para cada um calcula-se, em cada banco, a folga depois do pagamento: o menor saldo diário projetado desde a data do pagamento até ao fim do horizonte, já com os recebimentos futuros desse banco e os pagamentos já atribuídos, menos o valor, mais a CCC se Contar CCC como fundos disponíveis estiver ligado; 4) cabem os bancos com folga igual ou superior a zero, e entre eles escolhe-se pelo critério: maior folga (por defeito), ordem de prioridade (configurável com Subir e Descer), minimizar o uso de CCC (maior folga sem contar a CCC) ou minimizar transferências (ajuste justo: o banco com a menor folga que ainda cabe, que deixa os bancos com mais folga para pagamentos maiores); 5) se nenhum banco cabe, escolhe-se o banco com menos falta e o pagamento fica assinalado como precisando de transferência ou CCC; 6) créditos de fornecedores (valor negativo) vão para o banco ativo (com saldo ou movimentos) com menos folga. O pagamento sugerido aparece a itálico, com a folga, e Aceitar sugestão converte-o em atribuição manual por fornecedor (o banco que recebe a maior parte dos seus pagamentos) ou por documento. O plano por banco pode ser exportado em Importar e exportar, modo Plano por banco.'],
      ['Banco dos clientes e dos fornecedores', 'A relação cliente-banco vem do ficheiro clientes_bancos.xlsx (folha Export Worksheet), guardado na pasta privada do servidor e substituível em Ficheiros de origem: BPCNUM_0 é o código do cliente e corresponde a Terc. do Sage, BPCNAM_0 o nome, PAYBAN_0 o código do banco e DES_0 o nome do banco. A linha com BPCNUM 0 é a própria empresa e não entra. A correspondência é por código como texto, sem zeros à esquerda. Bancos: Novo Banco, Millennium BCP (BCP), Banco Santander Totta (Santander), Caixa Geral de Depósitos (CGD) e, novos na página, Bank Millennium Polska EUR e PLN (saldo e CCC a 0; os valores contam em euros, sem conversão da divisa PLN). Só os clientes têm banco: o banco carregado fica em Banco por entidade e pode ser alterado, e um banco escolhido num documento prevalece. Os clientes sem banco no ficheiro ficam em Sem banco atribuído e estão listados em Banco por entidade; usam o banco de recebimento por defeito, se o escolher. Os fornecedores não têm banco: o banco dos pagamentos é sugerido pela página (ver Banco sugerido para pagamentos), fixo no banco de pagamento por defeito, ou manual; um banco atribuído a um fornecedor ou a um documento prevalece. Uma entidade dos dois lados tratada como fornecedor paga-se pelo banco de pagamento dos fornecedores; tratada como cliente, a conta de cliente usa o banco do ficheiro. Importação e exportação Bancos por entidade mantêm-se; Repor bancos dos clientes volta a carregar o ficheiro.'],
      ['Saldos e CCC', 'Saldos de abertura e limites CCC são introduzidos manualmente em Saldos bancários e parâmetros (ou importados em modo Saldos).'],
      ['Movimentos recorrentes', 'Introduzidos à mão na secção Movimentos recorrentes (ou importados em modo Recorrentes; não vêm do Sage). Cada um tem descrição, categoria (Renda, Salários, Impostos/IVA/IRC/Seg. Social, Seguros, Outros), tipo, valor positivo, banco, frequência (semanal, mensal, trimestral, anual), dia, data de início e data de fim opcional. A página gera as ocorrências desde a data de início do plano até ao fim do horizonte; não gera ocorrências anteriores à data de início do plano, por isso nunca são vencidos. Mensal repete todos os meses; trimestral e anual repetem a cada 3 ou 12 meses a partir do mês da data de início; num mês sem o dia escolhido usa-se o último dia do mês. A regra de fim de semana (manter, adiantar ou adiar para dia útil) é aplicada à data de cada ocorrência. Cada ocorrência é um documento de origem Recorrente, marcado com ↻, com a sua semana ISO, que entra na projeção, no plano por banco, no dashboard (saldos, recebimentos e pagamentos por semana, saldo mínimo) e nas sugestões; fica de fora das listas de vencidos, do aging, dos prazos médios e dos dez maiores clientes e fornecedores, que descrevem documentos em aberto, e não é proposto para mudar de banco. Cada movimento liga-se e desliga-se à parte, e todos com Incluir movimentos recorrentes. Sem banco, usa o banco por defeito. Têm importação e exportação próprias (modo Recorrentes); a exportação de Documentos não os inclui.'],
      ['Colunas do ficheiro Sage', 'Terc., Nome, Tipo, Documento, Doc. Origem, Data Doc., Data venc., Colect., Nº Dias, Valor, Valor a pagar, Valor Pago, Status e Divisa. Usadas: Terc. e Nome (entidade), Tipo e Documento (documento), Data venc. (prazo e semana ISO), Colect. (cliente ou fornecedor), Valor a pagar (montante), Status (pendente de aprovação) e Divisa (USD). Não entram no cálculo: Doc. Origem e Data Doc. (informativas), Valor e Valor Pago (Valor a pagar já é o saldo) e Nº Dias (recalculado a partir da data de início).'],
      ['Coluna trabalhada: semana ISO', 'Calculada a partir de Data venc.: semana ISO 8601 com o ano ISO, por exemplo 2026-W44, com as semanas a começar à segunda-feira (a semana 1 é a que contém a 1.ª quinta-feira do ano, pelo que o ano ISO pode diferir do civil: 31/12/2026 e 01/01/2027 caem em 2026-W53). Aparece nas tabelas de recebimentos e pagamentos ao lado do vencimento e, quando há data planeada manual, na sua própria coluna. A projeção, o plano por banco, o dashboard e as sugestões agrupam por semana ISO, com o rótulo S44 2026 (26/10–01/11). O horizonte começa na semana ISO da data de início, cuja 1.ª semana pode ser parcial. Os vencidos caem na semana ISO da data de início, ou na semana da data que a regra escolhida lhes der. A data planeada manual prevalece. Não existe no Sage nem é gravada: é recalculada sempre.']
    ];
    box.appendChild(h('dl', { class: 'tr-rules' }, rules.map(function (r) { return [h('dt', { text: r[0] }), h('dd', { text: r[1] })]; })));
  }

  // ------------------------------------------------------------------ arranque
  var sf = document.querySelector('.sidebar-form');
  if (sf) sf.addEventListener('submit', function (e) { e.preventDefault(); });
  loadMenu();
  refreshAll();
})();
