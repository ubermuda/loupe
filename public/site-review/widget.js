(() => {
  // Idempotency guard. The widget's host elements are attached to <html>, so they
  // survive a Turbo (or any SPA) <body> swap; without this, every navigation that
  // re-executes the script tag would append another launcher/overlay and the
  // shadows would stack up. The flag lives on window, which persists across such
  // navigations, so only the first execution initializes.
  if (window.__loupeSiteReviewLoaded) return;
  window.__loupeSiteReviewLoaded = true;

  const script = document.currentScript;
  const BACKEND = new URL(script.src).origin;
  const TOKEN = script.getAttribute('data-token') || '';
  // The landing page runs this widget over itself with no project behind it, so
  // a visitor can try the flow before signing up. It swaps the transport and
  // nothing else — every behaviour below is the widget customers embed.
  const DEMO = script.hasAttribute('data-demo');
  // Every comment is saved to the API as it is written and is live from that
  // moment — there is no send step. `comments` mirrors the project's Pending
  // comments, the ones this reviewer may still edit or delete; once the agent
  // marks one addressed it drops out. Each item: { id, body, selector, text, url }.
  let comments = [];

  // Demo transport: an in-memory list that dies with the page. Same four calls,
  // same shapes, same 404 for a row that is gone — so the widget cannot tell.
  const demoStore = { comments: [], nextId: 1 };
  const demoApi = async (method, path, body) => {
    if (method === 'GET') {
      return { comments: demoStore.comments.map((comment) => ({ ...comment })) };
    }
    if (method === 'POST') {
      const commentId = `demo-${demoStore.nextId++}`;
      demoStore.comments.push({ id: commentId, ...body });
      return { commentId };
    }
    const id = path.slice(path.lastIndexOf('/') + 1);
    const index = demoStore.comments.findIndex((comment) => comment.id === id);
    if (index === -1) throw Object.assign(new Error('HTTP 404'), { status: 404 });
    if (method === 'PATCH') demoStore.comments[index].body = body.body;
    else demoStore.comments.splice(index, 1);
    return null;
  };

  const serverApi = async (method, path, body) => {
    const response = await fetch(`${BACKEND}${path}`, {
      method,
      headers: { 'Content-Type': 'application/json', Authorization: `Bearer ${TOKEN}` },
      body: body === undefined ? undefined : JSON.stringify(body),
    });
    if (!response.ok) {
      // Carry the status so callers can tell a permanent auth/config failure (401/403 —
      // invalid, revoked, or unlinked token) from a transient one (network, 5xx, 429).
      const error = new Error(`HTTP ${response.status}`);
      error.status = response.status;
      // The API's auth/authorization failures carry a machine-readable { error: code }
      // body (unauthorized / insufficient_scope / token_not_bound_to_site); read it to
      // tailor the fatal message. Guarded — a non-JSON body must never throw here.
      try {
        const data = await response.json();
        if (data && typeof data.error === 'string') error.code = data.error;
      } catch {
        /* no JSON body — the status alone still classifies the failure */
      }
      throw error;
    }
    return response.status === 204 ? null : response.json();
  };

  const api = DEMO ? demoApi : serverApi;

  // A 401/403 from ANY endpoint means the widget's token is unusable — loading, saving
  // and deleting all fail the same way — so it is not a per-action hiccup. Callers
  // promote it to the fatal state instead of showing a dismissible inline error.
  const authFailed = (error) => !!error && (error.status === 401 || error.status === 403);
  const fatalFrom = (error) => ({ status: error.status, code: error.code });
  // Enter the terminal fatal state: record the cause and tear down everything interactive
  // so the critical panel actually surfaces cleanly. Drops the in-memory list (so no
  // stale pins/rows/highlights linger as clickable dead ends), and exits pick and compose
  // modes — pick mode in particular hides the whole widget behind its scrim and keeps
  // document-level click listeners, which a boot rejection landing after the user entered
  // pick mode would otherwise leave stuck on the page.
  const enterFatal = (error) => {
    state.fatal = fatalFrom(error);
    comments = [];
    setTargeting(false);
    state.composing = false;
    state.composeTarget = null;
    state.editId = null;
    state.draft = '';
    state.actionError = null;
    state.savedNotice = null;
    textareaNode.value = '';
  };

  // Rehydrate the list from the project's Pending comments.
  const refresh = async ({ firstLoad = false } = {}) => {
    try {
      const payload = await api('GET', '/api/site-review/review');
      comments = payload.comments || [];
    } catch (error) {
      // Catch a rejected token at the earliest possible point — the boot load — so the
      // widget opens straight into its critical state instead of a misleading empty list.
      if (authFailed(error)) {
        enterFatal(error);
      } else if (firstLoad) {
        // A later failure keeps the list it already holds: the comments are safe on
        // the server, and blanking the screen would say otherwise. Only the boot
        // load has nothing to keep.
        comments = [];
      }
    }
  };

  const SELECTOR_MAX = 2000; // AddCommentRequest's cap — over it the API 400s
  const TEXT_MAX = 200; // how much anchor text the widget keeps, well under the cap

  // Comment bodies and anchor labels are arbitrary host-page text rendered into
  // innerHTML on a third-party page — every dynamic value MUST go through this.
  const escapeHtml = (value) =>
    String(value).replace(
      /[&<>"']/g,
      (character) =>
        ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[character],
    );

  // Build a stable-ish CSS selector for an element (used for re-anchoring on revisit).
  // A class-heavy page can outgrow the server's SELECTOR_MAX cap, so precision is
  // shed until it fits — classes first, then outermost ancestors — because a
  // less specific anchor beats a comment the API rejects.
  const selectorFor = (element) => {
    if (element.id) {
      const byId = `#${CSS.escape(element.id)}`;
      if (byId.length <= SELECTOR_MAX) return byId;
    }
    const parts = [];
    let current = element;
    while (current && current.nodeType === 1 && parts.length < 5) {
      const classes = [...current.classList].map((className) => CSS.escape(className));
      let nth = '';
      const parent = current.parentElement;
      if (parent) {
        const siblings = [...parent.children].filter((child) => child.tagName === current.tagName);
        if (siblings.length > 1) nth = `:nth-of-type(${siblings.indexOf(current) + 1})`;
      }
      parts.unshift({ tag: current.tagName.toLowerCase(), classes, nth });
      current = current.parentElement;
    }

    const render = (from, withClasses) =>
      parts
        .slice(from)
        .map(
          (part) =>
            part.tag +
            (withClasses && part.classes.length ? '.' + part.classes.join('.') : '') +
            part.nth,
        )
        .join(' > ');

    for (const withClasses of [true, false]) {
      for (let from = 0; from < parts.length; from++) {
        const selector = render(from, withClasses);
        if (selector.length <= SELECTOR_MAX) return selector;
      }
    }
    return '';
  };

  // Resolve a comment to a live element on the current page, or null when it
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

  // --- Design tokens: the app's Chartreuse palette, restated as literals. ---
  // The widget is embedded on other people's sites, so it may load nothing from
  // Loupe but itself: no @font-face, and no app font names either, since a
  // visitor's browser has none of them installed. The host's system UI font is
  // the whole type stack.
  //
  // The launcher, the pick-mode toast and the tooltips are the app's near-black
  // chrome in *both* themes. They float over a background the widget does not
  // control, and near-black carries its own contrast where paper does not.
  const CHROME = {
    '--font':
      "ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif",
    '--mono': 'ui-monospace,SFMono-Regular,Menlo,Consolas,monospace',
    '--bar-bg': '#0f0f0d',
    '--bar-raised': '#1c1c18',
    '--bar-line': '#3a3a34',
    '--bar-fg': '#e8e8e2',
    '--bar-mute': '#a8a89e',
    '--bar-shadow': '0 6px 20px rgba(15,15,13,.35)',
    '--accent': '#c4d600',
    '--accent-hover': '#b0c000',
    // Chartreuse is a light colour, so its foreground stays ink in both themes.
    '--on-accent': '#0f0f0d',
    // The dark edge that keeps a pin and the picker outline legible over a host
    // page whose background may be any colour, chartreuse-adjacent included.
    // Deep chartreuse rather than ink, so the edge reads as part of the accent
    // instead of a black box drawn over the page.
    '--pin-ring': '#3f4700',
    // Separation for the picker outline and its label. A soft shadow rather
    // than a hard ring: stacked inside the accent border and the fill glow,
    // a third concentric edge muddied the box at small sizes.
    '--pin-shadow': 'rgba(63,71,0,.35)',
  };
  const LIGHT = {
    '--panel-bg': '#ffffff',
    '--panel-border': '#f0f0ec',
    '--panel-elev': '#f9f9f6',
    '--hairline': '#f0f0ec',
    '--text': '#14140f',
    '--muted': '#6f6f66',
    '--faint': '#8f8f84',
    '--chip-bg': '#f4f4f0',
    '--chip-text': '#6f6f66',
    '--field-bg': '#f4f4f0',
    '--field-focus': '#f0f0ec',
    // Accent dark enough to read as text on paper.
    '--accent-ink': '#5c6600',
    '--accent-tint': '#f3f7c4',
    '--accent-border': '#dfe97a',
    '--accent-fill': 'rgba(196,214,0,.22)',
    '--shadow': '0 10px 34px rgba(15,15,13,.22)',
    '--scrim': 'rgba(15,15,13,.28)',
    '--success': '#2f9e5c',
    '--danger': '#c2372b',
  };
  // The app itself is light-only. This map is built from its dark shell rungs
  // rather than invented, so a widget on a dark host page still reads as Loupe.
  const DARK = {
    '--panel-bg': '#1c1c18',
    '--panel-border': '#3a3a34',
    '--panel-elev': '#26261f',
    '--hairline': '#26261f',
    '--text': '#e8e8e2',
    '--muted': '#a8a89e',
    '--faint': '#8f8f84',
    '--chip-bg': '#26261f',
    '--chip-text': '#a8a89e',
    '--field-bg': '#26261f',
    '--field-focus': '#33332b',
    '--accent-ink': '#c4d600',
    '--accent-tint': '#2c3010',
    '--accent-border': '#59631a',
    '--accent-fill': 'rgba(196,214,0,.26)',
    '--shadow': '0 12px 36px rgba(0,0,0,.55)',
    '--scrim': 'rgba(0,0,0,.45)',
    '--success': '#4ab97a',
    '--danger': '#e4685c',
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
    trash: (s) =>
      svg(
        s,
        '<path d="M3 6h18"/><path d="M8 6V4a1 1 0 0 1 1-1h6a1 1 0 0 1 1 1v2"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/>',
        1.9,
      ),
    check: (s, stroke, color) =>
      `<svg width="${s}" height="${s}" viewBox="0 0 24 24" fill="none" stroke="${color || 'currentColor'}" ` +
      `stroke-width="${stroke || 2.4}" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>`,
    edit: (s) =>
      svg(
        s,
        '<path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4 12.5-12.5z"/>',
        1.9,
      ),
    glyph: (s) =>
      `<svg width="${s}" height="${s}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" ` +
      `stroke-linecap="round"><circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="2.4" fill="currentColor" stroke="none"/></svg>`,
    alert: (s) =>
      svg(
        s,
        '<path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>',
        1.9,
      ),
  };

  // ---- widget state (the reviewer's comments live in `comments`; this is UI state) ----
  // Comments are identified by their index in `comments`; every mutation re-renders.
  const state = {
    open: false,
    target: false,
    composing: false,
    composeTarget: null, // { type:'general' } | { type:'element', el, selector, text, label }
    draft: '',
    editId: null, // server id of the comment being edited in place, or null for a new one
    listExpanded: false,
    expandLevel: 0,
    toastDock: 'top', // 'top' | 'bottom' — the pick-mode toast dodges to the bottom near the top edge
    moveHL: null, // { left, top, width, height, label } while picking
    hoverId: null, // hovered comment index (list row)
    hoverPinId: null, // hovered pin index
    confirmDeleteId: null, // armed inline list-row delete
    confirmClear: false,
    pinConfirmId: null, // armed pin-popover delete
    saving: false,
    deleting: false,
    savedNotice: null, // brief "it is live now" toast text, cleared on a timer
    actionError: null, // failed mutation (network/5xx/429/404): a dismissible inline banner
    fatal: null, // { status } once the token is rejected (401/403) — replaces the panel
  };
  let moveBase = null; // deepest element under the cursor while picking
  let pinCloseTimer = 0;

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
      /* The panel is a fixed-size popover and picking wants a cursor, so below
         the app's own mobile boundary the widget hides rather than degrades.
         A media query, not a boot-time check: it follows a rotation or a resize
         back into view on its own. */
      @media (max-width:639px){:host{display:none}}
      @keyframes lp-spin{to{transform:rotate(360deg)}}
      @keyframes lp-pop{from{transform:translateY(8px) scale(.985)}to{transform:none}}
      @keyframes lp-slide-left{from{transform:translateX(-100%)}to{transform:translateX(0)}}
      @keyframes lp-slide-left-out{from{transform:translateX(0)}to{transform:translateX(-100%)}}
      .lp-scroll::-webkit-scrollbar{width:10px;height:10px}
      .lp-scroll::-webkit-scrollbar-thumb{background:var(--faint);border-radius:9px;border:3px solid transparent;background-clip:content-box}
      .lp-scroll::-webkit-scrollbar-track{background:transparent}

      .lp-launcher{position:fixed;right:20px;bottom:20px;height:46px;padding:0 7px;display:flex;align-items:center;gap:0;background:var(--bar-bg);border:1px solid var(--bar-line);border-radius:999px;box-shadow:var(--bar-shadow);font-family:var(--font);pointer-events:auto;transition:box-shadow .14s ease,background .25s ease}
      /* The quick actions collapse as one unit when the panel opens. max-width + opacity
         animate the slide-away; visibility flips to hidden only after the collapse (the
         .24s delay) so the buttons are genuinely non-interactive once gone, and back
         immediately on expand. */
      .lp-launch-quick{display:flex;align-items:center;gap:3px;overflow:hidden;max-width:120px;opacity:1;visibility:visible;transition:max-width .24s cubic-bezier(.4,0,.2,1),opacity .18s ease,visibility 0s 0s}
      .lp-launcher.open .lp-launch-quick{max-width:0;opacity:0;visibility:hidden;transition:max-width .24s cubic-bezier(.4,0,.2,1),opacity .18s ease,visibility 0s .24s}
      .lp-launch-action{flex:0 0 auto;width:34px;height:34px;border:0;background:transparent;color:var(--bar-mute);border-radius:999px;display:flex;align-items:center;justify-content:center;cursor:pointer;transition:background .14s ease,color .14s ease}
      .lp-launch-action:hover{background:var(--bar-raised);color:var(--accent)}
      .lp-launch-div{flex:0 0 auto;width:1px;height:22px;background:var(--bar-line);margin:0 3px}
      .lp-launch-main{display:flex;align-items:center;gap:9px;height:38px;padding:0 10px 0 9px;background:transparent;border:0;color:var(--bar-fg);font-family:inherit;font-size:13.5px;font-weight:600;cursor:pointer;border-radius:999px;transition:background .14s ease}
      .lp-launch-main:hover{background:var(--bar-raised)}
      /* Styled tooltips for the launcher buttons, above each on hover. */
      [data-tip]{position:relative}
      [data-tip]::after{content:attr(data-tip);position:absolute;bottom:calc(100% + 8px);left:50%;transform:translateX(-50%) translateY(3px);padding:5px 10px;background:var(--bar-raised);border:1px solid var(--bar-line);color:var(--bar-fg);font-size:11.5px;font-weight:500;line-height:1.4;white-space:nowrap;border-radius:999px;box-shadow:var(--bar-shadow);opacity:0;pointer-events:none;transition:opacity .12s ease,transform .12s ease}
      [data-tip]:hover::after{opacity:1;transform:translateX(-50%) translateY(0)}
      .lp-count{display:inline-flex;align-items:center;justify-content:center;min-width:20px;height:20px;padding:0 7px;border-radius:999px;font-size:11.5px;font-weight:700}
      .lp-count.solid{background:var(--accent);color:var(--on-accent)}
      .lp-count.soft{background:var(--accent-tint);color:var(--accent-ink)}
      .lp-count.danger{background:var(--danger);color:#fff}

      .lp-panel{position:fixed;right:20px;bottom:78px;width:348px;max-height:calc(100vh - 160px);display:flex;flex-direction:column;background:var(--panel-bg);border:1px solid var(--panel-border);border-radius:16px;box-shadow:var(--shadow);pointer-events:auto;overflow:hidden;font-family:var(--font);color:var(--text);animation:lp-pop .2s cubic-bezier(.2,.9,.3,1);transition:background .25s ease,border-color .25s ease}
      .lp-main{display:flex;flex-direction:column;min-height:0;flex:1 1 auto}
      .lp-header{flex:0 0 auto;display:flex;align-items:center;gap:9px;padding:14px 14px 12px 17px}
      .lp-title{font-size:15px;font-weight:700;letter-spacing:-.01em}
      .lp-spacer{flex:1}
      .lp-iconbtn{width:28px;height:28px;border:0;background:transparent;color:var(--muted);border-radius:999px;display:flex;align-items:center;justify-content:center;cursor:pointer}
      .lp-iconbtn:hover{background:var(--panel-elev);color:var(--text)}

      .lp-composer{flex:0 0 auto;overflow:hidden;transition:max-height .27s cubic-bezier(.4,0,.2,1),opacity .2s ease}
      .lp-composer-inner{padding:2px 16px 14px}
      .lp-compose-head{display:flex;align-items:center;gap:7px;margin-bottom:9px;min-height:21px}
      .lp-compose-general{display:inline-flex;align-items:center;gap:6px;font-size:11.5px;color:var(--muted)}
      .lp-dot{width:7px;height:7px;border-radius:50%;border:1.5px dashed var(--faint)}
      .lp-compose-chip{flex:0 1 auto;min-width:0;display:inline-flex;align-items:center;gap:5px;height:21px;padding:0 9px;background:var(--accent-tint);color:var(--accent-ink);border-radius:999px;font-size:11px;font-weight:600;overflow:hidden}
      .lp-compose-chip span{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
      /* Borderless: the fill is the field, as everywhere else in the app. */
      .lp-textarea{width:100%;min-height:74px;resize:none;border:0;background:var(--field-bg);color:var(--text);border-radius:12px;padding:10px 12px;font-family:inherit;font-size:13px;line-height:1.5;outline:none;transition:background .14s ease}
      .lp-textarea:focus{background:var(--field-focus);box-shadow:inset 0 0 0 1px var(--accent-ink),0 0 0 3px var(--accent-tint)}
      .lp-textarea::placeholder{color:var(--faint)}
      .lp-compose-foot{display:flex;align-items:center;margin-top:9px}
      .lp-hint{font-size:11px;color:var(--faint)}
      .lp-mono{font-family:var(--mono)}
      .lp-ghost{height:30px;padding:0 13px;background:transparent;border:0;color:var(--muted);font-family:inherit;font-size:12.5px;font-weight:600;border-radius:999px;cursor:pointer}
      .lp-ghost:hover{background:var(--chip-bg);color:var(--text)}
      .lp-primary{height:30px;padding:0 15px;margin-left:4px;display:inline-flex;align-items:center;gap:6px;background:var(--accent);color:var(--on-accent);border:0;border-radius:999px;font-family:inherit;font-size:12.5px;font-weight:700;cursor:pointer;transition:background .14s ease}
      .lp-primary:hover{background:var(--accent-hover)}
      .lp-primary[disabled]{opacity:.55;cursor:default}

      .lp-actions{flex:0 0 auto;display:flex;gap:8px;padding:0 14px 12px}
      .lp-action{flex:1;height:38px;display:flex;align-items:center;justify-content:center;gap:7px;border-radius:999px;font-family:inherit;font-size:13px;font-weight:600;cursor:pointer;border:0;background:var(--chip-bg);color:var(--text);transition:background .15s ease,color .15s ease}
      .lp-action:hover{background:var(--field-focus)}
      /* Pressed, not primary: a solid accent fill here would compete with the
         Save button for the eye, so the toggle takes the pale tint. */
      .lp-action.active{background:var(--accent-tint);color:var(--accent-ink);box-shadow:inset 0 0 0 1px var(--accent-border)}
      .lp-kbd{font-family:var(--mono);font-size:10px;line-height:1;padding:2px 4px;border-radius:4px;background:var(--panel-elev);border:1px solid var(--panel-border);border-bottom-width:2px;color:var(--chip-text)}

      .lp-error{margin:0 14px 10px;padding:9px 11px;display:flex;align-items:flex-start;gap:8px;background:color-mix(in srgb,var(--danger) 10%,transparent);border:1px solid color-mix(in srgb,var(--danger) 28%,transparent);border-radius:12px;font-size:12px;line-height:1.45;color:var(--danger)}
      .lp-error span{flex:1;padding-top:2px}
      .lp-error button{flex:0 0 auto;background:transparent;border:0;color:var(--danger);font-family:inherit;font-size:12px;font-weight:700;cursor:pointer;border-radius:999px;padding:3px 9px}
      .lp-error button:hover{background:color-mix(in srgb,var(--danger) 14%,transparent)}

      .lp-empty-anim{flex:0 0 auto;overflow:hidden;transition:max-height .27s cubic-bezier(.4,0,.2,1),opacity .2s ease}
      .lp-empty{display:flex;flex-direction:column;align-items:center;justify-content:center;text-align:center;padding:22px 26px 28px;gap:4px}
      .lp-empty-icon{width:42px;height:42px;border-radius:999px;background:var(--chip-bg);display:flex;align-items:center;justify-content:center;color:var(--faint);margin-bottom:8px}
      .lp-empty-title{font-size:13.5px;font-weight:700;color:var(--text)}
      .lp-empty-sub{font-size:12.5px;color:var(--muted);line-height:1.5;max-width:210px}

      .lp-list-wrap{flex:0 1 auto;display:flex;flex-direction:column;min-height:0}
      .lp-list-anim{overflow:hidden;transition:max-height .3s cubic-bezier(.4,0,.2,1),opacity .2s ease}
      .lp-list{max-height:248px;overflow:auto;border-top:1px solid var(--hairline)}
      .lp-item{position:relative;overflow:hidden;display:flex;gap:11px;padding:12px 15px;border-bottom:1px solid var(--hairline);cursor:default}
      .lp-item:hover{background:var(--panel-elev)}
      .lp-item-confirm{position:absolute;inset:0;display:flex;align-items:center;gap:8px;padding:0 15px;background:var(--panel-bg);animation:lp-slide-left .18s cubic-bezier(.4,0,.2,1)}
      .lp-item-confirm-text{flex:1;font-size:12px;color:var(--text);font-weight:500}
      /* The teardrop is the pin's silhouette, so an anchored comment's badge
         carries the same shape as the marker it points at. */
      .lp-badge{flex:0 0 auto;width:22px;height:22px;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:700}
      .lp-badge.element{border-radius:50% 50% 50% 2px;background:var(--accent);color:var(--on-accent)}
      .lp-badge.general{border-radius:50%;border:1.5px dashed var(--faint);color:var(--faint)}
      .lp-item-body{flex:1;min-width:0}
      .lp-item-text{font-size:13px;line-height:1.5;color:var(--text);word-break:break-word}
      .lp-chip{display:inline-flex;align-items:center;gap:4px;margin-top:6px;height:19px;padding:0 9px;background:var(--chip-bg);color:var(--chip-text);border-radius:999px;font-size:10.5px;font-weight:600;max-width:100%;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
      .lp-edit{flex:0 0 auto;width:24px;height:24px;border:0;background:transparent;color:var(--faint);border-radius:999px;display:flex;align-items:center;justify-content:center;cursor:pointer;opacity:.55;transition:opacity .12s ease}
      .lp-edit:hover{opacity:1;background:var(--chip-bg);color:var(--accent-ink)}
      .lp-del{flex:0 0 auto;width:24px;height:24px;border:0;background:transparent;color:var(--faint);border-radius:999px;display:flex;align-items:center;justify-content:center;cursor:pointer;opacity:.55;transition:opacity .12s ease}
      .lp-del:hover{opacity:1;background:var(--chip-bg);color:var(--danger)}
      .lp-danger-sm{flex:0 0 auto;height:25px;padding:0 11px;background:var(--danger);color:#fff;border:0;border-radius:999px;font-family:inherit;font-size:11.5px;font-weight:700;cursor:pointer}
      .lp-ghost-sm{height:25px;padding:0 9px;background:transparent;border:0;color:var(--muted);font-family:inherit;font-size:11.5px;font-weight:600;border-radius:999px;cursor:pointer}
      .lp-ghost-sm:hover{background:var(--chip-bg);color:var(--text)}

      .lp-footer{flex:0 0 auto;align-items:center;gap:8px;padding:11px 14px;border-top:1px solid var(--hairline);background:var(--panel-bg)}
      .lp-footer-row{display:flex;align-items:center;gap:8px;width:100%}
      .lp-list-toggle{display:flex;align-items:center;gap:6px;height:32px;padding:0 11px 0 9px;background:transparent;border:0;cursor:pointer;color:var(--muted);font-family:inherit;font-size:12px;font-weight:600;border-radius:999px}
      .lp-list-toggle:hover{background:var(--chip-bg);color:var(--text)}
      .lp-chev{transition:transform .25s ease}
      .lp-clear{height:32px;padding:0 13px;background:transparent;border:0;color:var(--muted);font-family:inherit;font-size:12.5px;font-weight:600;border-radius:999px;cursor:pointer}
      .lp-clear:hover{background:var(--chip-bg);color:var(--danger)}
      .lp-clear[disabled]{opacity:.55;cursor:default}
      .lp-confirm-text{flex:1;font-size:12px;color:var(--text);font-weight:600;line-height:1.4}
      .lp-clear-cancel{height:32px;padding:0 13px;background:transparent;border:0;color:var(--muted);font-family:inherit;font-size:12.5px;font-weight:600;border-radius:999px;cursor:pointer}
      .lp-clear-cancel:hover{background:var(--chip-bg);color:var(--text)}
      .lp-clear-yes{height:32px;padding:0 15px;background:var(--danger);color:#fff;border:0;border-radius:999px;font-family:inherit;font-size:12.5px;font-weight:700;cursor:pointer}
      .lp-spin{width:13px;height:13px;border:2px solid currentColor;border-right-color:transparent;border-radius:50%;display:inline-block;animation:lp-spin .6s linear infinite}

      .lp-fatal{padding:10px 24px 26px;text-align:center;animation:lp-pop .2s ease}
      .lp-fatal-disc{width:46px;height:46px;margin:6px auto 13px;border-radius:50%;background:color-mix(in srgb,var(--danger) 13%,transparent);display:flex;align-items:center;justify-content:center;color:var(--danger)}
      .lp-fatal-title{font-size:14.5px;font-weight:700;color:var(--text)}
      .lp-fatal-sub{max-width:252px;margin:6px auto 0;font-size:12.5px;color:var(--muted);line-height:1.55}
    </style>
    <div class="lp-launcher" id="lp-launcher">
      <div class="lp-launch-quick" id="lp-launch-quick">
        <button class="lp-launch-action" id="lp-launch-note" aria-label="Add note" data-tip="Add note">${ICON.comment(16)}</button>
        <button class="lp-launch-action" id="lp-launch-target" aria-label="Pick element" data-tip="Pick element">${ICON.target(16)}</button>
        <span class="lp-launch-div"></span>
      </div>
      <button class="lp-launch-main" id="lp-launch-main" aria-label="Review">
        <span>Review</span>
        <span class="lp-count solid" id="lp-launch-count" style="display:none">0</span>
        <span class="lp-count danger" id="lp-launch-alert" style="display:none" aria-label="Widget error" title="This review widget can’t connect">!</span>
      </button>
    </div>
    <div class="lp-panel" id="lp-panel" style="display:none">
      <div class="lp-header">
        <span class="lp-title">Review</span>
        <span class="lp-count soft" id="lp-head-count" style="display:none">0</span>
        <div class="lp-spacer"></div>
        <button class="lp-iconbtn" id="lp-close" aria-label="Close">${ICON.close(15)}</button>
      </div>
      <div class="lp-main" id="lp-main">
        <div class="lp-composer" id="lp-composer" style="max-height:0;opacity:0;pointer-events:none">
          <div style="overflow:hidden;min-height:0">
            <div class="lp-composer-inner">
              <div class="lp-compose-head" id="lp-compose-head"></div>
              <textarea class="lp-textarea" id="lp-textarea" placeholder="Describe the issue or idea…"></textarea>
              <div class="lp-compose-foot">
                <span class="lp-hint"><span class="lp-mono">⌘↵</span> to save</span>
                <div class="lp-spacer"></div>
                <button class="lp-ghost" id="lp-cancel">Cancel</button>
                <button class="lp-primary" id="lp-save">Save</button>
              </div>
            </div>
          </div>
        </div>
        <div class="lp-empty-anim" id="lp-empty-anim" style="max-height:0;opacity:0">
          <div style="overflow:hidden;min-height:0">
            <div class="lp-empty" id="lp-empty">
              <div class="lp-empty-icon">${ICON.comment(20)}</div>
              <div class="lp-empty-title">No comments yet</div>
              <div class="lp-empty-sub">Add a note, or pick an element on the page to anchor your feedback.</div>
            </div>
          </div>
        </div>
        <div class="lp-actions">
          <button class="lp-action" id="general" aria-pressed="false">${ICON.comment(15)}<span>Add note</span><span class="lp-kbd" aria-hidden="true">C</span></button>
          <button class="lp-action" id="target" aria-pressed="false">${ICON.target(15)}<span>Pick element</span><span class="lp-kbd" aria-hidden="true">T</span></button>
        </div>
        <div class="lp-error" id="lp-error" style="display:none"></div>
        <div id="lp-body">
          <div class="lp-list-wrap" id="lp-list-wrap" style="display:none">
            <div class="lp-list-anim" id="lp-list-anim" style="max-height:0;opacity:0">
              <div style="overflow:hidden;min-height:0">
                <div class="lp-list lp-scroll" id="lp-list"></div>
              </div>
            </div>
          </div>
        </div>
        <div class="lp-footer" id="lp-footer" style="display:none">
          <div class="lp-footer-row" id="lp-footer-main">
            <button class="lp-list-toggle" id="lp-list-toggle">
              <span class="lp-chev" id="lp-chev" style="display:inline-flex">${ICON.chevron(13)}</span>
              <span id="lp-list-toggle-text">Show comments</span>
            </button>
            <div class="lp-spacer"></div>
            <button class="lp-clear" id="lp-clear">Delete all</button>
          </div>
          <div class="lp-footer-row" id="lp-footer-confirm" style="display:none">
            <span class="lp-confirm-text" id="lp-clear-confirm-text"></span>
            <div class="lp-spacer"></div>
            <button class="lp-clear-cancel" id="lp-clear-cancel">Cancel</button>
            <button class="lp-clear-yes" id="lp-clear-yes">Delete</button>
          </div>
        </div>
      </div>
      <div id="lp-fatal" style="display:none"></div>
    </div>`;

  overlayRoot.innerHTML = `
    <style>
      *{box-sizing:border-box}
      :host{all:initial}
      /* The panel is a fixed-size popover and picking wants a cursor, so below
         the app's own mobile boundary the widget hides rather than degrades.
         A media query, not a boot-time check: it follows a rotation or a resize
         back into view on its own. */
      @media (max-width:639px){:host{display:none}}
      @keyframes lp-fade{from{opacity:0}to{opacity:1}}
      @keyframes lp-slide-left{from{transform:translateX(-100%)}to{transform:translateX(0)}}
      @keyframes lp-slide-left-out{from{transform:translateX(0)}to{transform:translateX(-100%)}}
      @keyframes lp-pin{from{transform:scale(.4)}to{transform:scale(1)}}
      @keyframes lp-pop{from{transform:translateY(8px) scale(.985)}to{transform:none}}
      @keyframes lp-spin{to{transform:rotate(360deg)}}
      .lp-ov{font-family:var(--font)}
      .lp-scrim{position:fixed;inset:0;background:var(--scrim);animation:lp-fade .18s ease;pointer-events:none}
      /* Chartreuse alone can vanish against a pale or yellowish host page, so
         every marker the widget paints onto the page carries a near-black edge
         of its own: the accent reads as the brand, the ring keeps it visible. */
      .highlight{position:fixed;border:2px solid var(--accent);background:var(--accent-fill);border-radius:10px;pointer-events:none;box-shadow:0 2px 10px var(--pin-shadow);transition:left .07s ease,top .07s ease,width .07s ease,height .07s ease;z-index:2}
      .lp-hl-label{position:absolute;left:-2px;top:-27px;display:inline-block;max-width:240px;height:21px;line-height:21px;padding:0 9px;background:var(--accent);color:var(--on-accent);font-size:11px;font-weight:600;border-radius:999px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;box-shadow:0 2px 10px var(--pin-shadow)}
      .lp-pin-wrap{position:fixed;z-index:4;pointer-events:auto}
      .lp-ov.targeting .lp-pin-wrap{pointer-events:none}
      .pin{width:24px;height:24px;border-radius:50% 50% 50% 2px;border:2px solid var(--pin-ring);background:var(--accent);color:var(--on-accent);font-family:inherit;font-size:12px;font-weight:700;display:flex;align-items:center;justify-content:center;cursor:pointer;box-shadow:0 2px 8px rgba(15,15,13,.35);animation:lp-pin .22s cubic-bezier(.2,1.3,.5,1)}
      .pin:hover{transform:scale(1.12)}
      .lp-pop{position:absolute;top:16px;right:0;width:240px;padding-top:14px;cursor:default}
      .lp-pop-card{position:relative;overflow:hidden;min-height:96px;padding:12px;background:var(--panel-bg);border:1px solid var(--panel-border);border-radius:12px;box-shadow:var(--shadow);animation:lp-fade .12s ease;display:flex;flex-direction:column}
      .lp-pop-body{font-size:12.5px;line-height:1.5;color:var(--text);word-break:break-word}
      .lp-pop-row{display:flex;align-items:center;gap:8px;margin-top:auto;padding-top:10px}
      .lp-pop-chip{display:inline-flex;align-items:center;height:19px;padding:0 9px;background:var(--chip-bg);color:var(--chip-text);border-radius:999px;font-size:10.5px;font-weight:600;max-width:150px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
      .lp-pop-edit{flex:0 0 auto;width:24px;height:24px;border:0;background:transparent;color:var(--faint);border-radius:999px;display:flex;align-items:center;justify-content:center;cursor:pointer;opacity:.6;transition:opacity .12s ease}
      .lp-pop-edit:hover{opacity:1;background:var(--chip-bg);color:var(--accent-ink)}
      .lp-pop-del{flex:0 0 auto;width:24px;height:24px;border:0;background:transparent;color:var(--faint);border-radius:999px;display:flex;align-items:center;justify-content:center;cursor:pointer;opacity:.6;transition:opacity .12s ease}
      .lp-pop-del:hover{opacity:1;background:var(--chip-bg);color:var(--danger)}
      .lp-pop-confirm{position:absolute;inset:0;background:var(--panel-bg);border-radius:12px;padding:12px;display:flex;flex-direction:column;justify-content:center;animation:lp-slide-left .18s cubic-bezier(.4,0,.2,1)}
      .lp-pop-confirm-title{font-size:12.5px;font-weight:700;color:var(--text)}
      .lp-pop-confirm-sub{font-size:11.5px;color:var(--muted);margin-top:3px;line-height:1.45}
      .lp-pop-confirm-row{display:flex;gap:7px;margin-top:11px}
      .lp-pop-yes{flex:1;height:30px;background:var(--danger);color:#fff;border:0;border-radius:999px;font-family:inherit;font-size:12px;font-weight:700;cursor:pointer}
      .lp-pop-no{flex:1;height:30px;background:var(--chip-bg);border:0;color:var(--text);border-radius:999px;font-family:inherit;font-size:12px;font-weight:600;cursor:pointer}
      .lp-toast{position:fixed;top:18px;left:50%;transform:translate(-50%,0);z-index:6;display:flex;align-items:center;gap:10px;padding:9px 13px 9px 15px;background:var(--bar-bg);border:1px solid var(--bar-line);color:var(--bar-fg);border-radius:999px;font-size:13px;font-weight:600;box-shadow:var(--bar-shadow);animation:lp-fade .18s ease;transition:transform .22s cubic-bezier(.4,0,.2,1);pointer-events:auto}
      .lp-toast-sep{color:var(--bar-line)}
      .lp-toast-dim{color:var(--bar-mute);font-size:12px;font-weight:500}
      .lp-toast-key{margin-left:2px;padding:3px 9px;background:var(--bar-raised);border:1px solid var(--bar-line);border-radius:999px;font-size:11px;font-weight:600;cursor:pointer;transition:background .12s ease}
      .lp-toast-key:hover{background:var(--bar-line)}
      .lp-toast--saved{padding:9px 15px}
    </style>
    <div class="lp-ov" id="lp-ov">
      <div class="lp-scrim" id="lp-scrim" style="display:none"></div>
      <div class="highlight" id="lp-hl" style="display:none"><span class="lp-hl-label" id="lp-hl-label" style="display:none"></span></div>
      <div id="lp-pins"></div>
      <div class="lp-toast" id="lp-toast" style="display:none">
        ${ICON.target(15)}
        <span>Click to comment</span>
        <span class="lp-toast-sep">·</span>
        <span class="lp-toast-dim">⌥ scroll to resize</span>
        <span class="lp-toast-key" id="lp-toast-esc">Esc</span>
      </div>
      <div class="lp-toast lp-toast--saved" id="lp-saved" style="display:none">
        ${ICON.check(15, 2.6, 'var(--success)')}
        <span id="lp-saved-text"></span>
      </div>
    </div>`;

  const $ = (id) => root.getElementById(id);
  const $$ = (id) => overlayRoot.getElementById(id);

  const ovRoot = $$('lp-ov');
  const scrimNode = $$('lp-scrim');
  const hlNode = $$('lp-hl');
  const hlLabel = $$('lp-hl-label');
  const pinsNode = $$('lp-pins');
  const toastNode = $$('lp-toast');
  const savedToastNode = $$('lp-saved');
  const panelNode = $('lp-panel');
  const mainNode = $('lp-main');
  const fatalNode = $('lp-fatal');
  const composerNode = $('lp-composer');
  const composeHead = $('lp-compose-head');
  const textareaNode = $('lp-textarea');
  const errorNode = $('lp-error');
  const emptyAnim = $('lp-empty-anim');
  const launchQuick = $('lp-launch-quick');
  const listWrap = $('lp-list-wrap');
  const listAnim = $('lp-list-anim');
  const listNode = $('lp-list');
  const footerNode = $('lp-footer');
  const footerMain = $('lp-footer-main');
  const footerConfirm = $('lp-footer-confirm');
  const clearBtn = $('lp-clear');
  const saveBtn = $('lp-save');
  const generalBtn = $('general');
  const targetBtn = $('target');

  // --- theming: follow the host's color scheme and live-update on change. ---
  const applyTheme = (dark) => {
    const tokens = { ...CHROME, ...(dark ? DARK : LIGHT) };
    [host, overlayHost].forEach((node) => {
      Object.entries(tokens).forEach(([key, value]) => node.style.setProperty(key, value));
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
  const HIGHLIGHT_PADDING = 8; // px of breathing room around the targeted element
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
        const comment = comments[index];
        const el = comment && resolveElement(comment);
        const r = el && rectOf(el);
        if (r) hl = { left: r.left, top: r.top, width: r.width, height: r.height, label: null };
      }
    }
    if (!hl) {
      hlNode.style.display = 'none';
      return;
    }
    // Outset the measured rect so the outline frames the element rather than
    // tracing its edge. Applied before the label is placed, so the label keeps
    // sitting against the box the reviewer actually sees.
    hl = {
      ...hl,
      left: hl.left - HIGHLIGHT_PADDING,
      top: hl.top - HIGHLIGHT_PADDING,
      width: hl.width + HIGHLIGHT_PADDING * 2,
      height: hl.height + HIGHLIGHT_PADDING * 2,
    };
    hlNode.style.display = 'block';
    hlNode.style.left = hl.left + 'px';
    hlNode.style.top = hl.top + 'px';
    hlNode.style.width = hl.width + 'px';
    hlNode.style.height = hl.height + 'px';
    if (hl.label) {
      // Block, not inline-flex: text-overflow has no effect on a flex container's
      // anonymous text item, so the label would hard-clip mid-word instead of
      // ellipsing when the element's first line runs past the pill's max width.
      hlLabel.style.display = 'block';
      hlLabel.textContent = hl.label;
      // The label normally sits just above the box's left corner; when the box hugs an
      // edge of the viewport that placement clips off-screen, so flip to the other side.
      // Vertical: drop below the box when there's no room above.
      hlLabel.style.top = hl.top >= 26 ? '-25px' : hl.height + 4 + 'px';
      // Horizontal: anchor to the box's right edge (extending left) when a left-anchored
      // label would run off the right of the viewport.
      const overflowRight = hl.left - 2 + hlLabel.offsetWidth > window.innerWidth;
      hlLabel.style.left = overflowRight ? 'auto' : '-2px';
      hlLabel.style.right = overflowRight ? '-2px' : 'auto';
    } else {
      hlLabel.style.display = 'none';
    }
  };

  // Slide a confirm overlay back out to the left, then remove it. Marked exiting so
  // repeated renders don't restart the animation or double-remove.
  const slideOut = (el) => {
    if (!el || el.dataset.exiting) return;
    el.dataset.exiting = '1';
    el.style.animation = 'lp-slide-left-out .18s cubic-bezier(.4,0,.2,1) forwards';
    el.addEventListener('animationend', () => el.remove(), { once: true });
  };

  // Pin nodes are reconciled (not rebuilt) so the lp-pin drop-in animation plays
  // once per pin; hovering and scrolling reposition existing nodes rather than
  // recreating them (recreating would replay the scale-in and make the pin shrink).
  const pinNodes = new Map();
  // The card (body + chip + delete) is built once on hover; the confirm overlay is
  // a separate node toggled in place, so arming/cancelling delete never rebuilds the
  // card (which would replay its fade-in and flicker).
  const buildPopover = (comment, index) => {
    const label = firstLineLabel(comment.text);
    return `<div class="lp-pop">
        <div class="lp-pop-card">
          <div class="lp-pop-body">${escapeHtml(comment.body)}</div>
          <div class="lp-pop-row">
            ${label ? `<span class="lp-pop-chip">${escapeHtml(label)}</span>` : ''}
            <div style="flex:1"></div>
            <button class="lp-pop-edit" data-pin-edit="${index}" aria-label="Edit">${ICON.edit(14)}</button>
            <button class="lp-pop-del" data-pin-del="${index}" aria-label="Delete">${ICON.trash(14)}</button>
          </div>
        </div>
      </div>`;
  };
  // The popover's resting place is up and to the left of its pin, which puts it
  // off-screen for a pin near the left or bottom edge of the viewport. Nudge it
  // back inside. This runs on every reposition pass, not only when the card is
  // built, because the card survives scrolling — clamping once would let it
  // drift back out as the page moves under it.
  const POPOVER_MARGIN = 8;
  const PIN_SIZE = 24; // .pin width in the overlay stylesheet
  const POPOVER_OFFSET = 16; // .lp-pop top offset in the overlay stylesheet
  const clampPopover = (popover, info) => {
    if (!popover) return;
    // The wrap is the pin button, so the popover's default box in viewport
    // coordinates starts at the wrap's right edge minus its own width.
    const width = popover.offsetWidth;
    const height = popover.offsetHeight;
    const defaultLeft = info.left + PIN_SIZE - width;
    const defaultTop = info.top + POPOVER_OFFSET;
    const maxLeft = Math.max(POPOVER_MARGIN, window.innerWidth - width - POPOVER_MARGIN);
    const maxTop = Math.max(POPOVER_MARGIN, window.innerHeight - height - POPOVER_MARGIN);
    const left = Math.min(Math.max(defaultLeft, POPOVER_MARGIN), maxLeft);
    const top = Math.min(Math.max(defaultTop, POPOVER_MARGIN), maxTop);
    // Positions are relative to the wrap, so convert back from viewport space.
    popover.style.right = 'auto';
    popover.style.left = `${left - info.left}px`;
    popover.style.top = `${top - info.top}px`;
  };

  const buildConfirm = (index) =>
    `<div class="lp-pop-confirm">
       <div class="lp-pop-confirm-title">Delete this note?</div>
       <div class="lp-pop-confirm-sub">The pin will be removed from the page.</div>
       <div class="lp-pop-confirm-row">
         <button class="lp-pop-yes" data-pin-yes="${index}">Delete</button>
         <button class="lp-pop-no" data-pin-no="${index}">Cancel</button>
       </div>
     </div>`;
  const bindPopover = (holder, index) => {
    const del = holder.querySelector('[data-pin-del]');
    if (del)
      del.addEventListener('click', () => {
        state.pinConfirmId = index;
        renderPins();
      });
    const edit = holder.querySelector('[data-pin-edit]');
    if (edit) edit.addEventListener('click', () => openEditComposer(index));
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
    comments.forEach((comment, index) => {
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
        wrap.className = 'lp-pin-wrap';
        wrap.innerHTML =
          `<button class="pin" style="animation:lp-pin .22s cubic-bezier(.2,1.3,.5,1)"></button>` +
          `<div class="lp-pop-holder"></div>`;
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
      const holder = wrap.querySelector('.lp-pop-holder');
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
        clampPopover(holder.querySelector('.lp-pop'), info);
        const card = holder.querySelector('.lp-pop-card');
        const liveConfirm = card.querySelector('.lp-pop-confirm:not([data-exiting])');
        if (confirming && !liveConfirm) {
          card.querySelectorAll('.lp-pop-confirm').forEach((node) => node.remove());
          card.insertAdjacentHTML('beforeend', buildConfirm(index));
          bindConfirm(card.querySelector('.lp-pop-confirm'), index);
        } else if (!confirming && liveConfirm) {
          slideOut(liveConfirm);
        }
      }
    });
  };

  // The pick-mode toast lives at the top centre, where it can cover the very element
  // the reviewer wants to click. Rather than add controls, it auto-dodges: when the
  // cursor enters the top band it slides to the bottom, and slides back when it leaves.
  const TOAST_DODGE_BAND = 140; // px from the top edge
  const positionToast = () => {
    if (state.toastDock === 'bottom') {
      const offset = window.innerHeight - toastNode.offsetHeight - 36;
      toastNode.style.transform = `translate(-50%, ${offset}px)`;
    } else {
      toastNode.style.transform = 'translate(-50%, 0)';
    }
  };
  const updateToastDodge = (clientY) => {
    const dock = clientY != null && clientY < TOAST_DODGE_BAND ? 'bottom' : 'top';
    if (state.toastDock === dock) return;
    state.toastDock = dock;
    positionToast();
  };

  const renderOverlay = () => {
    scrimNode.style.display = state.target ? 'block' : 'none';
    toastNode.style.display = state.target ? 'flex' : 'none';
    // offsetHeight only reads correctly once the toast is shown, so position after.
    if (state.target) positionToast();
    // Both toasts sit top-centre, so the pick-mode one wins while it is up.
    const showSaved = !!state.savedNotice && !state.target;
    savedToastNode.style.display = showSaved ? 'flex' : 'none';
    if (showSaved) $$('lp-saved-text').textContent = state.savedNotice;
    renderPins();
    updateHighlight();
  };

  // Copy for a failed mutation. Auth failures (401/403) never reach here — authFailed()
  // promotes them to the fatal state. A 404 is its own case: the comment stopped being
  // editable because the agent picked it up, so retrying would fail the same way.
  const errorText = (error) =>
    error && error.status === 404
      ? 'Your agent has already picked that comment up, so it can’t be changed now.'
      : 'Couldn’t apply that change. Please try again.';

  // Detail line for the fatal panel, tailored to how the token was rejected. Keyed on the
  // server's error code (from the response body) with a status fallback: the three cases
  // have distinct fixes, so a generic "token rejected" would send embedders down the wrong
  // path (e.g. "regenerate" doesn't help when the wrong token type was pasted in).
  const fatalDetail = ({ status, code }) => {
    if ('token_not_bound_to_site' === code) {
      return 'This widget’s token isn’t linked to a site. Regenerate the widget token on the Connect page and update the embed snippet.';
    }
    if ('insufficient_scope' === code) {
      return 'This token can’t post site reviews. Make sure the embed uses the site’s widget token, not another API token.';
    }
    if (401 === status || 'unauthorized' === code) {
      return 'This widget’s access token is invalid or was revoked. Update the embed snippet with a current token.';
    }
    return 'This widget’s token was rejected. Check the embed snippet uses the site’s current widget token.';
  };

  // ---- panel render ----
  const updatePanel = () => {
    const fatal = null !== state.fatal;
    const n = comments.length;
    const launchCount = $('lp-launch-count');
    launchCount.style.display = !fatal && n > 0 ? 'inline-flex' : 'none';
    launchCount.textContent = String(n);
    // A rejected token surfaces on the collapsed launcher too — a danger "!" badge — so
    // the problem is visible before the panel is ever opened.
    $('lp-launch-alert').style.display = fatal ? 'inline-flex' : 'none';

    // While picking an element, hide the whole widget (launcher + panel) so it does
    // not obscure the page; the scrim + toast are the only pick-mode UI.
    const launcherNode = $('lp-launcher');
    launcherNode.style.display = state.target ? 'none' : '';
    // The launcher's quick actions duplicate the in-panel ones, so hide them (keeping only
    // the Review toggle) whenever the panel is open — or fatal, where they'd only launch a
    // composer the critical state immediately hides.
    launcherNode.classList.toggle('open', state.open || fatal);
    // A host page may float its own chrome beside the launcher. Both the panel
    // and pick mode take the space over, so say when that happens.
    document.documentElement.toggleAttribute(
      'data-loupe-review-open',
      state.open || Boolean(state.target),
    );
    // Clip the quick actions while they collapse; once expanded and idle, allow overflow
    // so their hover tooltips can escape upward (see the transitionend handler).
    if (state.open) launchQuick.style.overflow = 'hidden';
    panelNode.style.display = state.open && !state.target ? 'flex' : 'none';
    if (!state.open || state.target) return;

    const headCount = $('lp-head-count');
    headCount.style.display = n > 0 && !fatal ? 'inline-flex' : 'none';
    headCount.textContent = String(n);

    // A rejected token takes precedence over every other panel state.
    fatalNode.style.display = fatal ? 'block' : 'none';
    mainNode.style.display = fatal ? 'none' : 'flex';

    if (fatal) {
      renderFatal();
      return;
    }

    // composer
    composerNode.style.maxHeight = state.composing ? '240px' : '0px';
    composerNode.style.opacity = state.composing ? '1' : '0';
    composerNode.style.pointerEvents = state.composing ? 'auto' : 'none';
    // The save is the composer's own action, so its progress belongs on the Save button.
    saveBtn.disabled = state.saving;
    saveBtn.innerHTML = state.saving
      ? `<span class="lp-spin"></span>Saving…`
      : 'Save';
    if (state.composing) {
      const ct = state.composeTarget || { type: 'general' };
      composeHead.innerHTML =
        ct.type === 'general'
          ? `<span class="lp-compose-general"><span class="lp-dot"></span>General comment</span>`
          : `<span class="lp-compose-chip">${ICON.glyph(11)}<span>${escapeHtml(ct.label || 'Selected element')}</span></span>`;
    }
    // Composer just closed but the textarea kept focus would keep isTyping() true and
    // trap the single-key shortcuts (t/c). Blur it once the composer is hidden.
    if (!state.composing && root.activeElement === textareaNode) textareaNode.blur();

    // action buttons
    const noteActive = state.composing && (state.composeTarget || {}).type === 'general';
    generalBtn.classList.toggle('active', noteActive);
    generalBtn.setAttribute('aria-pressed', noteActive ? 'true' : 'false');
    targetBtn.classList.toggle('active', state.target);
    targetBtn.setAttribute('aria-pressed', state.target ? 'true' : 'false');

    if (state.actionError) {
      errorNode.style.display = 'flex';
      errorNode.innerHTML =
        `<span>${escapeHtml(errorText(state.actionError))}</span>` +
        `<button id="lp-error-dismiss">Dismiss</button>`;
      root.getElementById('lp-error-dismiss').addEventListener('click', () => {
        state.actionError = null;
        updatePanel();
      });
    } else {
      errorNode.style.display = 'none';
    }

    // empty vs list
    // Animate the empty state the same way the composer/list do, so cancelling a compose
    // on an empty panel cross-fades (composer slides out as this slides in) instead of
    // the empty state popping back instantly mid-animation.
    const showEmpty = n === 0 && !state.composing;
    emptyAnim.style.maxHeight = showEmpty ? '220px' : '0px';
    emptyAnim.style.opacity = showEmpty ? '1' : '0';
    listWrap.style.display = n > 0 ? 'flex' : 'none';
    listAnim.style.maxHeight = state.listExpanded ? '260px' : '0px';
    listAnim.style.opacity = state.listExpanded ? '1' : '0';
    renderList();

    // footer
    footerNode.style.display = n > 0 ? 'flex' : 'none';
    if (n > 0) {
      footerMain.style.display = state.confirmClear ? 'none' : 'flex';
      footerConfirm.style.display = state.confirmClear ? 'flex' : 'none';
      $('lp-chev').style.transform = `rotate(${state.listExpanded ? '0deg' : '180deg'})`;
      $('lp-list-toggle-text').textContent = state.listExpanded
        ? 'Hide comments'
        : n === 1
          ? 'Show 1 comment'
          : `Show ${n} comments`;
      // These are live comments the agent may already be acting on, not a private
      // draft, so the confirmation says what is actually at stake.
      $('lp-clear-confirm-text').textContent =
        n === 1
          ? 'Delete this comment? Your agent may have it already.'
          : `Delete all ${n} comments? Your agent may have them already.`;
      clearBtn.disabled = state.saving || state.deleting;
    }
  };

  // List rows are reconciled (not rebuilt) so arming/cancelling a delete only toggles
  // that row's confirm overlay in place — it slides in, and Cancel slides it back out
  // — without re-rendering the row (which would interrupt the animation).
  const rowNodes = new Map();
  const buildItemConfirm = (index) =>
    `<div class="lp-item-confirm">
       <span class="lp-item-confirm-text">Delete this comment?</span>
       <button class="lp-danger-sm" data-del-yes="${index}">Delete</button>
       <button class="lp-ghost-sm" data-del-no="${index}">Cancel</button>
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
      if (index >= comments.length) {
        row.remove();
        rowNodes.delete(index);
      }
    });
    comments.forEach((comment, index) => {
      let row = rowNodes.get(index);
      if (!row) {
        row = document.createElement('div');
        row.className = 'lp-item';
        row.innerHTML =
          `<span class="lp-badge"></span>` +
          `<div class="lp-item-body"><div class="lp-item-text"></div><span class="lp-chip" style="display:none"></span></div>` +
          `<button class="lp-edit" aria-label="Edit comment">${ICON.edit(14)}</button>` +
          `<button class="lp-del" aria-label="Delete comment">${ICON.trash(14)}</button>`;
        row.addEventListener('mouseenter', () => {
          if (state.target || !comments[index] || !comments[index].selector) return;
          state.hoverId = index;
          updateHighlight();
        });
        row.addEventListener('mouseleave', () => {
          state.hoverId = null;
          updateHighlight();
        });
        row.querySelector('.lp-edit').addEventListener('click', () => openEditComposer(index));
        row.querySelector('.lp-del').addEventListener('click', () => {
          state.confirmDeleteId = index;
          renderList();
        });
        listNode.appendChild(row);
        rowNodes.set(index, row);
      }
      const isElement = !!comment.selector;
      const badge = row.querySelector('.lp-badge');
      badge.className = 'lp-badge ' + (isElement ? 'element' : 'general');
      badge.textContent = String(index + 1);
      row.querySelector('.lp-item-text').textContent = comment.body;
      const chipEl = row.querySelector('.lp-chip');
      const label = isElement ? firstLineLabel(comment.text) : '';
      const showChip = isElement ? !!label : true;
      chipEl.style.display = showChip ? '' : 'none';
      if (showChip) chipEl.textContent = isElement ? label : 'General comment';
      // Toggle the confirm overlay in place (slide in on arm, slide out on cancel).
      const confirming = state.confirmDeleteId === index;
      const liveConfirm = row.querySelector('.lp-item-confirm:not([data-exiting])');
      if (confirming && !liveConfirm) {
        row.querySelectorAll('.lp-item-confirm').forEach((node) => node.remove());
        row.insertAdjacentHTML('beforeend', buildItemConfirm(index));
        bindItemConfirm(row.querySelector('.lp-item-confirm'), index);
      } else if (!confirming && liveConfirm) {
        slideOut(liveConfirm);
      }
    });
  };

  // The critical state for a rejected token. Built once and sticky — a bad token cannot
  // recover without a fresh page load (with a corrected embed), so there is nothing to
  // retry or dismiss; the copy tells the embedder how to fix it.
  const renderFatal = () => {
    if (fatalNode.dataset.shown) return;
    fatalNode.dataset.shown = '1';
    fatalNode.innerHTML = `<div class="lp-fatal">
        <div class="lp-fatal-disc">${ICON.alert(22)}</div>
        <div class="lp-fatal-title">This review widget can’t connect</div>
        <div class="lp-fatal-sub">${fatalDetail(state.fatal || {})}</div>
      </div>`;
  };

  const sync = () => {
    updatePanel();
    renderOverlay();
  };

  // The comment is live the moment the API accepts it, and nothing else on screen
  // says so — the pin and the row look identical to an unsaved one. This is that
  // acknowledgement, on the same toast furniture pick mode uses.
  let savedToastTimer = 0;
  const flashSaved = (text) => {
    state.savedNotice = text;
    clearTimeout(savedToastTimer);
    savedToastTimer = setTimeout(() => {
      state.savedNotice = null;
      renderOverlay();
    }, 2400);
  };

  // ---- composer / focus ----
  const focusTextarea = () => {
    const t = textareaNode;
    t.focus();
    const end = t.value.length;
    t.setSelectionRange(end, end);
  };
  const openNoteComposer = () => {
    if (state.fatal) return;
    state.composing = true;
    state.composeTarget = { type: 'general' };
    state.editId = null;
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
      state.editId = null;
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
      // Array.from splits on code points, so a truncation cannot land inside an
      // emoji and produce the broken character the API rejects.
      text: Array.from((el.innerText || '').trim())
        .slice(0, TEXT_MAX)
        .join(''),
      label: firstLineLabel(el.innerText),
    };
    state.editId = null;
    state.draft = '';
    textareaNode.value = '';
    state.open = true;
    setTargeting(false);
    sync();
    focusTextarea();
  };
  // Re-open the composer pre-filled to edit an existing comment in place. The anchor
  // (selector/text) is preserved and rebuilt from storage; only the body is editable.
  const openEditComposer = (index) => {
    const comment = comments[index];
    if (!comment) return;
    state.composing = true;
    state.editId = comment.id;
    state.composeTarget = comment.selector
      ? {
          type: 'element',
          el: resolveElement(comment), // may be null when the anchor is off-page — fine
          selector: comment.selector,
          text: comment.text,
          label: firstLineLabel(comment.text),
        }
      : { type: 'general' };
    state.draft = comment.body;
    textareaNode.value = comment.body;
    state.open = true;
    setTargeting(false);
    sync();
    focusTextarea();
  };
  const cancelCompose = () => {
    state.composing = false;
    state.composeTarget = null;
    state.editId = null;
    state.draft = '';
    textareaNode.value = '';
    sync();
  };
  const saveComment = async () => {
    const body = state.draft.trim();
    if (!body || state.saving) return;
    const editing = state.editId != null;
    state.saving = true;
    state.actionError = null;
    updatePanel();
    try {
      await ready; // don't let the boot refresh clobber an early save
      if (editing) {
        // Resolve by server id — a concurrent delete, or the reconcile after a 404,
        // may have dropped the row. Nothing was written then, so say so rather than
        // closing the composer under a "saved" toast the reviewer would believe.
        const target = comments.find((c) => c.id === state.editId);
        if (!target) throw Object.assign(new Error('HTTP 404'), { status: 404 });
        await api('PATCH', `/api/site-review/comments/${target.id}`, { body });
        target.body = body;
      } else {
        const ct = state.composeTarget || { type: 'general' };
        const comment =
          ct.type === 'element'
            ? { body, selector: ct.selector, text: ct.text, url: location.href }
            : { body, selector: '', text: '', url: location.href };
        const { commentId } = await api('POST', '/api/site-review/comments', comment);
        comments.push({ id: commentId, ...comment });
      }
      state.composing = false;
      state.composeTarget = null;
      state.editId = null;
      state.draft = '';
      textareaNode.value = '';
      flashSaved(editing ? 'Comment updated' : 'Comment saved');
    } catch (error) {
      // A rejected token is fatal; anything else keeps the composer open with the text
      // intact so nothing is lost. A 404 means the agent addressed it mid-edit, so the
      // list is stale — reconcile it.
      if (authFailed(error)) enterFatal(error);
      else {
        state.actionError = error;
        if (error && error.status === 404) await refresh();
      }
    }
    state.saving = false;
    sync();
  };

  // ---- destructive actions (every one is confirmed) ----
  const removeComment = async (index) => {
    const target = comments[index];
    if (!target) return;
    if (state.deleting) return;
    state.deleting = true;
    sync(); // paint the disabled footer now, or the click below is silently dropped
    try {
      await ready; // don't let the boot refresh clobber an early delete
      await api('DELETE', `/api/site-review/comments/${target.id}`);
      comments.splice(index, 1);
    } catch (error) {
      if (authFailed(error)) enterFatal(error);
      else {
        state.actionError = error;
        if (error && error.status === 404) await refresh();
      }
    }
    state.deleting = false;
    state.confirmDeleteId = null;
    state.pinConfirmId = null;
    state.hoverId = null;
    state.hoverPinId = null;
    if (!comments.length) state.listExpanded = false;
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
  const confirmClearYes = async () => {
    if (state.deleting) return;
    state.deleting = true;
    try {
      await ready; // don't let the boot refresh clobber an early clear
      // allSettled, not all: a rejection from one delete must not send us to
      // refresh() while the rest are still in flight, or the rehydrate races
      // them and restores rows that are about to disappear.
      const results = await Promise.allSettled(
        comments.map((comment) => api('DELETE', `/api/site-review/comments/${comment.id}`)),
      );
      const failure = results.find((result) => 'rejected' === result.status);
      if (!failure) {
        comments = [];
      } else if (authFailed(failure.reason)) {
        enterFatal(failure.reason);
      } else {
        // Some deletes landed, and a 404 just means the agent addressed one
        // first — either way the server is the truth now.
        state.actionError = failure.reason;
        await refresh();
      }
    } catch (error) {
      if (authFailed(error)) {
        enterFatal(error);
      } else {
        state.actionError = error;
        await refresh();
      }
    }
    state.deleting = false;
    state.confirmClear = false;
    state.listExpanded = false;
    state.composing = false;
    state.composeTarget = null;
    state.editId = null;
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
    } else {
      state.toastDock = 'top';
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
    if (state.fatal) return;
    const on = !state.target;
    if (on) {
      state.composing = false;
      state.composeTarget = null;
      state.editId = null;
      state.draft = '';
      textareaNode.value = '';
      state.open = true;
    }
    setTargeting(on);
    sync();
  };
  const onMove = (event) => {
    if (!state.target) return;
    updateToastDodge(event.clientY);
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

  // ---- panel open/close ----
  const togglePanel = () => {
    state.open = !state.open;
    if (!state.open) {
      setTargeting(false);
      state.composing = false;
      state.composeTarget = null;
      state.editId = null;
      state.draft = '';
      textareaNode.value = '';
    }
    sync();
  };

  // ---- static bindings (persistent nodes) ----
  $('lp-launch-main').addEventListener('click', togglePanel);
  $('lp-launch-note').addEventListener('click', openNoteComposer);
  $('lp-launch-target').addEventListener('click', toggleTarget);
  // The launcher starts expanded (panel closed), so allow tooltip overflow now; it is
  // re-clipped on collapse and re-opened here once the expand transition finishes.
  launchQuick.style.overflow = 'visible';
  launchQuick.addEventListener('transitionend', (event) => {
    if (event.propertyName === 'max-width' && !state.open) {
      launchQuick.style.overflow = 'visible';
    }
  });
  $('lp-close').addEventListener('click', togglePanel);
  generalBtn.addEventListener('click', toggleNote);
  targetBtn.addEventListener('click', toggleTarget);
  $('lp-cancel').addEventListener('click', cancelCompose);
  $('lp-save').addEventListener('click', saveComment);
  $('lp-list-toggle').addEventListener('click', () => {
    state.listExpanded = !state.listExpanded;
    sync();
  });
  $('lp-clear').addEventListener('click', armClear);
  $('lp-clear-cancel').addEventListener('click', cancelClear);
  $('lp-clear-yes').addEventListener('click', confirmClearYes);
  $$('lp-toast-esc').addEventListener('click', () => {
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
    // Single-key shortcuts only while the panel is open, no modifier held, not typing —
    // and never in the fatal state, where there is nothing to compose.
    if (!state.open || state.fatal) return;
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
  const rerenderAnchors = () => {
    renderPins();
    updateHighlight();
  };
  let lastSeenUrl = location.href;
  const handleLocationChange = () => {
    if (location.href === lastSeenUrl) return;
    lastSeenUrl = location.href;
    state.hoverId = null;
    state.hoverPinId = null;
    state.pinConfirmId = null;
    rerenderAnchors();
    // Under Turbo the <body> is swapped *after* the URL changes, so the new page's
    // anchors resolve to null on this tick and would never reappear until a scroll.
    // Re-run as the new DOM settles.
    requestAnimationFrame(rerenderAnchors);
    setTimeout(rerenderAnchors, 60);
    setTimeout(rerenderAnchors, 240);
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
  // Turbo swaps the body without a history method we wrap; re-anchor on its render
  // events too (harmless no-ops when Turbo is absent).
  ['turbo:load', 'turbo:render', 'turbo:frame-load'].forEach((evt) =>
    document.addEventListener(evt, rerenderAnchors),
  );

  const ready = refresh({ firstLoad: true });
  sync();
  ready.then(sync);
})();
