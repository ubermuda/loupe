(() => {
  const script = document.currentScript;
  const BACKEND = new URL(script.src).origin;
  const TOKEN = script.getAttribute('data-token') || '';
  const STORAGE_KEY = 'betterplans.siteReview.pending';

  const load = () => {
    try {
      return JSON.parse(localStorage.getItem(STORAGE_KEY)) || [];
    } catch {
      return [];
    }
  };
  const save = (items) => localStorage.setItem(STORAGE_KEY, JSON.stringify(items));
  let pending = load();

  // Build a stable-ish CSS selector for an element.
  const selectorFor = (element) => {
    if (element.id) return `#${CSS.escape(element.id)}`;
    const parts = [];
    let current = element;
    while (current && current.nodeType === 1 && parts.length < 5) {
      let part = current.tagName.toLowerCase();
      if (current.classList.length) {
        part += '.' + [...current.classList].map((className) => CSS.escape(className)).join('.');
      }
      const parent = current.parentElement;
      if (parent) {
        const siblings = [...parent.children].filter((child) => child.tagName === current.tagName);
        if (siblings.length > 1) part += `:nth-of-type(${siblings.indexOf(current) + 1})`;
      }
      parts.unshift(part);
      current = current.parentElement;
    }
    return parts.join(' > ');
  };

  // --- Shadow-DOM UI host ---
  const host = document.createElement('div');
  host.style.cssText = 'position:fixed;z-index:2147483647;bottom:16px;right:16px;';
  const root = host.attachShadow({ mode: 'open' });
  document.documentElement.appendChild(host);

  root.innerHTML = `
    <style>
      *{box-sizing:border-box;font-family:system-ui,sans-serif}
      .btn{cursor:pointer;border:0;border-radius:8px;padding:8px 12px;font-size:13px;background:#4f46e5;color:#fff}
      .btn.secondary{background:#e5e7eb;color:#111}
      .panel{position:fixed;bottom:64px;right:16px;width:300px;max-height:60vh;overflow:auto;background:#fff;
             border:1px solid #d1d5db;border-radius:12px;box-shadow:0 10px 30px rgba(0,0,0,.2);padding:12px;display:none}
      .panel.open{display:block}
      textarea{width:100%;min-height:70px;border:1px solid #d1d5db;border-radius:8px;padding:6px;font-size:13px}
      .item{border-top:1px solid #eee;padding:6px 0;font-size:12px}
      .row{display:flex;gap:8px;align-items:center;justify-content:space-between;margin-bottom:8px}
      code{background:#f3f4f6;padding:2px 4px;border-radius:4px;font-size:11px}
    </style>
    <button class="btn" id="toggle">Review (0)</button>
    <div class="panel" id="panel">
      <div class="row"><strong>Site review</strong>
        <button class="btn secondary" id="target">Target mode</button></div>
      <div id="list"></div>
      <div class="row" style="margin-top:8px">
        <button class="btn secondary" id="clear">Clear</button>
        <button class="btn" id="send">Send</button>
      </div>
      <div id="result"></div>
    </div>`;

  const byId = (id) => root.getElementById(id);
  let targeting = false;

  const refresh = () => {
    byId('toggle').textContent = `Review (${pending.length})`;
    byId('list').innerHTML = pending
      .map(
        (comment, index) =>
          `<div class="item"><code>${comment.selector}</code><br>${comment.body}
        <button class="btn secondary" data-del="${index}" style="padding:2px 6px;font-size:11px">x</button></div>`
      )
      .join('');
    root.querySelectorAll('[data-del]').forEach((button) =>
      button.addEventListener('click', () => {
        pending.splice(+button.dataset.del, 1);
        save(pending);
        refresh();
      })
    );
  };

  // --- Highlight + click capture in target mode ---
  const highlight = document.createElement('div');
  highlight.style.cssText =
    'position:fixed;pointer-events:none;z-index:2147483646;border:2px solid #4f46e5;background:rgba(79,70,229,.1);display:none';
  document.documentElement.appendChild(highlight);

  const onMove = (event) => {
    const element = document.elementFromPoint(event.clientX, event.clientY);
    if (!element || host.contains(element)) {
      highlight.style.display = 'none';
      return;
    }
    const rect = element.getBoundingClientRect();
    Object.assign(highlight.style, {
      display: 'block',
      left: rect.left + 'px',
      top: rect.top + 'px',
      width: rect.width + 'px',
      height: rect.height + 'px',
    });
  };

  const onClick = (event) => {
    const element = document.elementFromPoint(event.clientX, event.clientY);
    if (!element || host.contains(element)) return;
    event.preventDefault();
    event.stopPropagation();
    setTargeting(false);
    openComment(element);
  };

  const setTargeting = (on) => {
    targeting = on;
    byId('target').textContent = on ? 'Targeting… (esc)' : 'Target mode';
    highlight.style.display = 'none';
    if (on) {
      document.addEventListener('mousemove', onMove, true);
      document.addEventListener('click', onClick, true);
    } else {
      document.removeEventListener('mousemove', onMove, true);
      document.removeEventListener('click', onClick, true);
    }
  };

  const openComment = (element) => {
    const selector = selectorFor(element);
    const text = (element.innerText || '').trim().slice(0, 200);
    byId('result').innerHTML = `<div class="item"><code>${selector}</code>
      <textarea id="body" placeholder="Comment… (⌘/Ctrl+Enter to save)"></textarea>
      <div class="row"><button class="btn secondary" id="cancel">Cancel</button>
      <button class="btn" id="add">Save</button></div></div>`;
    byId('panel').classList.add('open');
    const textarea = byId('body');
    textarea.focus();
    const commit = () => {
      const body = textarea.value.trim();
      if (!body) return;
      pending.push({ body, selector, text, url: location.href });
      save(pending);
      byId('result').innerHTML = '';
      refresh();
    };
    textarea.addEventListener('keydown', (event) => {
      if ((event.metaKey || event.ctrlKey) && event.key === 'Enter') commit();
    });
    byId('add').addEventListener('click', commit);
    byId('cancel').addEventListener('click', () => {
      byId('result').innerHTML = '';
    });
  };

  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && targeting) setTargeting(false);
  });
  byId('toggle').addEventListener('click', () => byId('panel').classList.toggle('open'));
  byId('target').addEventListener('click', () => setTargeting(!targeting));
  byId('clear').addEventListener('click', () => {
    pending = [];
    save(pending);
    refresh();
    byId('result').innerHTML = '';
  });

  byId('send').addEventListener('click', async () => {
    if (!pending.length) return;
    byId('send').disabled = true;
    try {
      const response = await fetch(`${BACKEND}/api/site-review/batches`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Authorization: `Bearer ${TOKEN}` },
        body: JSON.stringify({ comments: pending }),
      });
      if (!response.ok) throw new Error(`HTTP ${response.status}`);
      const { batchId } = await response.json();
      pending = [];
      save(pending);
      refresh();
      byId('result').innerHTML = `<div class="item">Sent. Batch id:<br><code>${batchId}</code>
        <button class="btn secondary" id="copy">Copy</button></div>`;
      byId('copy').addEventListener('click', () => navigator.clipboard.writeText(batchId));
    } catch (error) {
      byId('result').innerHTML = `<div class="item">Send failed: ${error.message}</div>`;
    } finally {
      byId('send').disabled = false;
    }
  });

  refresh();
})();
