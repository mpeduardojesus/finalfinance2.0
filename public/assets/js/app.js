(function () {
  'use strict';

  function el(tag, attrs, children) {
    var n = document.createElement(tag);
    Object.keys(attrs || {}).forEach(function (k) {
      if (k === 'text') n.textContent = attrs[k];
      else if (k === 'class') n.className = attrs[k];
      else n.setAttribute(k, attrs[k]);
    });
    (children || []).forEach(function (c) { n.appendChild(c); });
    return n;
  }

  // Só permite http(s), caminhos relativos e âncoras (evita javascript: URLs)
  function safeUrl(u) {
    return typeof u === 'string' && /^(https?:\/\/|\/|#)/.test(u) ? u : '#';
  }

  function getJson(url) {
    return fetch(url, { headers: { Accept: 'application/json' } }).then(function (r) {
      if (!r.ok) throw new Error(url + ' → HTTP ' + r.status);
      return r.json();
    });
  }

  function showError(container, msg) {
    container.innerHTML = '';
    container.appendChild(el('p', { class: 'text-danger', text: msg }));
  }

  function renderMenu(items) {
    var ul = document.getElementById('sidebar-menu');
    ul.innerHTML = '';
    items.forEach(function (it) {
      var sub = el('ul', { class: 'treeview-menu' });
      (it.children || []).forEach(function (c) {
        sub.appendChild(el('li', {}, [el('a', { href: safeUrl(c.url) }, [el('i', { class: 'fa fa-angle-right' }), document.createTextNode(' ' + c.label)])]));
      });
      var a = el('a', { href: '#' }, [
        el('i', { class: 'fa ' + String(it.icon || 'fa-circle-o').replace(/[^\w-]/g, '') }),
        el('span', { text: it.label }),
        el('span', { class: 'pull-right-container' }, [el('i', { class: 'fa fa-angle-left pull-right' })])
      ]);
      ul.appendChild(el('li', { class: 'treeview' }, [a, sub]));
    });
  }

  function fmtDate(iso) { return String(iso).replace(/-/g, '.'); }

  function renderCommunications(rows) {
    var box = document.getElementById('comunicacoes');
    box.innerHTML = '';
    if (!rows.length) { box.appendChild(el('p', { class: 'text-muted', text: 'Sem comunicações.' })); return; }
    var list = el('div', { class: 'mp-list' });
    rows.forEach(function (r) {
      list.appendChild(el('div', { class: 'mp-row' + (r.highlighted ? ' hl' : ''), 'data-id': String(r.id) }, [
        el('div', {}, [el('span', { class: 'mp-date', text: fmtDate(r.date) }), el('span', { class: 'mp-dept', text: r.department })]),
        el('div', {}, [el('a', { href: safeUrl(r.url), text: r.title })])
      ]));
    });
    box.appendChild(list);
  }

  function renderFacts(rows) {
    var box = document.getElementById('sabias');
    box.innerHTML = '';
    if (!rows.length) { box.appendChild(el('p', { class: 'text-muted', text: 'Sem registos.' })); return; }
    rows.forEach(function (f) {
      box.appendChild(el('div', { class: 'mp-fact' }, [el('a', { href: safeUrl(f.url), text: f.title })]));
    });
  }

  getJson('/api/menu').then(renderMenu).catch(function () {
    renderMenu([]);
  });
  getJson('/api/communications').then(renderCommunications).catch(function (e) { showError(document.getElementById('comunicacoes'), 'Erro ao carregar comunicações.'); console.error(e); });
  getJson('/api/facts').then(renderFacts).catch(function (e) { showError(document.getElementById('sabias'), 'Erro ao carregar.'); console.error(e); });
  getJson('/api/health').then(function (h) {
    document.getElementById('backend-info').textContent = 'Dados: ' + h.backend;
  }).catch(function () {});
})();
