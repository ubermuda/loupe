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

  const escapeHtml = (value) =>
    String(value).replace(
      /[&<>"']/g,
      (character) =>
        ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[character],
    );

  // Inline SVG icons — this widget is embedded on third-party sites with no access
  // to the app's UX Icons bundle, so the markup must be self-contained.
  const svgIcon = (size, body) =>
    `<svg viewBox="0 0 24 24" width="${size}" height="${size}" fill="none" stroke="currentColor" ` +
    `stroke-width="2" stroke-linecap="round" stroke-linejoin="round">${body}</svg>`;
  const ICON = {
    comment: svgIcon(
      16,
      '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>' +
        '<line x1="9" y1="10" x2="15" y2="10"/><line x1="12" y1="7" x2="12" y2="13"/>',
    ),
    target: svgIcon(
      16,
      '<circle cx="12" cy="12" r="10"/><line x1="22" y1="12" x2="18" y2="12"/>' +
        '<line x1="6" y1="12" x2="2" y2="12"/><line x1="12" y1="6" x2="12" y2="2"/>' +
        '<line x1="12" y1="22" x2="12" y2="18"/>',
    ),
    check: svgIcon(14, '<polyline points="20 6 9 17 4 12"/>'),
    close: svgIcon(12, '<line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>'),
  };

  // Copy text to the clipboard, returning whether it actually succeeded. The async
  // Clipboard API is unavailable on insecure origins, so fall back to execCommand;
  // only report failure when both paths fail (never show false success to the user).
  const copyToClipboard = async (value) => {
    try {
      if (navigator.clipboard && navigator.clipboard.writeText) {
        await navigator.clipboard.writeText(value);
        return true;
      }
    } catch {
      /* fall through to the legacy fallback below */
    }
    try {
      const textarea = document.createElement('textarea');
      textarea.value = value;
      textarea.setAttribute('readonly', '');
      textarea.style.cssText = 'position:fixed;top:-9999px;opacity:0';
      document.documentElement.appendChild(textarea);
      textarea.select();
      const copied = document.execCommand('copy');
      textarea.remove();
      return copied;
    } catch {
      return false;
    }
  };

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

  // Resolve a pending comment to a live element on the current page, or null when it
  // is unanchored, was made on another page, or its selector no longer matches.
  const resolveElement = (comment) => {
    if (!comment.selector || comment.url !== location.href) return null;
    try {
      return document.querySelector(comment.selector);
    } catch {
      return null;
    }
  };

  // --- Shadow-DOM UI host (the floating panel) ---
  const host = document.createElement('div');
  host.style.cssText = 'position:fixed;z-index:2147483647;bottom:16px;right:16px;';
  const root = host.attachShadow({ mode: 'open' });
  document.documentElement.appendChild(host);

  root.innerHTML = `
    <style>
      *{box-sizing:border-box;font-family:system-ui,sans-serif}
      .btn{cursor:pointer;border:0;border-radius:8px;padding:8px 12px;font-size:13px;background:#4f46e5;
           color:#fff;display:inline-flex;align-items:center;justify-content:center;gap:6px;line-height:1}
      .btn.secondary{background:#e5e7eb;color:#111}
      .btn.success{background:#16a34a;color:#fff}
      .btn.danger{background:#dc2626;color:#fff}
      .btn[disabled]{opacity:.7;cursor:default}
      .iconbtn{cursor:pointer;border:0;border-radius:8px;width:32px;height:32px;display:inline-flex;
               align-items:center;justify-content:center;background:#e5e7eb;color:#111}
      .iconbtn:hover{background:#d1d5db}
      .iconbtn.active{background:#4f46e5;color:#fff}
      .iconbtn.small{width:22px;height:22px;border-radius:6px}
      .panel{position:fixed;bottom:64px;right:16px;width:300px;max-height:60vh;overflow:auto;background:#fff;
             border:1px solid #d1d5db;border-radius:12px;box-shadow:0 10px 30px rgba(0,0,0,.2);padding:12px;display:none}
      .panel.open{display:block}
      textarea{width:100%;min-height:70px;border:1px solid #d1d5db;border-radius:8px;padding:6px;font-size:13px;margin:6px 0}
      .toolbar{display:flex;gap:6px;align-items:center;margin-bottom:8px}
      .toolbar .hint{margin-left:auto;font-size:11px;color:#9ca3af}
      .row{display:flex;gap:8px;align-items:center;justify-content:space-between}
      .item{display:flex;gap:8px;align-items:flex-start;border-top:1px solid #eee;padding:6px 0;font-size:12px}
      .item .num{flex:0 0 auto;width:18px;height:18px;border-radius:50%;background:#4f46e5;color:#fff;
                 font-size:11px;display:inline-flex;align-items:center;justify-content:center}
      .item .item-body{flex:1 1 auto;word-break:break-word}
      .item.error{color:#b91c1c}
      .muted{color:#6b7280;font-style:italic}
      code{background:#f3f4f6;padding:2px 4px;border-radius:4px;font-size:11px;word-break:break-all}
      .target-label{font-size:12px;color:#374151}
      .spinner{display:inline-block;width:12px;height:12px;border:2px solid currentColor;
               border-right-color:transparent;border-radius:50%;animation:bp-spin .6s linear infinite}
      @keyframes bp-spin{to{transform:rotate(360deg)}}
    </style>
    <button class="btn" id="toggle">Review (0)</button>
    <div class="panel" id="panel">
      <div class="toolbar">
        <button class="iconbtn" id="general" title="Add comment (c)" aria-label="Add comment">${ICON.comment}</button>
        <button class="iconbtn" id="target" title="Target mode (t)" aria-label="Target mode" aria-pressed="false">${ICON.target}</button>
        <span class="hint">c · t</span>
      </div>
      <div id="list"></div>
      <div class="row" style="margin-top:8px">
        <button class="btn secondary" id="clear">Clear</button>
        <button class="btn" id="send">Send</button>
      </div>
      <div id="result"></div>
    </div>`;

  const byId = (id) => root.getElementById(id);
  let targeting = false;

  // --- On-screen overlay (highlight box, numbered pins, tooltip) in its own shadow host
  // so the host page's stylesheet can't bleed into the markers. ---
  const overlayHost = document.createElement('div');
  overlayHost.style.cssText = 'position:fixed;inset:0;pointer-events:none;z-index:2147483646;';
  const overlayRoot = overlayHost.attachShadow({ mode: 'open' });
  document.documentElement.appendChild(overlayHost);
  overlayRoot.innerHTML = `
    <style>
      .highlight{position:fixed;pointer-events:none;border:2px solid #4f46e5;background:rgba(79,70,229,.1);display:none;z-index:1}
      .pin{position:fixed;width:20px;height:20px;border:0;border-radius:50%;background:#4f46e5;color:#fff;padding:0;
           font:600 11px/1 system-ui,sans-serif;display:flex;align-items:center;justify-content:center;cursor:pointer;
           pointer-events:auto;box-shadow:0 1px 4px rgba(0,0,0,.3);z-index:2}
      :host(.targeting) .pin{pointer-events:none}
      .tooltip{position:fixed;max-width:220px;background:#111;color:#fff;font:12px/1.4 system-ui,sans-serif;
               padding:6px 8px;border-radius:6px;pointer-events:none;display:none;z-index:3;
               box-shadow:0 2px 8px rgba(0,0,0,.3);word-break:break-word}
    </style>
    <div class="highlight"></div>
    <div class="tooltip"></div>`;
  const highlight = overlayRoot.querySelector('.highlight');
  const tooltip = overlayRoot.querySelector('.tooltip');

  const showHighlightFor = (element) => {
    const rect = element.getBoundingClientRect();
    Object.assign(highlight.style, {
      display: 'block',
      left: rect.left + 'px',
      top: rect.top + 'px',
      width: rect.width + 'px',
      height: rect.height + 'px',
    });
  };
  const hideHighlight = () => {
    highlight.style.display = 'none';
  };

  const showTooltip = (text, element) => {
    tooltip.textContent = text;
    const rect = element.getBoundingClientRect();
    tooltip.style.display = 'block';
    const left = Math.min(rect.right + 6, window.innerWidth - tooltip.offsetWidth - 6);
    const top = Math.max(6, rect.top - tooltip.offsetHeight - 6);
    tooltip.style.left = Math.max(6, left) + 'px';
    tooltip.style.top = top + 'px';
  };
  const hideTooltip = () => {
    tooltip.style.display = 'none';
  };

  let pins = [];
  const repositionPins = () => {
    pins.forEach((pin) => {
      const rect = pin.element.getBoundingClientRect();
      if (rect.width === 0 && rect.height === 0) {
        pin.node.style.display = 'none';
        return;
      }
      pin.node.style.display = 'flex';
      pin.node.style.left = rect.right - 10 + 'px';
      pin.node.style.top = rect.top - 10 + 'px';
    });
  };
  const buildPins = () => {
    pins.forEach((pin) => pin.node.remove());
    pins = [];
    pending.forEach((comment, index) => {
      const element = resolveElement(comment);
      if (!element) return;
      const node = document.createElement('button');
      node.className = 'pin';
      node.textContent = String(index + 1);
      node.addEventListener('mouseenter', () => {
        showHighlightFor(element);
        showTooltip(comment.body, element);
      });
      node.addEventListener('mouseleave', () => {
        if (!targeting) hideHighlight();
        hideTooltip();
      });
      node.addEventListener('click', () => byId('panel').classList.add('open'));
      overlayRoot.appendChild(node);
      pins.push({ element, node });
    });
    repositionPins();
  };

  let repositionFrame = 0;
  const scheduleReposition = () => {
    if (repositionFrame) return;
    repositionFrame = requestAnimationFrame(() => {
      repositionFrame = 0;
      repositionPins();
    });
  };
  window.addEventListener('scroll', scheduleReposition, true);
  window.addEventListener('resize', scheduleReposition);

  const refresh = () => {
    byId('toggle').textContent = `Review (${pending.length})`;
    byId('list').innerHTML = pending
      .map(
        (comment, index) =>
          `<div class="item" data-index="${index}">` +
          `<span class="num">${index + 1}</span>` +
          `<span class="item-body">${escapeHtml(comment.body)}` +
          `${comment.selector ? '' : ' <em class="muted">(general)</em>'}</span>` +
          `<button class="iconbtn small" data-del="${index}" title="Delete" aria-label="Delete comment">${ICON.close}</button>` +
          `</div>`,
      )
      .join('');
    root.querySelectorAll('#list .item').forEach((row) => {
      const comment = pending[+row.dataset.index];
      row.addEventListener('mouseenter', () => {
        if (targeting) return;
        const element = resolveElement(comment);
        if (element) showHighlightFor(element);
      });
      row.addEventListener('mouseleave', () => {
        if (!targeting) hideHighlight();
      });
    });
    root.querySelectorAll('[data-del]').forEach((button) =>
      button.addEventListener('click', () => {
        pending.splice(+button.dataset.del, 1);
        save(pending);
        refresh();
      }),
    );
    buildPins();
  };

  // --- Highlight + click capture in target mode ---
  const onMove = (event) => {
    const element = document.elementFromPoint(event.clientX, event.clientY);
    if (!element || host.contains(element)) {
      hideHighlight();
      return;
    }
    showHighlightFor(element);
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
    const targetButton = byId('target');
    targetButton.classList.toggle('active', on);
    targetButton.setAttribute('aria-pressed', on ? 'true' : 'false');
    targetButton.title = on ? 'Targeting… (esc to cancel)' : 'Target mode (t)';
    overlayHost.classList.toggle('targeting', on);
    hideHighlight();
    if (on) {
      document.addEventListener('mousemove', onMove, true);
      document.addEventListener('click', onClick, true);
    } else {
      document.removeEventListener('mousemove', onMove, true);
      document.removeEventListener('click', onClick, true);
    }
  };

  // element === null → an unanchored ("general") comment with no selector/text.
  const openComment = (element) => {
    const selector = element ? selectorFor(element) : '';
    const text = element ? (element.innerText || '').trim().slice(0, 200) : '';
    const snippet = text.slice(0, 60);
    const label = element
      ? `<div class="target-label">Commenting on ${snippet ? `“${escapeHtml(snippet)}”` : 'the selected element'}</div>`
      : `<div class="target-label muted">General comment (no element)</div>`;
    byId('result').innerHTML = `<div class="item" style="display:block">${label}
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

  const addComment = () => {
    setTargeting(false);
    byId('panel').classList.add('open');
    openComment(null);
  };

  // True when focus is in any text-entry field, so single-key shortcuts don't hijack typing.
  // Inside our shadow the document's activeElement is the host; root.activeElement is the field.
  const isTyping = () => {
    const active = document.activeElement;
    if (active && (active.tagName === 'INPUT' || active.tagName === 'TEXTAREA' || active.isContentEditable)) {
      return true;
    }
    const shadowActive = root.activeElement;
    return !!shadowActive && (shadowActive.tagName === 'INPUT' || shadowActive.tagName === 'TEXTAREA');
  };

  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && targeting) {
      setTargeting(false);
      return;
    }
    // Single-key shortcuts only while the panel is open, no modifier held, and not typing.
    if (!byId('panel').classList.contains('open')) return;
    if (event.metaKey || event.ctrlKey || event.altKey || isTyping()) return;
    if (event.key === 'c') {
      event.preventDefault();
      addComment();
    } else if (event.key === 't') {
      event.preventDefault();
      setTargeting(!targeting);
    }
  });

  byId('toggle').addEventListener('click', () => byId('panel').classList.toggle('open'));
  byId('target').addEventListener('click', () => setTargeting(!targeting));
  byId('general').addEventListener('click', addComment);
  byId('clear').addEventListener('click', () => {
    pending = [];
    save(pending);
    refresh();
    byId('result').innerHTML = '';
  });

  const renderSendButton = (state) => {
    const sendButton = byId('send');
    if (state === 'loading') {
      sendButton.disabled = true;
      sendButton.classList.remove('success');
      sendButton.innerHTML = `<span class="spinner"></span>Sending…`;
    } else if (state === 'success') {
      sendButton.disabled = true;
      sendButton.classList.add('success');
      sendButton.innerHTML = `${ICON.check}Sent`;
    } else {
      sendButton.disabled = false;
      sendButton.classList.remove('success');
      sendButton.textContent = 'Send';
    }
  };

  byId('send').addEventListener('click', async () => {
    if (!pending.length) return;
    renderSendButton('loading');
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
      renderSendButton('success');
      setTimeout(() => renderSendButton('idle'), 2000);
      byId('result').innerHTML = `<div class="item" style="display:block">Sent. Batch id:<br><code>${escapeHtml(batchId)}</code>
        <button class="btn secondary" id="copy" style="margin-top:6px">Copy</button></div>`;
      const copyButton = byId('copy');
      copyButton.addEventListener('click', async () => {
        const copied = await copyToClipboard(batchId);
        if (copied) {
          copyButton.classList.add('success');
          copyButton.innerHTML = `${ICON.check}Copied`;
        } else {
          copyButton.classList.add('danger');
          copyButton.innerHTML = `${ICON.close}Copy failed`;
        }
        setTimeout(() => {
          copyButton.classList.remove('success', 'danger');
          copyButton.textContent = 'Copy';
        }, 1500);
      });
    } catch (error) {
      renderSendButton('idle');
      byId('result').innerHTML = `<div class="item error">Send failed: ${escapeHtml(error.message)}</div>`;
    }
  });

  // SPA / same-document navigation: pins are anchored to the URL they were made on,
  // so a client-side route change must rebuild them — otherwise stale pins linger and
  // appear to "follow" onto the new page. History methods are wrapped (and popstate /
  // hashchange listened to) to catch every same-document navigation across browsers.
  let lastSeenUrl = location.href;
  const handleLocationChange = () => {
    if (location.href === lastSeenUrl) return;
    lastSeenUrl = location.href;
    hideHighlight();
    hideTooltip();
    refresh();
  };
  ['pushState', 'replaceState'].forEach((method) => {
    const original = history[method];
    history[method] = function (...args) {
      const result = original.apply(this, args);
      handleLocationChange();
      return result;
    };
  });
  window.addEventListener('popstate', handleLocationChange);
  window.addEventListener('hashchange', handleLocationChange);

  refresh();
})();
