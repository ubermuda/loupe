(() => {
  // Idempotency guard. The widget's host elements are attached to <html>, so they
  // survive a Turbo (or any SPA) <body> swap; without this, every navigation that
  // re-executes the script tag would append another launcher/overlay and the
  // shadows would stack up. The flag lives on window, which persists across such
  // navigations, so only the first execution initializes.
  if (window.__betterplansSiteReviewLoaded) return;
  window.__betterplansSiteReviewLoaded = true;

  const script = document.currentScript;
  const BACKEND = new URL(script.src).origin;
  const TOKEN = script.getAttribute('data-token') || '';
  // Optional brand accent override; the design ships violet as the default.
  const ACCENT = script.getAttribute('data-accent') || '#6E56CF';
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

  // Comment bodies and anchor labels are arbitrary host-page text rendered into
  // innerHTML on a third-party page — every dynamic value MUST go through this.
  const escapeHtml = (value) =>
    String(value).replace(
      /[&<>"']/g,
      (character) =>
        ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[character],
    );

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

  // Build a stable-ish CSS selector for an element (used for re-anchoring on revisit).
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

  // Human-readable anchor: the first non-empty line of the element's visible text,
  // truncated. Returns '' when there's nothing readable — callers then show no chip
  // rather than a meaningless CSS selector.
  const firstLineLabel = (raw) => {
    const line =
      String(raw || '')
        .split('\n')
        .map((part) => part.trim())
        .find((part) => part.length > 0) || '';
    if (!line) return '';
    return line.length > 44 ? line.slice(0, 43).trim() + '…' : line;
  };

  // --- Design tokens: two complete light/dark maps; the accent variants are derived. ---
  const LIGHT = {
    '--page-bg': '#f5f5f8',
    '--card-bg': '#ffffff',
    '--card-border': '#eaeaf0',
    '--card-soft': '#f6f6f9',
    '--text': '#1b1b22',
    '--muted': '#6b6b77',
    '--faint': '#9a9aa6',
    '--panel-bg': '#ffffff',
    '--panel-border': '#e7e7ee',
    '--panel-elev': '#f9f9fc',
    '--hairline': '#eeeef3',
    '--chip-bg': '#f1f1f6',
    '--chip-text': '#5a5a66',
    '--field-bg': '#ffffff',
    '--field-border': '#dcdce4',
    '--shadow': '0 14px 34px -12px rgba(24,24,40,.24),0 3px 8px rgba(24,24,40,.06)',
    '--launch-shadow': '0 8px 24px -6px rgba(24,24,40,.22),0 2px 5px rgba(24,24,40,.08)',
    '--scrim': 'rgba(20,20,28,.30)',
    '--tooltip-bg': '#1c1c24',
    '--tooltip-text': '#ffffff',
    '--success': '#1f9d57',
    '--danger': '#e5484d',
  };
  const DARK = {
    '--page-bg': '#0e0e11',
    '--card-bg': '#161619',
    '--card-border': '#26262d',
    '--card-soft': '#1c1c21',
    '--text': '#f1f1f4',
    '--muted': '#9b9ba6',
    '--faint': '#67676f',
    '--panel-bg': '#161619',
    '--panel-border': '#2a2a32',
    '--panel-elev': '#1d1d22',
    '--hairline': '#26262d',
    '--chip-bg': '#222229',
    '--chip-text': '#b6b6c0',
    '--field-bg': '#1a1a1f',
    '--field-border': '#33333c',
    '--shadow': '0 20px 48px -14px rgba(0,0,0,.7),0 0 0 1px rgba(255,255,255,.04)',
    '--launch-shadow': '0 10px 28px -8px rgba(0,0,0,.65),0 0 0 1px rgba(255,255,255,.05)',
    '--scrim': 'rgba(0,0,0,.5)',
    '--tooltip-bg': '#2a2a33',
    '--tooltip-text': '#f2f2f4',
    '--success': '#34c77b',
    '--danger': '#ec5d62',
  };
  const hexRgb = (hex) => {
    let h = hex.replace('#', '');
    if (h.length === 3)
      h = h
        .split('')
        .map((c) => c + c)
        .join('');
    return [parseInt(h.slice(0, 2), 16), parseInt(h.slice(2, 4), 16), parseInt(h.slice(4, 6), 16)];
  };
  const rgba = (hex, alpha) => {
    const [r, g, b] = hexRgb(hex);
    return `rgba(${r},${g},${b},${alpha})`;
  };
  const darken = (hex, amount) => {
    const [r, g, b] = hexRgb(hex);
    const f = (value) => Math.max(0, Math.round(value * (1 - amount)));
    return `#${[f(r), f(g), f(b)].map((value) => value.toString(16).padStart(2, '0')).join('')}`;
  };

  // Inline SVG icons — this widget is embedded on third-party sites with no access
  // to the app's icon bundle, so the markup must be self-contained.
  const svg = (size, body, stroke) =>
    `<svg width="${size}" height="${size}" viewBox="0 0 24 24" fill="none" stroke="currentColor" ` +
    `stroke-width="${stroke || 2}" stroke-linecap="round" stroke-linejoin="round">${body}</svg>`;
  const ICON = {
    comment: (s) =>
      svg(
        s,
        '<path d="M21 11.5a8.4 8.4 0 0 1-8.5 8.5 8.4 8.4 0 0 1-3.8-.9L3 21l1.9-5.7A8.4 8.4 0 0 1 4 11.5 8.5 8.5 0 0 1 12.5 3 8.5 8.5 0 0 1 21 11.5z"/>',
        1.9,
      ),
    target: (s) =>
      svg(
        s,
        '<circle cx="12" cy="12" r="8"/><line x1="12" y1="2" x2="12" y2="5"/><line x1="12" y1="19" x2="12" y2="22"/><line x1="2" y1="12" x2="5" y2="12"/><line x1="19" y1="12" x2="22" y2="12"/>',
        1.9,
      ),
    close: (s) =>
      svg(s, '<line x1="6" y1="6" x2="18" y2="18"/><line x1="18" y1="6" x2="6" y2="18"/>', 2),
    chevron: (s) => svg(s, '<polyline points="6 9 12 15 18 9"/>', 2.2),
    send: (s) =>
      svg(s, '<line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/>', 2),
    trash: (s) =>
      svg(
        s,
        '<path d="M3 6h18"/><path d="M8 6V4a1 1 0 0 1 1-1h6a1 1 0 0 1 1 1v2"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/>',
        1.9,
      ),
    check: (s, stroke, color) =>
      `<svg width="${s}" height="${s}" viewBox="0 0 24 24" fill="none" stroke="${color || 'currentColor'}" ` +
      `stroke-width="${stroke || 2.4}" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>`,
    copy: (s) =>
      svg(
        s,
        '<rect x="9" y="9" width="11" height="11" rx="2.5"/><path d="M5 15H4.5A2.5 2.5 0 0 1 2 12.5v-8A2.5 2.5 0 0 1 4.5 2h8A2.5 2.5 0 0 1 15 4.5V5"/>',
        2,
      ),
    edit: (s) =>
      svg(
        s,
        '<path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4 12.5-12.5z"/>',
        1.9,
      ),
    glyph: (s) =>
      `<svg width="${s}" height="${s}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" ` +
      `stroke-linecap="round"><circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="2.4" fill="currentColor" stroke="none"/></svg>`,
  };

  // ---- widget state (the pending batch lives in `pending`; this is UI state) ----
  // Comments are identified by their index in `pending`; every mutation re-renders.
  const state = {
    open: false,
    target: false,
    composing: false,
    composeTarget: null, // { type:'general' } | { type:'element', el, selector, text, label }
    draft: '',
    listExpanded: false,
    expandLevel: 0,
    moveHL: null, // { left, top, width, height, label } while picking
    hoverId: null, // hovered comment index (list row)
    hoverPinId: null, // hovered pin index
    confirmDeleteId: null, // armed inline list-row delete
    confirmClear: false,
    pinConfirmId: null, // armed pin-popover delete
    sending: false,
    sent: null, // batch id string after a successful send
    sendError: null,
    copied: false,
    copyFailed: false,
  };
  let moveBase = null; // deepest element under the cursor while picking
  let pinCloseTimer = 0;
  let copyTimer = 0;

  // --- Shadow-DOM UI host (launcher + panel), isolated from host-page CSS. ---
  const host = document.createElement('div');
  host.style.cssText = 'position:fixed;inset:0;pointer-events:none;z-index:2147483647';
  const root = host.attachShadow({ mode: 'open' });
  document.documentElement.appendChild(host);

  // --- On-screen overlay (scrim, highlight, pins, instruction toast) in its own
  // shadow host so the host page's stylesheet can't bleed into the markers. ---
  const overlayHost = document.createElement('div');
  overlayHost.style.cssText = 'position:fixed;inset:0;pointer-events:none;z-index:2147483646';
  const overlayRoot = overlayHost.attachShadow({ mode: 'open' });
  document.documentElement.appendChild(overlayHost);

  // Force a crosshair across the whole page while picking (host elements may set
  // their own cursor; a global rule wins).
  const cursorStyle = document.createElement('style');
  document.documentElement.appendChild(cursorStyle);

  root.innerHTML = `
    <style>
      *{box-sizing:border-box}
      :host{all:initial}
      @keyframes bp-spin{to{transform:rotate(360deg)}}
      @keyframes bp-pop{from{transform:translateY(8px) scale(.985)}to{transform:none}}
      @keyframes bp-slide-left{from{transform:translateX(-100%)}to{transform:translateX(0)}}
      @keyframes bp-slide-left-out{from{transform:translateX(0)}to{transform:translateX(-100%)}}
      .bp-scroll::-webkit-scrollbar{width:10px;height:10px}
      .bp-scroll::-webkit-scrollbar-thumb{background:var(--faint);border-radius:9px;border:3px solid transparent;background-clip:content-box}
      .bp-scroll::-webkit-scrollbar-track{background:transparent}

      .bp-launcher{position:fixed;right:20px;bottom:20px;height:46px;padding:0 16px 0 13px;display:flex;align-items:center;gap:10px;background:var(--panel-bg);border:1px solid var(--panel-border);border-radius:23px;box-shadow:var(--launch-shadow);color:var(--text);font-family:'Geist',system-ui,-apple-system,sans-serif;font-size:13.5px;font-weight:550;cursor:pointer;pointer-events:auto;transition:transform .14s ease,box-shadow .14s ease,background .25s ease}
      .bp-launcher:hover{transform:translateY(-1px)}
      .bp-launch-icon{width:26px;height:26px;border-radius:8px;background:var(--accent-soft);color:var(--accent);display:flex;align-items:center;justify-content:center}
      .bp-count{display:inline-flex;align-items:center;justify-content:center;min-width:20px;height:20px;padding:0 6px;border-radius:10px;font-size:11.5px;font-weight:600}
      .bp-count.solid{background:var(--accent);color:var(--on-accent)}
      .bp-count.soft{background:var(--accent-soft);color:var(--accent)}

      .bp-panel{position:fixed;right:20px;bottom:78px;width:348px;max-height:calc(100vh - 160px);display:flex;flex-direction:column;background:var(--panel-bg);border:1px solid var(--panel-border);border-radius:17px;box-shadow:var(--shadow);pointer-events:auto;overflow:hidden;font-family:'Geist',system-ui,-apple-system,sans-serif;color:var(--text);animation:bp-pop .2s cubic-bezier(.2,.9,.3,1);transition:background .25s ease,border-color .25s ease}
      .bp-main{display:flex;flex-direction:column;min-height:0;flex:1 1 auto}
      .bp-header{flex:0 0 auto;display:flex;align-items:center;gap:9px;padding:14px 14px 12px 17px}
      .bp-title{font-size:15px;font-weight:600;letter-spacing:-.01em}
      .bp-spacer{flex:1}
      .bp-iconbtn{width:28px;height:28px;border:0;background:transparent;color:var(--muted);border-radius:7px;display:flex;align-items:center;justify-content:center;cursor:pointer}
      .bp-iconbtn:hover{background:var(--panel-elev);color:var(--text)}

      .bp-composer{flex:0 0 auto;overflow:hidden;transition:max-height .27s cubic-bezier(.4,0,.2,1),opacity .2s ease}
      .bp-composer-inner{padding:2px 16px 14px}
      .bp-compose-head{display:flex;align-items:center;gap:7px;margin-bottom:9px;min-height:21px}
      .bp-compose-general{display:inline-flex;align-items:center;gap:6px;font-size:11.5px;color:var(--muted)}
      .bp-dot{width:7px;height:7px;border-radius:50%;border:1.5px dashed var(--faint)}
      .bp-compose-chip{flex:0 1 auto;min-width:0;display:inline-flex;align-items:center;gap:5px;height:21px;padding:0 8px;background:var(--accent-soft);color:var(--accent);border-radius:6px;font-size:11px;font-weight:500;overflow:hidden}
      .bp-compose-chip span{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
      .bp-textarea{width:100%;min-height:74px;resize:none;border:1px solid var(--field-border);background:var(--field-bg);color:var(--text);border-radius:9px;padding:9px 10px;font-family:inherit;font-size:13px;line-height:1.5;outline:none}
      .bp-textarea:focus{border-color:var(--accent);box-shadow:0 0 0 3px var(--accent-soft)}
      .bp-textarea::placeholder{color:var(--faint)}
      .bp-compose-foot{display:flex;align-items:center;margin-top:9px}
      .bp-hint{font-size:11px;color:var(--faint)}
      .bp-mono{font-family:'Geist Mono',ui-monospace,monospace}
      .bp-ghost{height:30px;padding:0 11px;background:transparent;border:0;color:var(--muted);font-family:inherit;font-size:12.5px;font-weight:500;border-radius:8px;cursor:pointer}
      .bp-ghost:hover{background:var(--chip-bg);color:var(--text)}
      .bp-primary{height:30px;padding:0 13px;margin-left:4px;background:var(--accent);color:var(--on-accent);border:0;border-radius:8px;font-family:inherit;font-size:12.5px;font-weight:500;cursor:pointer}

      .bp-actions{flex:0 0 auto;display:flex;gap:8px;padding:0 14px 12px}
      .bp-action{flex:1;height:38px;display:flex;align-items:center;justify-content:center;gap:7px;border-radius:10px;font-family:inherit;font-size:13px;font-weight:500;cursor:pointer;border:1px solid var(--field-border);background:var(--panel-elev);color:var(--text);transition:background .15s ease,border-color .15s ease,color .15s ease}
      .bp-action:hover{border-color:var(--faint)}
      .bp-action.active{background:var(--accent-soft);color:var(--accent);border-color:var(--accent)}
      .bp-kbd{font-family:'Geist Mono',ui-monospace,monospace;font-size:10px;line-height:1;padding:2px 4px;border-radius:4px;background:var(--chip-bg);color:var(--chip-text)}

      .bp-error{margin:0 14px 10px;padding:9px 11px;display:flex;align-items:center;gap:8px;background:color-mix(in srgb,var(--danger) 12%,transparent);border:1px solid color-mix(in srgb,var(--danger) 34%,transparent);border-radius:9px;font-size:12px;color:var(--danger)}
      .bp-error button{margin-left:auto;background:transparent;border:0;color:var(--danger);font-family:inherit;font-size:12px;font-weight:600;cursor:pointer;border-radius:6px;padding:3px 6px}
      .bp-error button:hover{background:color-mix(in srgb,var(--danger) 14%,transparent)}

      .bp-empty{flex:1;display:flex;flex-direction:column;align-items:center;justify-content:center;text-align:center;padding:22px 26px 28px;gap:4px}
      .bp-empty-icon{width:42px;height:42px;border-radius:12px;background:var(--panel-elev);border:1px solid var(--hairline);display:flex;align-items:center;justify-content:center;color:var(--faint);margin-bottom:8px}
      .bp-empty-title{font-size:13.5px;font-weight:550;color:var(--text)}
      .bp-empty-sub{font-size:12.5px;color:var(--muted);line-height:1.5;max-width:210px}

      .bp-list-wrap{flex:0 1 auto;display:flex;flex-direction:column;min-height:0}
      .bp-list-anim{overflow:hidden;transition:max-height .3s cubic-bezier(.4,0,.2,1),opacity .2s ease}
      .bp-list{max-height:248px;overflow:auto;border-top:1px solid var(--hairline)}
      .bp-item{position:relative;overflow:hidden;display:flex;gap:11px;padding:12px 15px;border-bottom:1px solid var(--hairline);cursor:default}
      .bp-item:hover{background:var(--panel-elev)}
      .bp-item-confirm{position:absolute;inset:0;display:flex;align-items:center;gap:8px;padding:0 15px;background:var(--panel-bg);animation:bp-slide-left .18s cubic-bezier(.4,0,.2,1)}
      .bp-item-confirm-text{flex:1;font-size:12px;color:var(--text);font-weight:500}
      .bp-badge{flex:0 0 auto;width:22px;height:22px;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:600}
      .bp-badge.element{border-radius:50% 50% 50% 2px;background:var(--accent);color:var(--on-accent)}
      .bp-badge.general{border-radius:50%;border:1.5px dashed var(--faint);color:var(--faint)}
      .bp-item-body{flex:1;min-width:0}
      .bp-item-text{font-size:13px;line-height:1.5;color:var(--text);word-break:break-word}
      .bp-chip{display:inline-flex;align-items:center;gap:4px;margin-top:6px;height:19px;padding:0 7px;background:var(--chip-bg);color:var(--chip-text);border-radius:5px;font-size:10.5px;max-width:100%;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
      .bp-del{flex:0 0 auto;width:24px;height:24px;border:0;background:transparent;color:var(--faint);border-radius:6px;display:flex;align-items:center;justify-content:center;cursor:pointer;opacity:.55;transition:opacity .12s ease}
      .bp-del:hover{opacity:1;background:var(--chip-bg);color:var(--danger)}
      .bp-danger-sm{flex:0 0 auto;height:25px;padding:0 9px;background:var(--danger);color:#fff;border:0;border-radius:6px;font-family:inherit;font-size:11.5px;font-weight:600;cursor:pointer}
      .bp-ghost-sm{height:25px;padding:0 7px;background:transparent;border:0;color:var(--muted);font-family:inherit;font-size:11.5px;font-weight:500;border-radius:6px;cursor:pointer}
      .bp-ghost-sm:hover{background:var(--chip-bg);color:var(--text)}

      .bp-footer{flex:0 0 auto;align-items:center;gap:8px;padding:11px 14px;border-top:1px solid var(--hairline);background:var(--panel-bg)}
      .bp-footer-row{display:flex;align-items:center;gap:8px;width:100%}
      .bp-list-toggle{display:flex;align-items:center;gap:6px;height:32px;padding:0 9px 0 8px;background:transparent;border:0;cursor:pointer;color:var(--muted);font-family:inherit;font-size:12px;font-weight:550;border-radius:8px}
      .bp-list-toggle:hover{background:var(--chip-bg);color:var(--text)}
      .bp-chev{transition:transform .25s ease}
      .bp-clear{height:32px;padding:0 11px;background:transparent;border:0;color:var(--muted);font-family:inherit;font-size:12.5px;font-weight:500;border-radius:8px;cursor:pointer}
      .bp-clear:hover{background:var(--chip-bg);color:var(--danger)}
      .bp-send{height:32px;padding:0 15px;display:flex;align-items:center;gap:7px;background:var(--accent);color:var(--on-accent);border:0;border-radius:8px;font-family:inherit;font-size:12.5px;font-weight:600;cursor:pointer}
      .bp-send[disabled]{opacity:.55;cursor:default}
      .bp-confirm-text{font-size:12px;color:var(--text);font-weight:500}
      .bp-clear-cancel{height:32px;padding:0 11px;background:transparent;border:0;color:var(--muted);font-family:inherit;font-size:12.5px;font-weight:500;border-radius:8px;cursor:pointer}
      .bp-clear-cancel:hover{background:var(--chip-bg);color:var(--text)}
      .bp-clear-yes{height:32px;padding:0 13px;background:var(--danger);color:#fff;border:0;border-radius:8px;font-family:inherit;font-size:12.5px;font-weight:600;cursor:pointer}
      .bp-spin{width:13px;height:13px;border:2px solid currentColor;border-right-color:transparent;border-radius:50%;display:inline-block;animation:bp-spin .6s linear infinite}

      .bp-sent{padding:6px 18px 20px;text-align:center;animation:bp-pop .2s ease}
      .bp-sent-disc{width:46px;height:46px;margin:8px auto 12px;border-radius:50%;background:color-mix(in srgb,var(--success) 16%,transparent);display:flex;align-items:center;justify-content:center;color:var(--success)}
      .bp-sent-title{font-size:14.5px;font-weight:600}
      .bp-sent-sub{font-size:12.5px;color:var(--muted);margin-top:3px}
      .bp-batch{display:flex;align-items:center;gap:8px;margin:16px 0 4px;padding:9px 11px;background:var(--panel-elev);border:1px solid var(--hairline);border-radius:10px}
      .bp-batch-label{font-size:11px;color:var(--faint);flex:0 0 auto}
      .bp-batch-id{flex:1;font-family:'Geist Mono',ui-monospace,monospace;font-size:11.5px;color:var(--text);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;text-align:left}
      .bp-copy{flex:0 0 auto;height:26px;padding:0 9px;display:flex;align-items:center;gap:5px;background:var(--card-bg);border:1px solid var(--field-border);border-radius:7px;color:var(--muted);font-family:inherit;font-size:11.5px;font-weight:500;cursor:pointer}
      .bp-copy:hover{color:var(--text)}
      .bp-new{margin-top:10px;height:34px;width:100%;background:var(--panel-elev);border:1px solid var(--field-border);border-radius:9px;color:var(--text);font-family:inherit;font-size:13px;font-weight:500;cursor:pointer}
      .bp-new:hover{border-color:var(--accent)}
    </style>
    <button class="bp-launcher" id="bp-launcher" aria-label="Review" title="Review">
      <span class="bp-launch-icon">${ICON.comment(15)}</span>
      <span>Review</span>
      <span class="bp-count solid" id="bp-launch-count" style="display:none">0</span>
    </button>
    <div class="bp-panel" id="bp-panel" style="display:none">
      <div class="bp-header">
        <span class="bp-title">Review</span>
        <span class="bp-count soft" id="bp-head-count" style="display:none">0</span>
        <div class="bp-spacer"></div>
        <button class="bp-iconbtn" id="bp-close" aria-label="Close">${ICON.close(15)}</button>
      </div>
      <div class="bp-main" id="bp-main">
        <div class="bp-composer" id="bp-composer" style="max-height:0;opacity:0;pointer-events:none">
          <div style="overflow:hidden;min-height:0">
            <div class="bp-composer-inner">
              <div class="bp-compose-head" id="bp-compose-head"></div>
              <textarea class="bp-textarea" id="bp-textarea" placeholder="Describe the issue or idea…"></textarea>
              <div class="bp-compose-foot">
                <span class="bp-hint"><span class="bp-mono">⌘↵</span> to save</span>
                <div class="bp-spacer"></div>
                <button class="bp-ghost" id="bp-cancel">Cancel</button>
                <button class="bp-primary" id="bp-save">Save</button>
              </div>
            </div>
          </div>
        </div>
        <div class="bp-actions">
          <button class="bp-action" id="general" aria-pressed="false">${ICON.comment(15)}<span>Add note</span><span class="bp-kbd" aria-hidden="true">C</span></button>
          <button class="bp-action" id="target" aria-pressed="false">${ICON.target(15)}<span>Pick element</span><span class="bp-kbd" aria-hidden="true">T</span></button>
        </div>
        <div class="bp-error" id="bp-error" style="display:none"></div>
        <div id="bp-body">
          <div class="bp-empty" id="bp-empty" style="display:none">
            <div class="bp-empty-icon">${ICON.comment(20)}</div>
            <div class="bp-empty-title">No comments yet</div>
            <div class="bp-empty-sub">Add a note, or pick an element on the page to anchor your feedback.</div>
          </div>
          <div class="bp-list-wrap" id="bp-list-wrap" style="display:none">
            <div class="bp-list-anim" id="bp-list-anim" style="max-height:0;opacity:0">
              <div style="overflow:hidden;min-height:0">
                <div class="bp-list bp-scroll" id="bp-list"></div>
              </div>
            </div>
          </div>
        </div>
        <div class="bp-footer" id="bp-footer" style="display:none">
          <div class="bp-footer-row" id="bp-footer-main">
            <button class="bp-list-toggle" id="bp-list-toggle">
              <span class="bp-chev" id="bp-chev" style="display:inline-flex">${ICON.chevron(13)}</span>
              <span id="bp-list-toggle-text">Show comments</span>
            </button>
            <div class="bp-spacer"></div>
            <button class="bp-clear" id="bp-clear">Clear</button>
            <button class="bp-send" id="bp-send">${ICON.send(14)}Send</button>
          </div>
          <div class="bp-footer-row" id="bp-footer-confirm" style="display:none">
            <span class="bp-confirm-text" id="bp-clear-confirm-text"></span>
            <div class="bp-spacer"></div>
            <button class="bp-clear-cancel" id="bp-clear-cancel">Cancel</button>
            <button class="bp-clear-yes" id="bp-clear-yes">Clear all</button>
          </div>
        </div>
      </div>
      <div id="bp-sent" style="display:none"></div>
    </div>`;

  overlayRoot.innerHTML = `
    <style>
      *{box-sizing:border-box}
      :host{all:initial}
      @keyframes bp-fade{from{opacity:0}to{opacity:1}}
      @keyframes bp-slide-left{from{transform:translateX(-100%)}to{transform:translateX(0)}}
      @keyframes bp-slide-left-out{from{transform:translateX(0)}to{transform:translateX(-100%)}}
      @keyframes bp-pin{from{transform:scale(.4)}to{transform:scale(1)}}
      @keyframes bp-pop{from{transform:translateY(8px) scale(.985)}to{transform:none}}
      @keyframes bp-spin{to{transform:rotate(360deg)}}
      .bp-ov{font-family:'Geist',system-ui,-apple-system,sans-serif}
      .bp-scrim{position:fixed;inset:0;background:var(--scrim);animation:bp-fade .18s ease;pointer-events:none}
      .highlight{position:fixed;border:2px solid var(--accent);background:var(--accent-fill);border-radius:9px;pointer-events:none;box-shadow:0 0 0 4px var(--accent-soft);transition:left .07s ease,top .07s ease,width .07s ease,height .07s ease;z-index:2}
      .bp-hl-label{position:absolute;left:-2px;top:-25px;display:inline-flex;align-items:center;max-width:240px;height:21px;padding:0 8px;background:var(--accent);color:var(--on-accent);font-size:11px;font-weight:500;border-radius:6px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
      .bp-pin-wrap{position:fixed;z-index:4;pointer-events:auto}
      .bp-ov.targeting .bp-pin-wrap{pointer-events:none}
      .pin{width:24px;height:24px;border-radius:50% 50% 50% 2px;border:2px solid var(--page-bg);background:var(--accent);color:var(--on-accent);font-family:inherit;font-size:12px;font-weight:600;display:flex;align-items:center;justify-content:center;cursor:pointer;box-shadow:0 2px 8px rgba(24,24,40,.3);animation:bp-pin .22s cubic-bezier(.2,1.3,.5,1)}
      .pin:hover{transform:scale(1.12)}
      .bp-pop{position:absolute;top:16px;right:0;width:240px;padding-top:14px;cursor:default}
      .bp-pop-card{position:relative;overflow:hidden;min-height:96px;padding:12px;background:var(--panel-bg);border:1px solid var(--panel-border);border-radius:12px;box-shadow:var(--shadow);animation:bp-fade .12s ease;display:flex;flex-direction:column}
      .bp-pop-body{font-size:12.5px;line-height:1.5;color:var(--text);word-break:break-word}
      .bp-pop-row{display:flex;align-items:center;gap:8px;margin-top:auto;padding-top:10px}
      .bp-pop-chip{display:inline-flex;align-items:center;height:19px;padding:0 7px;background:var(--chip-bg);color:var(--chip-text);border-radius:5px;font-size:10.5px;max-width:150px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
      .bp-pop-del{flex:0 0 auto;width:24px;height:24px;border:0;background:transparent;color:var(--faint);border-radius:6px;display:flex;align-items:center;justify-content:center;cursor:pointer;opacity:.6;transition:opacity .12s ease}
      .bp-pop-del:hover{opacity:1;background:var(--chip-bg);color:var(--danger)}
      .bp-pop-confirm{position:absolute;inset:0;background:var(--panel-bg);border-radius:12px;padding:12px;display:flex;flex-direction:column;justify-content:center;animation:bp-slide-left .18s cubic-bezier(.4,0,.2,1)}
      .bp-pop-confirm-title{font-size:12.5px;font-weight:600;color:var(--text)}
      .bp-pop-confirm-sub{font-size:11.5px;color:var(--muted);margin-top:3px;line-height:1.45}
      .bp-pop-confirm-row{display:flex;gap:7px;margin-top:11px}
      .bp-pop-yes{flex:1;height:30px;background:var(--danger);color:#fff;border:0;border-radius:8px;font-family:inherit;font-size:12px;font-weight:600;cursor:pointer}
      .bp-pop-no{flex:1;height:30px;background:var(--panel-elev);border:1px solid var(--field-border);color:var(--text);border-radius:8px;font-family:inherit;font-size:12px;font-weight:500;cursor:pointer}
      .bp-toast{position:fixed;top:18px;left:50%;transform:translateX(-50%);z-index:6;display:flex;align-items:center;gap:10px;padding:9px 12px 9px 14px;background:var(--tooltip-bg);color:var(--tooltip-text);border-radius:11px;font-size:13px;font-weight:500;box-shadow:0 8px 26px rgba(0,0,0,.34);animation:bp-pop .2s ease;pointer-events:auto}
      .bp-toast-sep{opacity:.5}
      .bp-toast-dim{opacity:.65;font-size:12px}
      .bp-toast-key{margin-left:2px;padding:3px 7px;background:rgba(255,255,255,.16);border-radius:6px;font-size:11px;font-weight:500;cursor:pointer}
    </style>
    <div class="bp-ov" id="bp-ov">
      <div class="bp-scrim" id="bp-scrim" style="display:none"></div>
      <div class="highlight" id="bp-hl" style="display:none"><span class="bp-hl-label" id="bp-hl-label" style="display:none"></span></div>
      <div id="bp-pins"></div>
      <div class="bp-toast" id="bp-toast" style="display:none">
        ${ICON.target(15)}
        <span>Click to comment</span>
        <span class="bp-toast-sep">·</span>
        <span class="bp-toast-dim">⌥ scroll to resize</span>
        <span class="bp-toast-key" id="bp-toast-esc">Esc</span>
      </div>
    </div>`;

  const $ = (id) => root.getElementById(id);
  const $$ = (id) => overlayRoot.getElementById(id);

  const ovRoot = $$('bp-ov');
  const scrimNode = $$('bp-scrim');
  const hlNode = $$('bp-hl');
  const hlLabel = $$('bp-hl-label');
  const pinsNode = $$('bp-pins');
  const toastNode = $$('bp-toast');
  const panelNode = $('bp-panel');
  const mainNode = $('bp-main');
  const sentNode = $('bp-sent');
  const composerNode = $('bp-composer');
  const composeHead = $('bp-compose-head');
  const textareaNode = $('bp-textarea');
  const errorNode = $('bp-error');
  const emptyNode = $('bp-empty');
  const listWrap = $('bp-list-wrap');
  const listAnim = $('bp-list-anim');
  const listNode = $('bp-list');
  const footerNode = $('bp-footer');
  const footerMain = $('bp-footer-main');
  const footerConfirm = $('bp-footer-confirm');
  const sendBtn = $('bp-send');
  const generalBtn = $('general');
  const targetBtn = $('target');

  // --- theming: follow the host's color scheme and live-update on change. ---
  const applyTheme = (dark) => {
    const base = dark ? DARK : LIGHT;
    [host, overlayHost].forEach((node) => {
      Object.entries(base).forEach(([key, value]) => node.style.setProperty(key, value));
      node.style.setProperty('--accent', ACCENT);
      node.style.setProperty('--accent-press', darken(ACCENT, 0.12));
      node.style.setProperty('--accent-fill', rgba(ACCENT, dark ? 0.18 : 0.12));
      node.style.setProperty('--accent-soft', rgba(ACCENT, dark ? 0.2 : 0.11));
      node.style.setProperty('--on-accent', '#ffffff');
    });
  };
  const colorScheme = window.matchMedia('(prefers-color-scheme: dark)');
  applyTheme(colorScheme.matches);
  colorScheme.addEventListener('change', (event) => applyTheme(event.matches));

  // ---- geometry helpers (viewport-relative; the overlay host is fixed full-screen) ----
  const insideWidget = (el) => host.contains(el) || overlayHost.contains(el);
  const pickEl = (event) => {
    const el = document.elementFromPoint(event.clientX, event.clientY);
    if (!el || el === host || el === overlayHost || insideWidget(el)) return null;
    if (el === document.body || el === document.documentElement) return null;
    return el;
  };
  const climbUp = (el, level) => {
    let current = el;
    for (let i = 0; i < level; i++) {
      const parent = current.parentElement;
      if (!parent || parent === document.body || parent === document.documentElement || insideWidget(parent)) {
        break;
      }
      current = parent;
    }
    return current;
  };
  const rectOf = (el) => {
    const r = el.getBoundingClientRect();
    if (r.width === 0 && r.height === 0) return null;
    return r;
  };

  // ---- overlay render (scrim / highlight / pins / toast) ----
  const updateHighlight = () => {
    let hl = null;
    if (state.target && state.moveHL) {
      hl = state.moveHL;
    } else if (
      state.composing &&
      state.composeTarget &&
      state.composeTarget.type === 'element' &&
      state.composeTarget.el
    ) {
      const r = rectOf(state.composeTarget.el);
      if (r) hl = { left: r.left, top: r.top, width: r.width, height: r.height, label: state.composeTarget.label };
    } else {
      const index = state.hoverPinId != null ? state.hoverPinId : state.hoverId;
      if (index != null) {
        const comment = pending[index];
        const el = comment && resolveElement(comment);
        const r = el && rectOf(el);
        if (r) hl = { left: r.left, top: r.top, width: r.width, height: r.height, label: null };
      }
    }
    if (!hl) {
      hlNode.style.display = 'none';
      return;
    }
    hlNode.style.display = 'block';
    hlNode.style.left = hl.left + 'px';
    hlNode.style.top = hl.top + 'px';
    hlNode.style.width = hl.width + 'px';
    hlNode.style.height = hl.height + 'px';
    if (hl.label) {
      hlLabel.style.display = 'inline-flex';
      hlLabel.textContent = hl.label;
    } else {
      hlLabel.style.display = 'none';
    }
  };

  // Slide a confirm overlay back out to the left, then remove it. Marked exiting so
  // repeated renders don't restart the animation or double-remove.
  const slideOut = (el) => {
    if (!el || el.dataset.exiting) return;
    el.dataset.exiting = '1';
    el.style.animation = 'bp-slide-left-out .18s cubic-bezier(.4,0,.2,1) forwards';
    el.addEventListener('animationend', () => el.remove(), { once: true });
  };

  // Pin nodes are reconciled (not rebuilt) so the bp-pin drop-in animation plays
  // once per pin; hovering and scrolling reposition existing nodes rather than
  // recreating them (recreating would replay the scale-in and make the pin shrink).
  const pinNodes = new Map();
  // The card (body + chip + delete) is built once on hover; the confirm overlay is
  // a separate node toggled in place, so arming/cancelling delete never rebuilds the
  // card (which would replay its fade-in and flicker).
  const buildPopover = (comment, index) => {
    const label = firstLineLabel(comment.text);
    return `<div class="bp-pop">
        <div class="bp-pop-card">
          <div class="bp-pop-body">${escapeHtml(comment.body)}</div>
          <div class="bp-pop-row">
            ${label ? `<span class="bp-pop-chip">${escapeHtml(label)}</span>` : ''}
            <div style="flex:1"></div>
            <button class="bp-pop-del" data-pin-del="${index}" aria-label="Delete">${ICON.trash(14)}</button>
          </div>
        </div>
      </div>`;
  };
  const buildConfirm = (index) =>
    `<div class="bp-pop-confirm">
       <div class="bp-pop-confirm-title">Delete this note?</div>
       <div class="bp-pop-confirm-sub">The pin will be removed from the page.</div>
       <div class="bp-pop-confirm-row">
         <button class="bp-pop-yes" data-pin-yes="${index}">Delete</button>
         <button class="bp-pop-no" data-pin-no="${index}">Cancel</button>
       </div>
     </div>`;
  const bindPopover = (holder, index) => {
    const del = holder.querySelector('[data-pin-del]');
    if (del)
      del.addEventListener('click', () => {
        state.pinConfirmId = index;
        renderPins();
      });
  };
  const bindConfirm = (el, index) => {
    const yes = el.querySelector('[data-pin-yes]');
    if (yes) yes.addEventListener('click', () => removeComment(index));
    const no = el.querySelector('[data-pin-no]');
    if (no)
      no.addEventListener('click', () => {
        state.pinConfirmId = null;
        renderPins();
      });
  };
  const renderPins = () => {
    ovRoot.classList.toggle('targeting', state.target);
    const wanted = new Map();
    pending.forEach((comment, index) => {
      const el = resolveElement(comment);
      if (!el) return;
      const r = rectOf(el);
      if (!r) return;
      const onScreen = !(
        r.bottom < 0 ||
        r.top > window.innerHeight ||
        r.right < 0 ||
        r.left > window.innerWidth
      );
      wanted.set(index, { comment, left: r.left + r.width - 12, top: r.top - 12, onScreen });
    });
    // Drop pins whose element is gone (deleted, or anchored to another page).
    pinNodes.forEach((wrap, index) => {
      if (!wanted.has(index)) {
        wrap.remove();
        pinNodes.delete(index);
      }
    });
    wanted.forEach((info, index) => {
      let wrap = pinNodes.get(index);
      if (!wrap) {
        wrap = document.createElement('div');
        wrap.className = 'bp-pin-wrap';
        wrap.innerHTML =
          `<button class="pin" style="animation:bp-pin .22s cubic-bezier(.2,1.3,.5,1)"></button>` +
          `<div class="bp-pop-holder"></div>`;
        wrap.querySelector('.pin').addEventListener('click', () => {
          state.open = true;
          state.listExpanded = true;
          sync();
        });
        wrap.addEventListener('mouseenter', () => hoverPin(index));
        wrap.addEventListener('mouseleave', unhoverPin);
        pinsNode.appendChild(wrap);
        pinNodes.set(index, wrap);
      }
      wrap.style.left = info.left + 'px';
      wrap.style.top = info.top + 'px';
      wrap.style.display = info.onScreen ? '' : 'none';
      wrap.querySelector('.pin').textContent = String(index + 1);
      // Build the card once on hover; toggle the confirm overlay in place. Neither
      // is rebuilt on scroll, so nothing re-animates while repositioning.
      const hovered = state.hoverPinId === index;
      const confirming = hovered && state.pinConfirmId === index;
      const holder = wrap.querySelector('.bp-pop-holder');
      if (!hovered) {
        if (holder.dataset.shown) {
          holder.innerHTML = '';
          delete holder.dataset.shown;
        }
      } else {
        if (!holder.dataset.shown) {
          holder.innerHTML = buildPopover(info.comment, index);
          holder.dataset.shown = '1';
          bindPopover(holder, index);
        }
        const card = holder.querySelector('.bp-pop-card');
        const liveConfirm = card.querySelector('.bp-pop-confirm:not([data-exiting])');
        if (confirming && !liveConfirm) {
          card.querySelectorAll('.bp-pop-confirm').forEach((node) => node.remove());
          card.insertAdjacentHTML('beforeend', buildConfirm(index));
          bindConfirm(card.querySelector('.bp-pop-confirm'), index);
        } else if (!confirming && liveConfirm) {
          slideOut(liveConfirm);
        }
      }
    });
  };

  const renderOverlay = () => {
    scrimNode.style.display = state.target ? 'block' : 'none';
    toastNode.style.display = state.target ? 'flex' : 'none';
    renderPins();
    updateHighlight();
  };

  // ---- panel render ----
  const updatePanel = () => {
    const n = pending.length;
    const launchCount = $('bp-launch-count');
    launchCount.style.display = n > 0 ? 'inline-flex' : 'none';
    launchCount.textContent = String(n);

    // While picking an element, hide the whole widget (launcher + panel) so it does
    // not obscure the page; the scrim + toast are the only pick-mode UI.
    $('bp-launcher').style.display = state.target ? 'none' : '';
    panelNode.style.display = state.open && !state.target ? 'flex' : 'none';
    if (!state.open || state.target) return;

    const sent = state.sent;
    const headCount = $('bp-head-count');
    headCount.style.display = n > 0 && !sent ? 'inline-flex' : 'none';
    headCount.textContent = String(n);

    mainNode.style.display = sent ? 'none' : 'flex';
    sentNode.style.display = sent ? 'block' : 'none';

    if (sent) {
      renderSent();
      return;
    }

    // composer
    composerNode.style.maxHeight = state.composing ? '240px' : '0px';
    composerNode.style.opacity = state.composing ? '1' : '0';
    composerNode.style.pointerEvents = state.composing ? 'auto' : 'none';
    if (state.composing) {
      const ct = state.composeTarget || { type: 'general' };
      composeHead.innerHTML =
        ct.type === 'general'
          ? `<span class="bp-compose-general"><span class="bp-dot"></span>General comment</span>`
          : `<span class="bp-compose-chip">${ICON.glyph(11)}<span>${escapeHtml(ct.label || 'Selected element')}</span></span>`;
    }

    // action buttons
    const noteActive = state.composing && (state.composeTarget || {}).type === 'general';
    generalBtn.classList.toggle('active', noteActive);
    generalBtn.setAttribute('aria-pressed', noteActive ? 'true' : 'false');
    targetBtn.classList.toggle('active', state.target);
    targetBtn.setAttribute('aria-pressed', state.target ? 'true' : 'false');

    // error banner
    if (state.sendError) {
      errorNode.style.display = 'flex';
      errorNode.innerHTML = `<span>Couldn’t send your review. Please try again.</span><button id="bp-retry">Try again</button>`;
      $('bp-retry').addEventListener('click', send);
    } else {
      errorNode.style.display = 'none';
    }

    // empty vs list
    emptyNode.style.display = n === 0 && !state.composing ? 'flex' : 'none';
    listWrap.style.display = n > 0 ? 'flex' : 'none';
    listAnim.style.maxHeight = state.listExpanded ? '260px' : '0px';
    listAnim.style.opacity = state.listExpanded ? '1' : '0';
    renderList();

    // footer
    footerNode.style.display = n > 0 ? 'flex' : 'none';
    if (n > 0) {
      footerMain.style.display = state.confirmClear ? 'none' : 'flex';
      footerConfirm.style.display = state.confirmClear ? 'flex' : 'none';
      $('bp-chev').style.transform = `rotate(${state.listExpanded ? '0deg' : '180deg'})`;
      $('bp-list-toggle-text').textContent = state.listExpanded
        ? 'Hide comments'
        : n === 1
          ? 'Show 1 comment'
          : `Show ${n} comments`;
      $('bp-clear-confirm-text').textContent =
        n === 1 ? 'Remove this comment?' : `Remove all ${n} comments?`;
      sendBtn.disabled = state.sending || n === 0;
      sendBtn.innerHTML = state.sending
        ? `<span class="bp-spin"></span>Sending…`
        : `${ICON.send(14)}Send`;
    }
  };

  // List rows are reconciled (not rebuilt) so arming/cancelling a delete only toggles
  // that row's confirm overlay in place — it slides in, and Cancel slides it back out
  // — without re-rendering the row (which would interrupt the animation).
  const rowNodes = new Map();
  const buildItemConfirm = (index) =>
    `<div class="bp-item-confirm">
       <span class="bp-item-confirm-text">Delete this comment?</span>
       <button class="bp-danger-sm" data-del-yes="${index}">Delete</button>
       <button class="bp-ghost-sm" data-del-no="${index}">Cancel</button>
     </div>`;
  const bindItemConfirm = (el, index) => {
    el.querySelector('[data-del-yes]').addEventListener('click', () => removeComment(index));
    el.querySelector('[data-del-no]').addEventListener('click', () => {
      state.confirmDeleteId = null;
      renderList();
    });
  };
  const renderList = () => {
    rowNodes.forEach((row, index) => {
      if (index >= pending.length) {
        row.remove();
        rowNodes.delete(index);
      }
    });
    pending.forEach((comment, index) => {
      let row = rowNodes.get(index);
      if (!row) {
        row = document.createElement('div');
        row.className = 'bp-item';
        row.innerHTML =
          `<span class="bp-badge"></span>` +
          `<div class="bp-item-body"><div class="bp-item-text"></div><span class="bp-chip" style="display:none"></span></div>` +
          `<button class="bp-del" aria-label="Delete comment">${ICON.trash(14)}</button>`;
        row.addEventListener('mouseenter', () => {
          if (state.target || !pending[index] || !pending[index].selector) return;
          state.hoverId = index;
          updateHighlight();
        });
        row.addEventListener('mouseleave', () => {
          state.hoverId = null;
          updateHighlight();
        });
        row.querySelector('.bp-del').addEventListener('click', () => {
          state.confirmDeleteId = index;
          renderList();
        });
        listNode.appendChild(row);
        rowNodes.set(index, row);
      }
      const isElement = !!comment.selector;
      const badge = row.querySelector('.bp-badge');
      badge.className = 'bp-badge ' + (isElement ? 'element' : 'general');
      badge.textContent = String(index + 1);
      row.querySelector('.bp-item-text').textContent = comment.body;
      const chipEl = row.querySelector('.bp-chip');
      const label = isElement ? firstLineLabel(comment.text) : '';
      const showChip = isElement ? !!label : true;
      chipEl.style.display = showChip ? '' : 'none';
      if (showChip) chipEl.textContent = isElement ? label : 'General comment';
      // Toggle the confirm overlay in place (slide in on arm, slide out on cancel).
      const confirming = state.confirmDeleteId === index;
      const liveConfirm = row.querySelector('.bp-item-confirm:not([data-exiting])');
      if (confirming && !liveConfirm) {
        row.querySelectorAll('.bp-item-confirm').forEach((node) => node.remove());
        row.insertAdjacentHTML('beforeend', buildItemConfirm(index));
        bindItemConfirm(row.querySelector('.bp-item-confirm'), index);
      } else if (!confirming && liveConfirm) {
        slideOut(liveConfirm);
      }
    });
  };

  const copyButtonLabel = () =>
    state.copied
      ? `${ICON.check(12, 2.6, 'var(--success)')}Copied`
      : state.copyFailed
        ? 'Copy failed'
        : `${ICON.copy(12)}Copy`;
  // Reconcile rather than rebuild: re-assigning sentNode.innerHTML recreates the
  // .bp-sent element and replays its entrance animation, so clicking Copy (which
  // re-renders) would flash the whole panel. Build the markup once per batch and only
  // swap the Copy button's label on subsequent renders.
  const renderSent = () => {
    if (sentNode.dataset.batch === state.sent) {
      const copyBtn = $('bp-copy');
      if (copyBtn) copyBtn.innerHTML = copyButtonLabel();
      return;
    }
    sentNode.dataset.batch = state.sent;
    sentNode.innerHTML = `<div class="bp-sent">
        <div class="bp-sent-disc">${ICON.check(22, 2.4)}</div>
        <div class="bp-sent-title">Review sent</div>
        <div class="bp-sent-sub">Your team will see it in the workspace.</div>
        <div class="bp-batch">
          <span class="bp-batch-label">Batch</span>
          <code class="bp-batch-id">${escapeHtml(state.sent)}</code>
          <button class="bp-copy" id="bp-copy">${copyButtonLabel()}</button>
        </div>
        <button class="bp-new" id="bp-new">Start a new review</button>
      </div>`;
    $('bp-copy').addEventListener('click', copyBatch);
    $('bp-new').addEventListener('click', dismissSent);
  };

  const sync = () => {
    updatePanel();
    renderOverlay();
  };

  // ---- composer / focus ----
  const focusTextarea = () => {
    const t = textareaNode;
    t.focus();
    const end = t.value.length;
    t.setSelectionRange(end, end);
  };
  const openNoteComposer = () => {
    state.composing = true;
    state.composeTarget = { type: 'general' };
    state.draft = '';
    textareaNode.value = '';
    state.open = true;
    setTargeting(false);
    sync();
    focusTextarea();
  };
  const toggleNote = () => {
    const ct = state.composeTarget || {};
    if (state.composing && ct.type === 'general') {
      state.composing = false;
      state.composeTarget = null;
      state.draft = '';
      textareaNode.value = '';
      sync();
    } else {
      openNoteComposer();
    }
  };
  const openElementComposer = (el) => {
    state.composing = true;
    state.composeTarget = {
      type: 'element',
      el,
      selector: selectorFor(el),
      text: (el.innerText || '').trim().slice(0, 200),
      label: firstLineLabel(el.innerText),
    };
    state.draft = '';
    textareaNode.value = '';
    state.open = true;
    setTargeting(false);
    sync();
    focusTextarea();
  };
  const cancelCompose = () => {
    state.composing = false;
    state.composeTarget = null;
    state.draft = '';
    textareaNode.value = '';
    sync();
  };
  const saveComment = () => {
    const body = state.draft.trim();
    if (!body) return;
    const ct = state.composeTarget || { type: 'general' };
    const comment =
      ct.type === 'element'
        ? { body, selector: ct.selector, text: ct.text, url: location.href }
        : { body, selector: '', text: '', url: location.href };
    pending.push(comment);
    save(pending);
    state.composing = false;
    state.composeTarget = null;
    state.draft = '';
    textareaNode.value = '';
    sync();
  };

  // ---- destructive actions (every one is confirmed) ----
  const removeComment = (index) => {
    pending.splice(index, 1);
    save(pending);
    state.confirmDeleteId = null;
    state.pinConfirmId = null;
    state.hoverId = null;
    state.hoverPinId = null;
    if (!pending.length) state.listExpanded = false;
    sync();
  };
  const armClear = () => {
    state.confirmClear = true;
    sync();
  };
  const cancelClear = () => {
    state.confirmClear = false;
    sync();
  };
  const confirmClearYes = () => {
    pending = [];
    save(pending);
    state.confirmClear = false;
    state.listExpanded = false;
    state.composing = false;
    state.composeTarget = null;
    state.hoverId = null;
    state.hoverPinId = null;
    state.confirmDeleteId = null;
    state.pinConfirmId = null;
    sync();
  };

  // ---- pin hover (intent: a 160ms close delay bridges the pin→popover gap) ----
  const hoverPin = (index) => {
    clearTimeout(pinCloseTimer);
    state.hoverPinId = index;
    renderPins();
    updateHighlight();
  };
  const unhoverPin = () => {
    clearTimeout(pinCloseTimer);
    pinCloseTimer = setTimeout(() => {
      state.hoverPinId = null;
      state.pinConfirmId = null;
      renderPins();
      updateHighlight();
    }, 160);
  };

  // ---- element picker (target mode) ----
  const setTargeting = (on) => {
    state.target = on;
    if (!on) {
      state.moveHL = null;
      state.expandLevel = 0;
      moveBase = null;
    }
    cursorStyle.textContent = on ? '*{cursor:crosshair !important}' : '';
    if (on) {
      document.addEventListener('mousemove', onMove, true);
      document.addEventListener('click', onClick, true);
      document.addEventListener('contextmenu', onContext, true);
      window.addEventListener('wheel', onWheel, { passive: false });
    } else {
      document.removeEventListener('mousemove', onMove, true);
      document.removeEventListener('click', onClick, true);
      document.removeEventListener('contextmenu', onContext, true);
      window.removeEventListener('wheel', onWheel);
    }
  };
  const toggleTarget = () => {
    const on = !state.target;
    if (on) {
      state.composing = false;
      state.composeTarget = null;
      state.draft = '';
      textareaNode.value = '';
      state.open = true;
    }
    setTargeting(on);
    sync();
  };
  const onMove = (event) => {
    if (!state.target) return;
    const base = pickEl(event);
    if (!base) {
      moveBase = null;
      if (state.moveHL) {
        state.moveHL = null;
        state.expandLevel = 0;
        updateHighlight();
      }
      return;
    }
    let level = state.expandLevel;
    if (base !== moveBase) {
      moveBase = base;
      level = 0;
    }
    const current = climbUp(base, level);
    const r = rectOf(current);
    if (!r) return;
    state.expandLevel = level;
    state.moveHL = {
      left: r.left,
      top: r.top,
      width: r.width,
      height: r.height,
      label: firstLineLabel(current.innerText),
    };
    updateHighlight();
  };
  const onClick = (event) => {
    if (!state.target) return;
    const base = pickEl(event);
    // A click that isn't on the host page (e.g. on the panel itself) is left alone.
    if (!base) return;
    event.preventDefault();
    event.stopPropagation();
    const current = climbUp(base, state.expandLevel);
    setTargeting(false);
    openElementComposer(current);
  };
  // Granularity: widen to parent (delta>0) or narrow back (delta<0) relative to the
  // element under the cursor. Clamped to stay inside the host content.
  const adjustLevel = (delta) => {
    if (!state.target || !moveBase) return;
    const level = state.expandLevel;
    let next;
    if (delta > 0) {
      const parent = climbUp(moveBase, level).parentElement;
      if (!parent || parent === document.body || parent === document.documentElement || insideWidget(parent)) {
        return;
      }
      next = level + 1;
    } else {
      if (level === 0) return;
      next = level - 1;
    }
    const current = climbUp(moveBase, next);
    const r = rectOf(current);
    state.expandLevel = next;
    if (r) {
      state.moveHL = {
        left: r.left,
        top: r.top,
        width: r.width,
        height: r.height,
        label: firstLineLabel(current.innerText),
      };
    }
    updateHighlight();
  };
  // Alt/⌥ + wheel resizes the selection; a plain wheel still scrolls the host page,
  // so picking never traps scrolling on long pages.
  const onWheel = (event) => {
    if (!state.target || !moveBase || !event.altKey) return;
    event.preventDefault();
    adjustLevel(event.deltaY < 0 ? 1 : -1);
  };
  const onContext = (event) => {
    if (!state.target) return;
    event.preventDefault();
    setTargeting(false);
    sync();
  };

  // ---- send ----
  const send = async () => {
    if (!pending.length || state.sending) return;
    state.sending = true;
    state.sendError = null;
    updatePanel();
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
      setTargeting(false);
      Object.assign(state, {
        sending: false,
        sent: batchId,
        sendError: null,
        composing: false,
        composeTarget: null,
        draft: '',
        listExpanded: false,
        confirmClear: false,
        confirmDeleteId: null,
        pinConfirmId: null,
        hoverId: null,
        hoverPinId: null,
        moveHL: null,
      });
      textareaNode.value = '';
      sync();
    } catch (error) {
      // Keep the pending batch intact so the reviewer can retry.
      state.sending = false;
      state.sendError = error && error.message ? error.message : 'Send failed';
      updatePanel();
    }
  };
  const copyBatch = async () => {
    const ok = await copyToClipboard(state.sent);
    state.copied = ok;
    state.copyFailed = !ok;
    updatePanel();
    clearTimeout(copyTimer);
    copyTimer = setTimeout(() => {
      state.copied = false;
      state.copyFailed = false;
      updatePanel();
    }, 1500);
  };
  const dismissSent = () => {
    state.sent = null;
    state.copied = false;
    state.copyFailed = false;
    state.listExpanded = false;
    sync();
  };

  // ---- panel open/close ----
  const togglePanel = () => {
    state.open = !state.open;
    if (!state.open) {
      setTargeting(false);
      state.composing = false;
      state.composeTarget = null;
      state.draft = '';
      textareaNode.value = '';
    }
    sync();
  };

  // ---- static bindings (persistent nodes) ----
  $('bp-launcher').addEventListener('click', togglePanel);
  $('bp-close').addEventListener('click', togglePanel);
  generalBtn.addEventListener('click', toggleNote);
  targetBtn.addEventListener('click', toggleTarget);
  $('bp-cancel').addEventListener('click', cancelCompose);
  $('bp-save').addEventListener('click', saveComment);
  $('bp-list-toggle').addEventListener('click', () => {
    state.listExpanded = !state.listExpanded;
    sync();
  });
  $('bp-clear').addEventListener('click', armClear);
  $('bp-clear-cancel').addEventListener('click', cancelClear);
  $('bp-clear-yes').addEventListener('click', confirmClearYes);
  sendBtn.addEventListener('click', send);
  $$('bp-toast-esc').addEventListener('click', () => {
    setTargeting(false);
    sync();
  });
  textareaNode.addEventListener('input', (event) => {
    state.draft = event.target.value;
  });
  textareaNode.addEventListener('keydown', (event) => {
    if ((event.metaKey || event.ctrlKey) && event.key === 'Enter') saveComment();
  });

  // True when focus is in any text-entry field, so single-key shortcuts don't hijack
  // typing. Inside our shadow the document's activeElement is the host; root.activeElement
  // is the field.
  const isTyping = () => {
    const active = document.activeElement;
    if (active && (active.tagName === 'INPUT' || active.tagName === 'TEXTAREA' || active.isContentEditable)) {
      return true;
    }
    const shadowActive = root.activeElement;
    return !!shadowActive && (shadowActive.tagName === 'INPUT' || shadowActive.tagName === 'TEXTAREA');
  };

  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') {
      if (state.target) {
        setTargeting(false);
        sync();
        return;
      }
      if (state.composing) {
        cancelCompose();
        return;
      }
    }
    // Single-key shortcuts only while the panel is open, no modifier held, not typing.
    if (!state.open) return;
    if (event.metaKey || event.ctrlKey || event.altKey || isTyping()) return;
    if (event.key === 'c') {
      event.preventDefault();
      openNoteComposer();
    } else if (event.key === 't') {
      event.preventDefault();
      toggleTarget();
    }
  });

  // Pins and the highlight are recomputed from live rects on scroll/resize.
  let repositionFrame = 0;
  const scheduleReposition = () => {
    if (repositionFrame) return;
    repositionFrame = requestAnimationFrame(() => {
      repositionFrame = 0;
      renderPins();
      updateHighlight();
    });
  };
  window.addEventListener('scroll', scheduleReposition, true);
  window.addEventListener('resize', scheduleReposition);

  // SPA / same-document navigation: pins are anchored to the URL they were made on,
  // so a client-side route change must rebuild them — otherwise stale pins linger and
  // appear to "follow" onto the new page. History methods are wrapped (and popstate /
  // hashchange listened to) to catch every same-document navigation across browsers.
  let lastSeenUrl = location.href;
  const handleLocationChange = () => {
    if (location.href === lastSeenUrl) return;
    lastSeenUrl = location.href;
    state.hoverId = null;
    state.hoverPinId = null;
    state.pinConfirmId = null;
    renderPins();
    updateHighlight();
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

  sync();
})();
