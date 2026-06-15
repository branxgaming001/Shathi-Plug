/* Saathi embedded demo bot — vanilla, talks to /api/chat.php.
   Showcases: live chat, product showcase (demo), follow-ups, and live
   colour + mascot customization (the plugin's signature features). */
(function () {
  var API = '/api/chat.php';
  var MASCOT = function (n) { return (window.SAATHI_IMG && window.SAATHI_IMG['mascot-' + n]) || ''; };
  var COLORS = ['#6D5DFB', '#2DB4FF', '#19C37D', '#FF6B5E', '#F0567A', '#7C3AED'];
  var MASCOTS = [1, 3, 5, 6, 7, 8];
  var state = { open: false, mascot: 1, color: '#6D5DFB', history: [], busy: false };

  var esc = function (s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) { return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' })[c]; }); };
  function md(t) {
    var s = esc(t);
    // bullets
    var lines = s.split('\n'), out = [], inUl = false;
    lines.forEach(function (ln) {
      if (/^\s*[-*]\s+/.test(ln)) { if (!inUl) { out.push('<ul>'); inUl = true; } out.push('<li>' + ln.replace(/^\s*[-*]\s+/, '') + '</li>'); }
      else { if (inUl) { out.push('</ul>'); inUl = false; } if (ln.trim() === '') out.push(''); else out.push('<p>' + ln + '</p>'); }
    });
    if (inUl) out.push('</ul>');
    var h = out.join('');
    h = h.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>').replace(/`(.+?)`/g, '<code>$1</code>');
    return h;
  }

  var root = document.getElementById('sbot');
  if (!root) { root = document.createElement('div'); root.id = 'sbot'; document.body.appendChild(root); }
  root.style.setProperty('--bot', state.color);

  function launcher() {
    root.innerHTML =
      '<div class="sbot-launch">' +
      '<div class="sbot-tag" id="sbTag">Hi! I’m Saathi 👋 Ask me anything — or see a product demo ✨</div>' +
      '<button class="sbot-fab" id="sbFab" aria-label="Open chat"><img src="' + MASCOT(state.mascot) + '" alt="Saathi"></button>' +
      '</div>';
    document.getElementById('sbFab').onclick = open;
    document.getElementById('sbTag').onclick = open;
  }

  function windowHtml() {
    return '<div class="sbot-win" role="dialog" aria-label="Saathi chat">' +
      '<div class="sbot-head">' +
      '<img src="' + MASCOT(state.mascot) + '" alt="" id="sbHeadAv">' +
      '<div><div class="h-name">Saathi</div><div class="h-stat"><span class="dot"></span>Online · ask me anything</div></div>' +
      '<div class="h-btns"><button class="sbot-icon" id="sbReset" title="New chat">+</button><button class="sbot-icon" id="sbClose" title="Close">✕</button></div>' +
      '</div>' +
      '<div class="sbot-msgs" id="sbMsgs"></div>' +
      '<div class="sbot-cust">' +
      '<span class="lbl">Customize this bot — try it live:</span>' +
      '<span id="sbColors" style="display:flex;gap:6px"></span>' +
      '<span style="width:1px;height:18px;background:#e2def5"></span>' +
      '<span id="sbMascots" style="display:flex;gap:6px"></span>' +
      '</div>' +
      '<div class="sbot-input"><input id="sbInput" placeholder="Type your message…" autocomplete="off"><button class="sbot-send" id="sbSend" aria-label="Send">➤</button></div>' +
      '<div class="sbot-foot"><b>Saathi</b> · a product by RAI</div>' +
      '</div>';
  }

  function open() {
    state.open = true; root.innerHTML = windowHtml();
    document.getElementById('sbClose').onclick = close;
    document.getElementById('sbReset').onclick = function () { state.history = []; greet(); };
    document.getElementById('sbSend').onclick = sendInput;
    var inp = document.getElementById('sbInput');
    inp.addEventListener('keydown', function (e) { if (e.key === 'Enter') sendInput(); });
    buildCustomizer(); greet(); inp.focus();
  }
  function close() { state.open = false; launcher(); }

  function buildCustomizer() {
    var cw = document.getElementById('sbColors');
    COLORS.forEach(function (c) {
      var b = document.createElement('span'); b.className = 'sbot-sw' + (c === state.color ? ' on' : '');
      b.style.background = c; b.title = c;
      b.onclick = function () { state.color = c; root.style.setProperty('--bot', c); buildCustomizer(); };
      cw.appendChild(b);
    });
    var mw = document.getElementById('sbMascots');
    MASCOTS.forEach(function (n) {
      var b = document.createElement('img'); b.className = 'sbot-mini' + (n === state.mascot ? ' on' : '');
      b.src = MASCOT(n); b.title = 'Mascot ' + n;
      b.onclick = function () { state.mascot = n; var h = document.getElementById('sbHeadAv'); if (h) h.src = MASCOT(n); buildCustomizer(); };
      mw.appendChild(b);
    });
  }

  var msgs = function () { return document.getElementById('sbMsgs'); };
  function scroll() { var m = msgs(); if (m) m.scrollTop = m.scrollHeight; }
  function botRow(inner) {
    return '<div class="sbot-row"><img class="sbot-av" src="' + MASCOT(state.mascot) + '" alt="">' +
      '<div style="max-width:84%">' + inner + '</div></div>';
  }
  function addBot(html) { var d = document.createElement('div'); d.innerHTML = botRow('<div class="sbot-bub bot">' + html + '</div>'); msgs().appendChild(d.firstChild); scroll(); }
  function addMe(text) { var d = document.createElement('div'); d.innerHTML = '<div class="sbot-row me"><div class="sbot-bub me">' + esc(text) + '</div></div>'; msgs().appendChild(d.firstChild); scroll(); }

  function greet() {
    msgs().innerHTML = '';
    addBot('<p>Hi! I’m <strong>Saathi</strong> 👋 the AI assistant for your website. Ask me about features &amp; pricing — or tap below.</p>');
    var sug = ['What can Saathi do?', 'Show me a product demo', 'How much does it cost?', 'Is it easy to set up?'];
    var wrap = document.createElement('div'); wrap.className = 'sbot-sugs';
    sug.forEach(function (s) { var b = document.createElement('button'); b.className = 'sbot-sug'; b.textContent = s; b.onclick = function () { send(s); }; wrap.appendChild(b); });
    var holder = document.createElement('div'); holder.appendChild(wrap);
    msgs().lastChild.querySelector('div[style]').appendChild(wrap); scroll();
  }

  function typing(on) {
    var ex = document.getElementById('sbTyping');
    if (on) { if (ex) return; var d = document.createElement('div'); d.id = 'sbTyping'; d.innerHTML = botRow('<div class="sbot-bub bot"><div class="sbot-typing"><i></i><i></i><i></i></div></div>'); msgs().appendChild(d); scroll(); }
    else if (ex) ex.remove();
  }

  function renderProducts(list) {
    if (!list || !list.length) return;
    var box = document.createElement('div'); box.className = 'sbot-products';
    list.forEach(function (p) {
      var stars = '★★★★★';
      box.innerHTML += '<div class="sbot-pc"><img src="' + esc(p.image) + '" alt=""><div class="b">' +
        '<div class="nm">' + esc(p.name) + '</div>' +
        (p.rating ? '<div class="rt">' + stars + '<span>(' + esc(p.reviews || '') + ')</span></div>' : '') +
        '<div class="pr">' + (p.was ? '<del>' + esc(p.was) + '</del>' : '') + esc(p.price) + '</div>' +
        '<div class="act"><button class="sbot-buy">Buy now</button><button class="sbot-add" title="Add to cart">+</button></div>' +
        '</div></div>';
    });
    msgs().lastChild.querySelector('div[style]').appendChild(box);
    box.querySelectorAll('.sbot-add').forEach(function (b) { b.onclick = function () { b.textContent = '✓'; b.style.background = '#19c37d'; }; });
    box.querySelectorAll('.sbot-buy').forEach(function (b) { b.onclick = function () { addBot('<p>This is a <strong>demo</strong> — on your real store this opens secure checkout. 🛒</p>'); }; });
    scroll();
  }
  function renderFollowups(fu) {
    if (!fu || !fu.options || !fu.options.length) return;
    var box = document.createElement('div'); box.className = 'sbot-fu';
    if (fu.question) box.innerHTML = '<div class="q">' + esc(fu.question) + '</div>';
    fu.options.forEach(function (o) { var b = document.createElement('button'); b.innerHTML = '<span class="rd"></span><span>' + esc(o) + '</span>'; b.onclick = function () { send(o); }; box.appendChild(b); });
    msgs().lastChild.querySelector('div[style]').appendChild(box); scroll();
  }

  function sendInput() { var inp = document.getElementById('sbInput'); var v = (inp.value || '').trim(); if (v) { inp.value = ''; send(v); } }
  function send(text) {
    if (state.busy) return; state.busy = true;
    addMe(text); state.history.push({ role: 'user', content: text }); typing(true);
    fetch(API, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ message: text, history: state.history.slice(-8) }) })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        typing(false);
        var reply = (d && d.reply) ? d.reply : 'Sorry, I had trouble responding. Please try again.';
        addBot(md(reply)); state.history.push({ role: 'assistant', content: reply });
        if (d && d.products) renderProducts(d.products);
        if (d && d.followups) renderFollowups(d.followups);
        state.busy = false;
      })
      .catch(function () { typing(false); addBot('<p>Connection issue — please try again.</p>'); state.busy = false; });
  }

  launcher();
})();
