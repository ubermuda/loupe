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
  // comments, the ones this reviewer may still edit or delete; once the agent marks
  // one addressed it drops out. Each item: { id, body, url, anchors }, and an anchor is
  // { selector, text, quote, quotePrefix, quoteSuffix } — several when the comment says
  // how elements relate, none for a page note, and quoting when it points at a run of
  // text inside its element rather than at the whole element.
  let comments = [];
  // Whether this instance offers freehand drawing. The boot load carries it,
  // because the embed snippet lives in someone else's page and nobody re-pastes
  // it. Off until the server says otherwise: a widget that offered a control
  // the API then refuses would lose the reviewer's gesture. Strokes already
  // saved render whatever this says, so switching the flag off hides no data.
  let drawingEnabled = false;
  const MAX_ANCHORS = 10; // AddCommentRequest's cap — over it the API 422s
  const AT_CAP_MESSAGE = `A comment can point at ${MAX_ANCHORS} elements at most.`;
  // AddCommentRequest's caps for the drawing. A stroke past the point cap stops
  // growing rather than 422ing the save the reviewer already committed to.
  const MAX_STROKES = 50;
  const MAX_STROKE_POINTS = 500;
  const AT_STROKE_CAP_MESSAGE = `A comment can carry ${MAX_STROKES} strokes at most.`;
  const MIN_POINT_GAP = 2; // px of pointer travel before another point is kept
  // SiteReviewAnchorInput caps quote, quotePrefix and quoteSuffix at 2000 each. The
  // widget refuses well under that, because a quote long enough to approach it is a
  // whole section rather than the passage the reviewer means.
  const QUOTE_MAX = 1000;
  const LONG_QUOTE_MESSAGE = `A quote can be ${QUOTE_MAX} characters at most. Select a shorter passage.`;

  // Hold this key to add another element to the comment being composed. Ctrl is
  // the modifier away from a Mac, where Ctrl+click is a right click.
  // Either source is enough, because Ctrl+click on a Mac is a right click and a
  // widget that reads one spoofed value wrong there picks nothing at all.
  // userAgentData says 'macOS', navigator.platform says 'MacIntel'.
  const IS_MAC = /mac|iphone|ipad|ipod/i.test(
    `${navigator.platform || ''} ${(navigator.userAgentData && navigator.userAgentData.platform) || ''}`,
  );
  const MOD_KEY = IS_MAC ? 'Meta' : 'Control';
  const MOD_LABEL = IS_MAC ? '⌘' : 'Ctrl';
  const modHeld = (event) => (IS_MAC ? event.metaKey : event.ctrlKey);

  // Anchors of a comment, tolerating a pre-anchors server that sends only the
  // scalar selector/text pair.
  const anchorsOf = (comment) => {
    if (Array.isArray(comment.anchors)) return comment.anchors;
    return comment.selector ? [{ selector: comment.selector, text: comment.text || '' }] : [];
  };

  // Strokes of a saved comment, tolerating a server that predates the column.
  const strokesOf = (comment) => (Array.isArray(comment.strokes) ? comment.strokes : []);

  // Demo transport: an in-memory list that dies with the page. Same four calls,
  // same shapes, same 404 for a row that is gone — so the widget cannot tell.
  const demoStore = { comments: [], nextId: 1 };
  const demoApi = async (method, path, body) => {
    if (method === 'GET') {
      return {
        // The demo has no instance behind it, so it shows the whole widget.
        drawingEnabled: true,
        comments: demoStore.comments.map((comment) => ({
          ...comment,
          anchors: (comment.anchors || []).map((anchor) => ({ ...anchor })),
        })),
      };
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
    setDrawing(false);
    state.strokes = [];
    state.composing = false;
    state.composeTarget = null;
    state.editId = null;
    state.draft = '';
    state.actionError = null;
    state.savedNotice = null;
    state.quotePick = null;
    textareaNode.value = '';
  };

  // Rehydrate the list from the project's Pending comments.
  const refresh = async ({ firstLoad = false } = {}) => {
    try {
      const payload = await api('GET', '/api/site-review/review');
      comments = payload.comments || [];
      drawingEnabled = true === payload.drawingEnabled;
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

  const queryOne = (selector) => {
    try {
      return document.querySelector(selector);
    } catch {
      return null;
    }
  };

  // ---- text quotes (the W3C TextQuoteSelector shape) ----
  // Characters of context kept on each side of a quote, and how many of them a
  // match is confirmed on. Both mirror the document reviewer's AnchorService,
  // which solved this same problem against a rendered document.
  const QUOTE_CONTEXT = 32;
  const QUOTE_FINGERPRINT = 8;

  // The last `count` codepoints of `text` ending at the UTF-16 offset `index`.
  // A plain slice would build a shorter window, and could halve a surrogate
  // pair, around an emoji.
  const beforeText = (text, index, count) =>
    Array.from(text.slice(Math.max(0, index - count * 2), index))
      .slice(-count)
      .join('');
  // The first `count` codepoints of `text` starting at the UTF-16 offset `index`.
  const afterText = (text, index, count) =>
    Array.from(text.slice(index, index + count * 2))
      .slice(0, count)
      .join('');

  const textWalker = (root) => document.createTreeWalker(root, NodeFilter.SHOW_TEXT, null);

  // Offset at which `element`'s own text begins within `root`'s textContent.
  const elementStartOffset = (root, element) => {
    const walker = textWalker(root);
    let offset = 0;
    for (let node = walker.nextNode(); node; node = walker.nextNode()) {
      // A descendant of the element reports FOLLOWING too, so this stops at the
      // element's own subtree as well as at anything after it.
      if (element.compareDocumentPosition(node) & Node.DOCUMENT_POSITION_FOLLOWING) break;
      offset += node.textContent.length;
    }
    return offset;
  };

  // Offset of (node, offsetInNode) within `root`'s textContent. A range boundary
  // can land on an element rather than a text node — a triple-click selects a
  // whole block — and the offset then counts child nodes, not characters.
  const textOffsetIn = (root, node, offsetInNode) => {
    if (node.nodeType === 1) {
      const before = [...node.childNodes]
        .slice(0, offsetInNode)
        .reduce((total, child) => total + child.textContent.length, 0);
      return elementStartOffset(root, node) + before;
    }
    const walker = textWalker(root);
    let offset = 0;
    for (let current = walker.nextNode(); current; current = walker.nextNode()) {
      if (current === node) return offset + offsetInNode;
      offset += current.textContent.length;
    }
    return offset;
  };

  // A [start, end) span of `root`'s textContent as a live DOM Range.
  const rangeForSpan = (root, start, end) => {
    const walker = textWalker(root);
    const range = document.createRange();
    let offset = 0;
    let startSet = false;
    for (let node = walker.nextNode(); node; node = walker.nextNode()) {
      const length = node.textContent.length;
      if (!startSet && start <= offset + length) {
        range.setStart(node, start - offset);
        startSet = true;
      }
      if (startSet && end <= offset + length) {
        range.setEnd(node, end - offset);
        return range;
      }
      offset += length;
    }
    return null;
  };

  // Where an anchor's quote reads now, as a Range, or null when it no longer
  // reads at all, and null again when nothing separates two occurrences.
  // textContent, never innerText: innerText collapses whitespace, so it would
  // count different characters from the ones the capture counted.
  const quoteRange = (el, anchor) => {
    const quote = (anchor && anchor.quote) || '';
    if (!el || !quote) return null;
    const haystack = el.textContent || '';
    const hits = [];
    for (let at = haystack.indexOf(quote); at !== -1; at = haystack.indexOf(quote, at + 1)) {
      hits.push(at);
    }
    if (!hits.length) return null;
    const prefix = anchor.quotePrefix || '';
    const suffix = anchor.quoteSuffix || '';
    const score = (start) => {
      let value = 0;
      const head = beforeText(haystack, start, QUOTE_CONTEXT);
      const tail = afterText(haystack, start + quote.length, QUOTE_CONTEXT);
      if (prefix && head.endsWith(beforeText(prefix, prefix.length, QUOTE_FINGERPRINT))) value += 1;
      if (suffix && tail.startsWith(afterText(suffix, 0, QUOTE_FINGERPRINT))) value += 1;
      return value;
    };
    const ranked = hits
      .map((at) => ({ at, rank: score(at) }))
      .sort((a, b) => b.rank - a.rank || a.at - b.at);
    // Resolve only when one occurrence outranks every other. A tie means the
    // stored context does not say which one the reviewer meant, and position
    // alone would be a coin flip, so the anchor degrades to its element. A lone
    // hit needs no context, because there is nothing else it could be.
    if (ranked.length > 1 && ranked[0].rank === ranked[1].rank) return null;
    const start = ranked[0].at;
    try {
      return rangeForSpan(el, start, start + quote.length);
    } catch {
      return null;
    }
  };

  // Every anchor paired with the element it resolves to on this page, or null
  // when it no longer matches. Empty off-page and for an unanchored comment.
  // `range` is resolved fresh every pass, because the host page's nodes are
  // replaced under it. A stale quote leaves it null and `el` intact, which is
  // how the anchor degrades to its element instead of dropping the comment.
  const resolveAnchors = (comment) => {
    if (comment.url !== location.href) return [];
    return anchorsOf(comment).map((anchor, anchorIndex) => {
      const el = anchor.selector ? queryOne(anchor.selector) : null;
      return { anchor, anchorIndex, el, range: quoteRange(el, anchor) };
    });
  };

  // A comment is degraded when it was anchored to several elements and at least
  // one of them is gone. Rendering the survivors as if the comment had always
  // been about them would misstate what the reviewer said, so the widget says so.
  // A single-anchor comment that no longer resolves is still dropped in silence.
  const anchorHealth = (comment) => {
    const resolved = resolveAnchors(comment);
    const found = resolved.filter((entry) => entry.el);
    return { resolved, found, total: resolved.length, degraded: resolved.length > 1 && found.length < resolved.length };
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

  // What an anchor is called: the text it quotes, or its element's own text when
  // it quotes nothing. Pass kind 'element' where the widget is drawing the
  // element rather than the quote, so the name matches the box on the page.
  const anchorLabel = (anchor, kind) =>
    firstLineLabel('element' === kind ? anchor.text : anchor.quote || anchor.text);

  // Where a comment was made, as a short path. The reviewer only needs to tell one
  // page from another, so the origin is dropped and the raw string is the fallback
  // when the URL will not parse — a label beats none.
  const pageLabel = (raw) => {
    const value = String(raw || '');
    let label;
    try {
      const parsed = new URL(value, location.href);
      label = parsed.pathname + parsed.search;
    } catch {
      label = value;
    }
    if (!label) return '';
    return label.length > 32 ? label.slice(0, 31).trim() + '…' : label;
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
    arrowOut: (s) => svg(s, '<path d="M7 17 17 7"/><polyline points="8 7 17 7 17 16"/>', 2),
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
    pen: (s) =>
      svg(
        s,
        '<path d="M12 19l7-7 3 3-7 7-3-3z"/><path d="M18 13l-1.5-7.5L2 2l3.5 14.5L13 18l5-5z"/><path d="M2 2l7.586 7.586"/><circle cx="11" cy="11" r="2"/>',
        1.9,
      ),
    glyph: (s) =>
      `<svg width="${s}" height="${s}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" ` +
      `stroke-linecap="round"><circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="2.4" fill="currentColor" stroke="none"/></svg>`,
    quote: (s) =>
      svg(
        s,
        '<path d="M9 7H5a2 2 0 0 0-2 2v3a2 2 0 0 0 2 2h2v1a3 3 0 0 1-3 3"/><path d="M19 7h-4a2 2 0 0 0-2 2v3a2 2 0 0 0 2 2h2v1a3 3 0 0 1-3 3"/>',
        1.9,
      ),
    corners: (s) =>
      svg(s, '<path d="M4 9V4h5"/><path d="M15 4h5v5"/><path d="M20 15v5h-5"/><path d="M9 20H4v-5"/>', 1.9),
    alert: (s) =>
      svg(
        s,
        '<path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>',
        1.9,
      ),
  };

  // ---- launcher corner ----
  // The launcher is fixed to one corner, and a host page may pin its own chrome
  // to that same corner. Both routes out of a collision write here: the panel's
  // move control walks this list, and a drag snaps to the nearest entry.
  const CORNERS = ['bottom-right', 'bottom-left', 'top-left', 'top-right'];
  const CORNER_LABEL = {
    'bottom-right': 'bottom right',
    'bottom-left': 'bottom left',
    'top-left': 'top left',
    'top-right': 'top right',
  };
  const CORNER_KEY = 'loupe.site-review.corner';
  // Reading and writing site data throws outright in a private window, or where
  // the visitor blocks it, and this script runs on other people's pages. Both
  // sides swallow that: the default corner is a correct answer.
  const readCorner = () => {
    try {
      const stored = window.localStorage.getItem(CORNER_KEY);
      if (CORNERS.includes(stored)) return stored;
    } catch {
      /* storage unavailable — fall through to the default */
    }
    return CORNERS[0];
  };
  const writeCorner = (corner) => {
    try {
      window.localStorage.setItem(CORNER_KEY, corner);
    } catch {
      /* storage unavailable — the corner still holds for this page */
    }
  };

  // ---- widget state (the reviewer's comments live in `comments`; this is UI state) ----
  // Comments are identified by their index in `comments`; every mutation re-renders.
  const state = {
    open: false,
    target: false,
    drawing: false, // the freehand canvas is taking pointer events
    // Strokes of the comment being composed, each a list of [x, y] in document
    // pixels. They become fractions at save time, never before, because the
    // page can scroll or the anchor can change between the drag and the save.
    strokes: [],
    composing: false,
    // { type:'general' } | { type:'element', anchors: [{ el, selector, text, label }] }
    composeTarget: null,
    draft: '',
    addAnchor: false, // the add-another-element modifier is held down
    modCancelled: false, // another key went down during this hold, so it adds nothing
    editId: null, // server id of the comment being edited in place, or null for a new one
    listExpanded: false,
    expandLevel: 0,
    corner: readCorner(), // which corner the launcher and the panel are pinned to
    toastDock: 'top', // 'top' | 'bottom' — the pick-mode toast dodges away from the cursor
    moveHL: null, // { left, top, width, height, label } while picking
    quotePick: null, // { range, anchor } — a live selection offering to be quoted
    hoverId: null, // hovered comment index (list row)
    hoverAnchor: null, // anchor index whose on-page remove control is revealed
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
  let liveStroke = null; // the stroke under the pointer right now, or null

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
      /* The panel is a fixed-size popover and picking drives off hover, so the
         widget hides rather than degrades: below the app's own mobile boundary,
         and on any touch-primary device regardless of width — an embedder page
         with no viewport meta reports ~980px on a phone, so width alone would
         miss it. A media query, not a boot-time check: it follows a rotation or
         a resize back into view on its own. */
      @media (max-width:639px),(hover:none) and (pointer:coarse){:host{display:none}}
      @keyframes lp-spin{to{transform:rotate(360deg)}}
      @keyframes lp-pop{from{transform:translateY(8px) scale(.985)}to{transform:none}}
      @keyframes lp-pop-down{from{transform:translateY(-8px) scale(.985)}to{transform:none}}
      @keyframes lp-slide-left{from{transform:translateX(-100%)}to{transform:translateX(0)}}
      @keyframes lp-slide-left-out{from{transform:translateX(0)}to{transform:translateX(-100%)}}
      .lp-scroll::-webkit-scrollbar{width:10px;height:10px}
      .lp-scroll::-webkit-scrollbar-thumb{background:var(--faint);border-radius:9px;border:3px solid transparent;background-clip:content-box}
      .lp-scroll::-webkit-scrollbar-track{background:transparent}

      .lp-launcher{position:fixed;right:20px;bottom:20px;height:46px;padding:0 7px;display:flex;align-items:center;gap:0;background:var(--bar-bg);border:1px solid var(--bar-line);border-radius:999px;box-shadow:var(--bar-shadow);font-family:var(--font);pointer-events:auto;cursor:grab;user-select:none;-webkit-user-select:none;transition:box-shadow .14s ease,background .25s ease}
      /* Corner placement. Only the two offsets move: the launcher keeps one
         shape in every corner, so the open/close collapse is untouched. */
      .lp-launcher.at-left{right:auto;left:20px}
      .lp-launcher.at-top{bottom:auto;top:20px}
      /* While dragging, the launcher follows the pointer on left/top, so both
         corner offsets are cleared inline and the raised shadow says it is loose. */
      .lp-launcher.dragging{cursor:grabbing;box-shadow:0 14px 34px rgba(15,15,13,.5)}
      .lp-launcher.dragging *{cursor:grabbing}
      /* The quick actions collapse as one unit when the panel opens. max-width + opacity
         animate the slide-away; visibility flips to hidden only after the collapse (the
         .24s delay) so the buttons are genuinely non-interactive once gone, and back
         immediately on expand. */
      /* max-width is the collapse animation's start, so it has to clear the row
         and stay near it. Three actions and the divider measure 118px, and the
         travel above that is time the collapse spends going nowhere. */
      .lp-launch-quick{display:flex;align-items:center;gap:3px;overflow:hidden;max-width:130px;opacity:1;visibility:visible;transition:max-width .24s cubic-bezier(.4,0,.2,1),opacity .18s ease,visibility 0s 0s}
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
      /* A tooltip above a launcher docked at the top would sit off screen, so
         it hangs below instead. The hover rule is restated because the corner
         selector outranks the plain one. */
      .lp-launcher.at-top [data-tip]::after{bottom:auto;top:calc(100% + 8px);transform:translateX(-50%) translateY(-3px)}
      .lp-launcher.at-top [data-tip]:hover::after{transform:translateX(-50%) translateY(0)}
      .lp-count{display:inline-flex;align-items:center;justify-content:center;min-width:20px;height:20px;padding:0 7px;border-radius:999px;font-size:11.5px;font-weight:700}
      .lp-count.solid{background:var(--accent);color:var(--on-accent)}
      .lp-count.soft{background:var(--accent-tint);color:var(--accent-ink)}
      .lp-count.danger{background:var(--danger);color:#fff}

      .lp-panel{position:fixed;right:20px;bottom:78px;width:348px;max-height:calc(100vh - 160px);display:flex;flex-direction:column;background:var(--panel-bg);border:1px solid var(--panel-border);border-radius:16px;box-shadow:var(--shadow);pointer-events:auto;overflow:hidden;font-family:var(--font);color:var(--text);animation:lp-pop .2s cubic-bezier(.2,.9,.3,1);transition:background .25s ease,border-color .25s ease}
      /* The panel opens away from the launcher's edge. 78px clears the 46px
         launcher plus its 20px inset, and the 160px max-height budget covers
         that offset at either end, so no corner grows off screen. */
      .lp-panel.at-left{right:auto;left:20px}
      .lp-panel.at-top{bottom:auto;top:78px;animation-name:lp-pop-down}
      .lp-main{display:flex;flex-direction:column;min-height:0;flex:1 1 auto}
      .lp-header{flex:0 0 auto;display:flex;align-items:center;gap:9px;padding:14px 14px 12px 17px}
      .lp-title{font-size:15px;font-weight:700;letter-spacing:-.01em}
      .lp-spacer{flex:1}
      .lp-iconbtn{width:28px;height:28px;border:0;background:transparent;color:var(--muted);border-radius:999px;display:flex;align-items:center;justify-content:center;cursor:pointer}
      .lp-iconbtn:hover{background:var(--panel-elev);color:var(--text)}
      .lp-iconbtn:focus-visible{outline:2px solid var(--accent-ink);outline-offset:2px}

      .lp-composer{flex:0 0 auto;overflow:hidden;transition:max-height .27s cubic-bezier(.4,0,.2,1),opacity .2s ease}
      .lp-composer-inner{padding:2px 16px 14px}
      /* The composer's height is fixed and it clips, so the chips scroll rather
         than push the textarea and the buttons out of the box. */
      .lp-compose-head{display:flex;align-items:center;gap:7px;margin-bottom:9px;min-height:21px;flex-wrap:wrap;max-height:90px;overflow-y:auto;overscroll-behavior:contain}
      .lp-compose-general{display:inline-flex;align-items:center;gap:6px;font-size:11.5px;color:var(--muted)}
      .lp-dot{width:7px;height:7px;border-radius:50%;border:1.5px dashed var(--faint)}
      .lp-compose-chip{flex:0 1 auto;min-width:0;display:inline-flex;align-items:center;gap:5px;height:21px;padding:0 9px;background:var(--accent-tint);color:var(--accent-ink);border-radius:999px;font-size:11px;font-weight:600;overflow:hidden}
      .lp-compose-chip span{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
      /* The pill names one anchor, so it is a control: point at it and its
         element is emphasised on the page, click it and the page scrolls to it. */
      .lp-chip-name{flex:0 1 auto;min-width:0;display:inline-flex;align-items:center;gap:5px;border:0;padding:0;background:transparent;color:inherit;font-family:inherit;font-size:inherit;font-weight:inherit;line-height:1;cursor:pointer}
      .lp-chip-name:focus-visible{outline:2px solid var(--accent-ink);outline-offset:2px;border-radius:999px}
      .lp-compose-chip.lit{background:var(--accent);color:var(--on-accent)}
      .lp-chip-x{flex:0 0 auto;border:0;background:transparent;color:inherit;font-family:inherit;font-size:13px;line-height:1;padding:0;margin-right:-3px;cursor:pointer;opacity:.6}
      .lp-chip-x:hover{opacity:1}
      .lp-chip-x:focus-visible{opacity:1;outline:2px solid var(--accent-ink);outline-offset:2px;border-radius:999px}
      .lp-compose-hint{flex:0 0 auto;height:21px;line-height:21px;color:var(--muted);font-size:11px;font-weight:600}
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

      /* Three capture modes share one 320px row, so each label is one word and
         the keyboard hints moved to the docs. The full name stays the button's
         accessible name, which is what a screen reader and the specs read. */
      .lp-actions{flex:0 0 auto;display:flex;gap:7px;padding:0 14px 12px}
      .lp-action{flex:1;min-width:0;height:38px;display:flex;align-items:center;justify-content:center;gap:7px;border-radius:999px;font-family:inherit;font-size:13px;font-weight:600;white-space:nowrap;cursor:pointer;border:0;background:var(--chip-bg);color:var(--text);transition:background .15s ease,color .15s ease}
      /* An icon is a flex item with a min-content width of zero, so a row one
         pixel too tight silently squashes it instead of overflowing. */
      .lp-action svg{flex:0 0 auto}
      .lp-action:hover{background:var(--field-focus)}
      /* Pressed, not primary: a solid accent fill here would compete with the
         Save button for the eye, so the toggle takes the pale tint. */
      .lp-action.active{background:var(--accent-tint);color:var(--accent-ink);box-shadow:inset 0 0 0 1px var(--accent-border)}
      .lp-action[disabled]{opacity:.5;cursor:default}
      .lp-action[disabled]:hover{background:var(--chip-bg)}

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
      .lp-chip{display:inline-flex;align-items:center;gap:4px;margin-top:6px;height:19px;padding:0 9px;background:var(--chip-bg);color:var(--chip-text);border-radius:999px;font-size:10.5px;font-weight:600;max-width:100%;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;margin-right:6px}
      .lp-chip.degraded{background:#fef3c7;color:#92400e}
      /* Chip-shaped but a real button: it says which page the comment was made on
         and goes there. Only rendered when that page is not the current one. */
      .lp-item-page{display:inline-flex;align-items:center;gap:4px;margin-top:6px;height:19px;padding:0 9px;background:transparent;border:1px solid var(--hairline);color:var(--muted);border-radius:999px;font-family:inherit;font-size:10.5px;font-weight:600;max-width:100%;cursor:pointer}
      .lp-item-page:hover{background:var(--chip-bg);color:var(--accent-ink)}
      .lp-item-page-label{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
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
        <button class="lp-launch-action" id="lp-launch-draw" aria-label="Draw" data-tip="Draw">${ICON.pen(16)}</button>
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
        <button class="lp-iconbtn" id="lp-move">${ICON.corners(15)}</button>
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
              <div class="lp-empty-sub">Select text, pick an element, or add a note.</div>
            </div>
          </div>
        </div>
        <div class="lp-actions">
          <button class="lp-action" id="general" aria-pressed="false" aria-label="Add note">${ICON.comment(15)}<span>Note</span></button>
          <button class="lp-action" id="target" aria-pressed="false" aria-label="Pick element">${ICON.target(15)}<span>Element</span></button>
          <button class="lp-action" id="draw" aria-pressed="false" aria-label="Draw">${ICON.pen(15)}<span>Draw</span></button>
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
      /* The panel is a fixed-size popover and picking drives off hover, so the
         widget hides rather than degrades: below the app's own mobile boundary,
         and on any touch-primary device regardless of width — an embedder page
         with no viewport meta reports ~980px on a phone, so width alone would
         miss it. A media query, not a boot-time check: it follows a rotation or
         a resize back into view on its own. */
      @media (max-width:639px),(hover:none) and (pointer:coarse){:host{display:none}}
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
      /* A comment's other anchors. Same accent, no fill, so the framed one still
         reads as the one under the cursor. */
      .highlight.sib{background:transparent;border-width:1.5px;box-shadow:none;transition:none}
      /* The anchor whose pill is pointed at, or whose own box is. It stays the
         emphasised member of a set that all stays painted. */
      .highlight.lit{background:var(--accent-fill);border-width:3px;border-color:var(--accent-ink);box-shadow:0 2px 10px var(--pin-shadow)}
      /* Only a removable box takes pointer events, so an outline never eats a
         click on the host page outside the short window a comment is composed in. */
      .highlight.removable{pointer-events:auto}
      /* The controls live above every box rather than inside one. Two anchors on
         neighbouring elements have overlapping boxes, and a control drawn inside
         its own box would sit under its neighbour and refuse the click. */
      .lp-hl-x{position:fixed;z-index:5;display:flex;width:22px;height:22px;align-items:center;justify-content:center;padding:0;background:var(--accent);color:var(--on-accent);border:2px solid var(--pin-ring);border-radius:50%;font-family:inherit;font-size:14px;font-weight:700;line-height:1;cursor:pointer;pointer-events:auto;opacity:0;transition:opacity .12s ease;box-shadow:0 2px 8px var(--pin-shadow)}
      /* :focus reveals it, because a mousedown on it is prevented and cannot
         focus it. The ring is :focus-visible, so only a keyboard user sees one. */
      .lp-hl-x.lit,.lp-hl-x:hover,.lp-hl-x:focus{opacity:1}
      .lp-hl-x:focus-visible{outline:2px solid var(--pin-ring);outline-offset:2px}
      .lp-hl-label{position:absolute;left:-2px;top:-27px;display:inline-block;max-width:240px;height:21px;line-height:21px;padding:0 9px;background:var(--accent);color:var(--on-accent);font-size:11px;font-weight:600;border-radius:999px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;box-shadow:0 2px 10px var(--pin-shadow)}
      /* Above the outlines, below the pins: the drawing is content over the
         page, and a pin still has to be reachable through it. */
      .lp-canvas{position:fixed;left:0;top:0;z-index:3;pointer-events:none}
      /* touch-action, so a pen or a touch drag on a hybrid laptop reaches
         pointermove instead of being claimed by panning, which would cancel
         the stroke. A touch-primary device hides the widget outright. */
      .lp-ov.drawing .lp-canvas{pointer-events:auto;cursor:crosshair;touch-action:none}
      .lp-pin-wrap{position:fixed;z-index:4;pointer-events:auto}
      .lp-ov.targeting .lp-pin-wrap,.lp-ov.drawing .lp-pin-wrap{pointer-events:none}
      .pin{width:24px;height:24px;border-radius:50% 50% 50% 2px;border:2px solid var(--pin-ring);background:var(--accent);color:var(--on-accent);font-family:inherit;font-size:12px;font-weight:700;display:flex;align-items:center;justify-content:center;cursor:pointer;box-shadow:0 2px 8px rgba(15,15,13,.35);animation:lp-pin .22s cubic-bezier(.2,1.3,.5,1)}
      .pin:hover{transform:scale(1.12)}
      .lp-pop{position:absolute;top:16px;right:0;width:240px;padding-top:14px;cursor:default}
      .lp-pop-card{position:relative;overflow:hidden;min-height:96px;padding:12px;background:var(--panel-bg);border:1px solid var(--panel-border);border-radius:12px;box-shadow:var(--shadow);animation:lp-fade .12s ease;display:flex;flex-direction:column}
      .lp-pop-body{font-size:12.5px;line-height:1.5;color:var(--text);word-break:break-word}
      .pin.degraded{border-style:dashed;border-color:#b45309}
      .lp-pop-degraded{margin-top:6px;font-size:11px;line-height:1.4;color:#b45309;word-break:break-word}
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
      .lp-toast-key{margin-left:2px;padding:3px 9px;background:var(--bar-raised);border:1px solid var(--bar-line);border-radius:999px;color:inherit;font-family:inherit;font-size:11px;font-weight:600;cursor:pointer;transition:background .12s ease}
      .lp-toast-key:hover{background:var(--bar-line)}
      .lp-toast-key[disabled]{opacity:.45;cursor:default}
      .lp-toast-key[disabled]:hover{background:var(--bar-raised)}
      .lp-toast--saved{padding:9px 15px}
      /* The offer to quote a selection. Same bar furniture as the toasts, so it
         reads as the widget rather than as host-page chrome. */
      .lp-quote-btn{position:fixed;z-index:6;display:inline-flex;align-items:center;gap:7px;height:30px;padding:0 12px;background:var(--bar-bg);border:1px solid var(--bar-line);color:var(--bar-fg);border-radius:999px;font-family:var(--font);font-size:12.5px;font-weight:600;white-space:nowrap;cursor:pointer;pointer-events:auto;box-shadow:var(--bar-shadow);animation:lp-fade .12s ease}
      .lp-quote-btn:hover{background:var(--bar-raised)}
      .lp-quote-btn:focus-visible{outline:2px solid var(--accent);outline-offset:2px}
    </style>
    <div class="lp-ov" id="lp-ov">
      <div class="lp-scrim" id="lp-scrim" style="display:none"></div>
      <div id="lp-hls"></div>
      <div class="highlight" id="lp-hl" style="display:none"><span class="lp-hl-label" id="lp-hl-label" style="display:none"></span></div>
      <canvas class="lp-canvas" id="lp-canvas"></canvas>
      <div id="lp-hlx"></div>
      <div id="lp-pins"></div>
      <div class="lp-toast" id="lp-toast" style="display:none">
        ${ICON.target(15)}
        <span id="lp-toast-text">Click to comment</span>
        <span class="lp-toast-sep">·</span>
        <span class="lp-toast-dim">⌥ scroll to resize</span>
        <span class="lp-toast-key" id="lp-toast-esc">Esc</span>
      </div>
      <div class="lp-toast" id="lp-draw-toast" style="display:none">
        ${ICON.pen(15)}
        <span id="lp-draw-text">Drag to draw</span>
        <span class="lp-toast-sep">·</span>
        <button class="lp-toast-key" id="lp-draw-undo" type="button">Undo</button>
        <button class="lp-toast-key" id="lp-draw-clear" type="button">Clear</button>
        <button class="lp-toast-key" id="lp-draw-done" type="button">Done</button>
      </div>
      <div class="lp-toast lp-toast--saved" id="lp-saved" style="display:none">
        ${ICON.check(15, 2.6, 'var(--success)')}
        <span id="lp-saved-text"></span>
      </div>
      <button class="lp-quote-btn" id="lp-quote-btn" type="button" style="display:none">
        ${ICON.quote(13)}<span>Comment on this text</span>
      </button>
    </div>`;

  const $ = (id) => root.getElementById(id);
  const $$ = (id) => overlayRoot.getElementById(id);

  const ovRoot = $$('lp-ov');
  const scrimNode = $$('lp-scrim');
  const hlsNode = $$('lp-hls');
  const hlNode = $$('lp-hl');
  const hlLabel = $$('lp-hl-label');
  const hlxNode = $$('lp-hlx');
  const pinsNode = $$('lp-pins');
  const canvasNode = $$('lp-canvas');
  const drawToastNode = $$('lp-draw-toast');
  const toastNode = $$('lp-toast');
  const toastText = $$('lp-toast-text');
  const savedToastNode = $$('lp-saved');
  const quoteBtn = $$('lp-quote-btn');
  const panelNode = $('lp-panel');
  const mainNode = $('lp-main');
  const fatalNode = $('lp-fatal');
  const composerNode = $('lp-composer');
  const composeHead = $('lp-compose-head');
  const textareaNode = $('lp-textarea');
  const errorNode = $('lp-error');
  const emptyAnim = $('lp-empty-anim');
  const launchQuick = $('lp-launch-quick');
  const launchDrawBtn = $('lp-launch-draw');
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
  const drawBtn = $('draw');

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
  // An element or a Range — both answer getBoundingClientRect, and a quoted
  // anchor is measured on its run of text rather than on its element. A range
  // over several lines gives one box spanning them.
  const rectOf = (box) => {
    const r = box.getBoundingClientRect();
    if (r.width === 0 && r.height === 0) return null;
    return r;
  };
  // What an anchor is drawn around: its quoted text when the quote still reads
  // on the page, and its element when it does not.
  const boxOf = (entry) => entry.range || entry.el;
  const anchorKind = (entry) => (entry.range ? 'quote' : 'element');

  // ---- freehand strokes ----
  // Strokes are held in document pixels while they are drawn and stored as
  // fractions. Document pixels are the only space that survives a scroll
  // between the drag and the save, and a fraction is the only one that survives
  // the page moving under a stroke that is already saved.
  const docPointOf = (event) => [event.clientX + window.scrollX, event.clientY + window.scrollY];
  const docRectOf = (el) => {
    const r = el && rectOf(el);
    if (!r) return null;
    return { left: r.left + window.scrollX, top: r.top + window.scrollY, width: r.width, height: r.height };
  };
  // Both axes divide by the width, so a page-space drawing keeps its shape. A
  // page taller than it is wide simply carries fractions above 1.
  const pageScale = () => Math.max(document.documentElement.scrollWidth, 1);
  // The box a stroke is measured against: anchor 0 of the comment, matching the
  // pin the reviewer sees first. A zero-sized box divides by nothing.
  const strokeBoxOf = (anchors) => {
    const first = anchors[0];
    const box = first && docRectOf(first.el || (first.selector ? queryOne(first.selector) : null));
    return box && box.width > 0 && box.height > 0 ? box : null;
  };
  // How far outside its box a stroke may reach and still be measured against
  // it. A drawing that stays near the element wants to move with it. One that
  // crosses the page from a small element does not: every fraction is then
  // multiplied by a tiny width, so a few pixels of reflow would fling the far
  // end of the stroke across the screen. Page coordinates are the honest space
  // for a page-scale gesture, and they keep the stored numbers small.
  const ANCHOR_SPACE_REACH = 20;
  const storeStroke = (points, box) => {
    const anchored = box
      ? points.map(([x, y]) => [(x - box.left) / box.width, (y - box.top) / box.height])
      : null;
    if (anchored && anchored.every(([x, y]) => Math.max(Math.abs(x), Math.abs(y)) <= ANCHOR_SPACE_REACH)) {
      return {
        space: 'anchor',
        points: anchored.map(([x, y]) => [Number(x.toFixed(5)), Number(y.toFixed(5))]),
      };
    }
    const scale = pageScale();
    return {
      space: 'page',
      points: points.map(([x, y]) => [Number((x / scale).toFixed(5)), Number((y / scale).toFixed(5))]),
    };
  };
  // Back to document pixels for painting. Null when an anchor-space stroke has
  // no box to measure against, which is how a drawing on a vanished element
  // disappears with it instead of landing somewhere arbitrary.
  const readStroke = (stroke, box) => {
    const points = Array.isArray(stroke && stroke.points) ? stroke.points : [];
    if (points.length < 2) return null;
    if (stroke.space === 'anchor') {
      if (!box) return null;
      return points.map(([x, y]) => [box.left + x * box.width, box.top + y * box.height]);
    }
    const scale = pageScale();
    return points.map(([x, y]) => [x * scale, y * scale]);
  };

  const STROKE_RING_WIDTH = 6;
  const STROKE_WIDTH = 3;
  const paintStroke = (ctx, points) => {
    const sx = window.scrollX;
    const sy = window.scrollY;
    ctx.beginPath();
    points.forEach(([x, y], index) => {
      if (index === 0) ctx.moveTo(x - sx, y - sy);
      else ctx.lineTo(x - sx, y - sy);
    });
    // Same near-black edge every marker the widget paints carries, so a stroke
    // stays legible over a host page of any colour.
    ctx.strokeStyle = CHROME['--pin-ring'];
    ctx.lineWidth = STROKE_RING_WIDTH;
    ctx.stroke();
    ctx.strokeStyle = CHROME['--accent'];
    ctx.lineWidth = STROKE_WIDTH;
    ctx.stroke();
  };

  const renderStrokes = () => {
    const dpr = window.devicePixelRatio || 1;
    const width = window.innerWidth;
    const height = window.innerHeight;
    const backingWidth = Math.round(width * dpr);
    const backingHeight = Math.round(height * dpr);
    if (canvasNode.width !== backingWidth || canvasNode.height !== backingHeight) {
      canvasNode.width = backingWidth;
      canvasNode.height = backingHeight;
      canvasNode.style.width = width + 'px';
      canvasNode.style.height = height + 'px';
    }
    const ctx = canvasNode.getContext('2d');
    ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    ctx.clearRect(0, 0, width, height);
    ctx.lineCap = 'round';
    ctx.lineJoin = 'round';
    comments.forEach((comment) => {
      // Same page gate the pins use: a drawing made elsewhere is not about this
      // page, whatever space it was stored in.
      if (comment.url !== location.href) return;
      const box = strokeBoxOf(anchorsOf(comment).filter((anchor) => anchor.selector));
      strokesOf(comment).forEach((stroke) => {
        const points = readStroke(stroke, box);
        if (points) paintStroke(ctx, points);
      });
    });
    if (!state.composing) return;
    state.strokes.forEach((points) => paintStroke(ctx, points));
    if (liveStroke && liveStroke.length > 1) paintStroke(ctx, liveStroke);
  };

  // ---- overlay render (scrim / highlight / pins / toast) ----
  const HIGHLIGHT_PADDING = 8; // px of breathing room around the targeted element

  // What the overlay outlines: one framed element (`move` while picking, or `el`),
  // plus `others`, the rest of the same comment's anchors. Every anchor of the
  // comment being composed stays outlined, and hovering one anchor of a saved
  // comment lights its siblings.
  const highlightTargets = () => {
    const composed = state.composing
      ? composeAnchors()
          .map((entry, anchorIndex) => ({
            el: entry.el,
            range: entry.range,
            label: entry.label,
            anchorIndex,
          }))
          .filter((entry) => entry.el)
      : [];
    // The box carries a remove control only while a new comment is composed and
    // both the picker and the canvas are off. An edit PATCHes the body alone
    // and cannot change anchors, a live picker needs the boxes out of the way,
    // and a removable box would swallow the drag that starts a stroke.
    const removable = state.editId == null && !state.target && !state.drawing;
    const mark = (entry) => ({
      el: entry.el,
      range: entry.range,
      anchorIndex: entry.anchorIndex,
      removeIndex: removable ? entry.anchorIndex : null,
    });
    if (state.target) {
      return { move: state.moveHL, others: composed.map(mark) };
    }
    if (composed.length) {
      const last = composed[composed.length - 1];
      return {
        el: last.el,
        range: last.range,
        label: last.label,
        anchorIndex: last.anchorIndex,
        removeIndex: mark(last).removeIndex,
        others: composed.slice(0, -1).map(mark),
      };
    }
    if (state.hoverPinId != null) {
      // The pin key is `${commentIndex}:${anchorIndex}` — frame that one anchor.
      const [commentIndex, anchorIndex] = String(state.hoverPinId).split(':').map(Number);
      const found = resolveAnchors(comments[commentIndex] || {}).filter((entry) => entry.el);
      const framed = found.find((entry) => entry.anchorIndex === anchorIndex);
      return {
        el: framed?.el,
        range: framed?.range,
        others: found
          .filter((entry) => entry.anchorIndex !== anchorIndex)
          .map((entry) => ({ el: entry.el, range: entry.range })),
      };
    }
    if (state.hoverId != null && comments[state.hoverId]) {
      const found = anchorHealth(comments[state.hoverId]).found.map((entry) => ({
        el: entry.el,
        range: entry.range,
      }));
      return { el: found[0] && found[0].el, range: found[0] && found[0].range, others: found.slice(1) };
    }
    return { others: [] };
  };

  // The sibling boxes are pooled and hidden rather than removed, so a hover that
  // moves between anchors repositions nodes instead of recreating them.
  const sibNodes = [];
  const drawSiblings = (entries) => {
    entries.forEach((entry, index) => {
      let node = sibNodes[index];
      if (!node) {
        node = document.createElement('div');
        node.className = 'highlight sib';
        hlsNode.appendChild(node);
        sibNodes[index] = node;
      }
      const r = rectOf(boxOf(entry));
      if (!r) {
        node.style.display = 'none';
        node.classList.remove('removable', 'lit');
        node.dataset.anchorHover = '';
        return;
      }
      node.dataset.anchorKind = anchorKind(entry);
      node.classList.toggle('removable', entry.removeIndex != null);
      node.classList.toggle('lit', entry.anchorIndex != null && state.hoverAnchor === entry.anchorIndex);
      node.dataset.anchorHover = entry.anchorIndex == null ? '' : String(entry.anchorIndex);
      node.style.display = 'block';
      node.style.left = r.left - HIGHLIGHT_PADDING + 'px';
      node.style.top = r.top - HIGHLIGHT_PADDING + 'px';
      node.style.width = r.width + HIGHLIGHT_PADDING * 2 + 'px';
      node.style.height = r.height + HIGHLIGHT_PADDING * 2 + 'px';
    });
    for (let index = entries.length; index < sibNodes.length; index++) {
      sibNodes[index].style.display = 'none';
      sibNodes[index].classList.remove('removable', 'lit');
      sibNodes[index].dataset.anchorHover = '';
    }
  };

  // One remove control per removable anchor, pooled and pinned to the padded
  // box's top-right corner. `lit` follows the box the pointer is over.
  const xNodes = [];
  const X_SIZE = 22; // matches .lp-hl-x
  const drawRemoveControls = (entries) => {
    entries.forEach((entry, index) => {
      let node = xNodes[index];
      if (!node) {
        node = document.createElement('button');
        node.className = 'lp-hl-x';
        node.type = 'button';
        node.setAttribute('aria-label', 'Remove this element');
        node.textContent = '×';
        hlxNode.appendChild(node);
        xNodes[index] = node;
      }
      const r = entry.rect;
      // A control for an anchor that is scrolled out of sight has nothing to
      // point at. Clamping it would park an invisible hit target on the edge,
      // where it would eat host-page clicks and remove an anchor nobody can see.
      // Measured on the element, not on its outline, which reaches 8px further.
      const e = entry.inner;
      const onScreen =
        e.left < window.innerWidth &&
        e.left + e.width > 0 &&
        e.top < window.innerHeight &&
        e.top + e.height > 0;
      if (!onScreen) {
        node.style.display = 'none';
        node.removeAttribute('data-anchor-remove');
        return;
      }
      node.style.display = 'flex';
      node.dataset.anchorRemove = String(entry.removeIndex);
      node.classList.toggle('lit', state.hoverAnchor === entry.removeIndex);
      // Clamped: an anchor against the top or right edge would otherwise put
      // most of the control off screen, where it cannot be clicked.
      const clamp = (value, limit) => Math.min(Math.max(value, 0), limit - X_SIZE);
      node.style.left = clamp(r.left + r.width - 11, window.innerWidth) + 'px';
      node.style.top = clamp(r.top - 11, window.innerHeight) + 'px';
    });
    for (let index = entries.length; index < xNodes.length; index++) {
      xNodes[index].style.display = 'none';
      xNodes[index].removeAttribute('data-anchor-remove');
    }
  };

  const outset = (r) => ({
    left: r.left - HIGHLIGHT_PADDING,
    top: r.top - HIGHLIGHT_PADDING,
    width: r.width + HIGHLIGHT_PADDING * 2,
    height: r.height + HIGHLIGHT_PADDING * 2,
  });

  const updateHighlight = () => {
    const targets = highlightTargets();
    const others = targets.others || [];
    drawSiblings(others);
    const framedRemove = targets.move ? null : targets.removeIndex;
    const framedAnchor = targets.move ? null : targets.anchorIndex;
    hlNode.classList.toggle('removable', framedRemove != null);
    hlNode.classList.toggle(
      'lit',
      framedAnchor != null && state.hoverAnchor === framedAnchor,
    );
    hlNode.dataset.anchorHover = framedAnchor == null ? '' : String(framedAnchor);
    let hl = null;
    if (targets.move) {
      hl = targets.move;
      hlNode.dataset.anchorKind = 'element';
    } else if (targets.el) {
      const r = rectOf(boxOf(targets));
      if (r) {
        hl = { left: r.left, top: r.top, width: r.width, height: r.height, label: targets.label || null };
        hlNode.dataset.anchorKind = anchorKind(targets);
      }
    }
    // Every removable anchor gets a control, the framed one included, and each is
    // measured from the same outset rect the box is drawn with.
    const controls = [];
    others.forEach((entry) => {
      const r = entry.removeIndex != null && rectOf(boxOf(entry));
      if (r) controls.push({ rect: outset(r), inner: r, removeIndex: entry.removeIndex });
    });
    if (hl && framedRemove != null) {
      controls.push({ rect: outset(hl), inner: hl, removeIndex: framedRemove });
    }
    drawRemoveControls(controls);
    if (!hl) {
      hlNode.style.display = 'none';
      hlNode.classList.remove('removable', 'lit');
      hlNode.dataset.anchorHover = '';
      hlNode.dataset.anchorKind = '';
      return;
    }
    // Outset the measured rect so the outline frames the element rather than
    // tracing its edge. Applied before the label is placed, so the label keeps
    // sitting against the box the reviewer actually sees.
    hl = { ...hl, ...outset(hl) };
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
  const buildPopover = (comment, index, info) => {
    const label = anchorLabel(info.anchor, info.kind);
    // A degraded comment names how many of its elements survived, so the reviewer
    // is not left reading a note about two things beside a pin on one.
    const missing = info.total - info.foundCount;
    const warning = info.degraded
      ? `<div class="lp-pop-degraded">${escapeHtml(
          `Anchored to ${info.total} elements — ${missing} no longer on this page`,
        )}</div>`
      : '';
    return `<div class="lp-pop">
        <div class="lp-pop-card">
          <div class="lp-pop-body">${escapeHtml(comment.body)}</div>
          ${warning}
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
  const bindPopover = (holder, key, commentIndex) => {
    const del = holder.querySelector('[data-pin-del]');
    if (del)
      del.addEventListener('click', () => {
        state.pinConfirmId = key;
        renderPins();
      });
    const edit = holder.querySelector('[data-pin-edit]');
    if (edit) edit.addEventListener('click', () => openEditComposer(commentIndex));
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
    ovRoot.classList.toggle('drawing', state.drawing);
    // One pin per resolved anchor, keyed by comment and anchor so a comment on
    // several elements gets a pin on each. Every pin of one comment carries that
    // comment's number, which is what shows they belong together.
    const wanted = new Map();
    comments.forEach((comment, index) => {
      const { found, total, degraded } = anchorHealth(comment);
      found.forEach((entry) => {
        const r = rectOf(boxOf(entry));
        if (!r) return;
        const onScreen = !(
          r.bottom < 0 ||
          r.top > window.innerHeight ||
          r.right < 0 ||
          r.left > window.innerWidth
        );
        wanted.set(`${index}:${entry.anchorIndex}`, {
          comment,
          index,
          anchor: entry.anchor,
          kind: anchorKind(entry),
          total,
          foundCount: found.length,
          degraded,
          left: r.left + r.width - 12,
          top: r.top - 12,
          onScreen,
        });
      });
    });
    // Drop pins whose element is gone (deleted, or anchored to another page).
    pinNodes.forEach((wrap, key) => {
      if (!wanted.has(key)) {
        wrap.remove();
        pinNodes.delete(key);
      }
    });
    wanted.forEach((info, key) => {
      let wrap = pinNodes.get(key);
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
        wrap.addEventListener('mouseenter', () => hoverPin(key));
        wrap.addEventListener('mouseleave', unhoverPin);
        pinsNode.appendChild(wrap);
        pinNodes.set(key, wrap);
      }
      wrap.style.left = info.left + 'px';
      wrap.style.top = info.top + 'px';
      wrap.style.display = info.onScreen ? '' : 'none';
      const pin = wrap.querySelector('.pin');
      pin.textContent = String(info.index + 1);
      pin.dataset.anchorKind = info.kind;
      pin.classList.toggle('degraded', info.degraded);
      // Build the card once on hover; toggle the confirm overlay in place. Neither
      // is rebuilt on scroll, so nothing re-animates while repositioning.
      const hovered = state.hoverPinId === key;
      const confirming = hovered && state.pinConfirmId === key;
      const holder = wrap.querySelector('.lp-pop-holder');
      if (!hovered) {
        if (holder.dataset.shown) {
          holder.innerHTML = '';
          delete holder.dataset.shown;
        }
      } else {
        if (!holder.dataset.shown) {
          holder.innerHTML = buildPopover(info.comment, info.index, info);
          holder.dataset.shown = '1';
          bindPopover(holder, key, info.index);
        }
        clampPopover(holder.querySelector('.lp-pop'), info);
        const card = holder.querySelector('.lp-pop-card');
        const liveConfirm = card.querySelector('.lp-pop-confirm:not([data-exiting])');
        if (confirming && !liveConfirm) {
          card.querySelectorAll('.lp-pop-confirm').forEach((node) => node.remove());
          card.insertAdjacentHTML('beforeend', buildConfirm(info.index));
          bindConfirm(card.querySelector('.lp-pop-confirm'), info.index);
        } else if (!confirming && liveConfirm) {
          slideOut(liveConfirm);
        }
      }
    });
  };

  // Both toasts rest against the edge opposite the launcher, so a launcher moved
  // to a top corner never sits under one. The pick-mode toast can still cover the
  // element the reviewer wants to click, so it auto-dodges: the cursor entering
  // the band at its own edge sends it to the other one, and it slides back after.
  const TOAST_DODGE_BAND = 140; // px from the toast's resting edge
  const toastHome = () => (state.corner.startsWith('top') ? 'bottom' : 'top');
  const dockToast = (node, dock) => {
    const offset = dock === 'bottom' ? window.innerHeight - node.offsetHeight - 36 : 0;
    node.style.transform = `translate(-50%, ${offset}px)`;
  };
  const positionToast = () => dockToast(toastNode, state.toastDock);
  const updateToastDodge = (clientY) => {
    const home = toastHome();
    const away = home === 'top' ? 'bottom' : 'top';
    const nearHome =
      clientY != null &&
      (home === 'top'
        ? clientY < TOAST_DODGE_BAND
        : clientY > window.innerHeight - TOAST_DODGE_BAND);
    const dock = nearHome ? away : home;
    if (state.toastDock === dock) return;
    state.toastDock = dock;
    positionToast();
  };

  // The offer sits just under the selection, flipping above it near the bottom
  // edge and clamped horizontally, on the same reasoning as the pin popover.
  const QUOTE_BTN_GAP = 8;
  const QUOTE_BTN_HEIGHT = 30; // matches .lp-quote-btn
  const renderQuoteButton = () => {
    const pick = state.quotePick;
    // Hidden while another mode owns the pointer, drawing included: the canvas
    // sits under this button, so an offer left up would eat part of a stroke
    // and could add a quote without leaving draw mode. The selection survives,
    // so the offer comes back when the mode ends.
    if (!pick || state.target || state.drawing || state.fatal || !state.open || state.editId != null || hidden.matches) {
      quoteBtn.style.display = 'none';
      return;
    }
    quoteBtn.style.display = 'inline-flex';
    const r = pick.range.getBoundingClientRect();
    const maxLeft = Math.max(QUOTE_BTN_GAP, window.innerWidth - quoteBtn.offsetWidth - QUOTE_BTN_GAP);
    const below = r.bottom + QUOTE_BTN_GAP;
    const fits = below + QUOTE_BTN_HEIGHT + QUOTE_BTN_GAP <= window.innerHeight;
    quoteBtn.style.left = Math.min(Math.max(r.left, QUOTE_BTN_GAP), maxLeft) + 'px';
    quoteBtn.style.top =
      (fits ? below : Math.max(QUOTE_BTN_GAP, r.top - QUOTE_BTN_HEIGHT - QUOTE_BTN_GAP)) + 'px';
  };

  const clearQuotePick = () => {
    if (!state.quotePick) return;
    state.quotePick = null;
    renderQuoteButton();
  };

  const renderOverlay = () => {
    scrimNode.style.display = state.target ? 'block' : 'none';
    toastNode.style.display = state.target ? 'flex' : 'none';
    toastText.textContent = state.addAnchor ? 'Click to add another element' : 'Click to comment';
    // offsetHeight only reads correctly once the toast is shown, so position after.
    if (state.target) positionToast();
    // Every toast shares one resting edge, so the mode the reviewer is in wins.
    // The saved notice clears itself on a timer, and drawing again inside that
    // window would otherwise bury the stroke count and its Undo and Clear.
    const showSaved = !!state.savedNotice && !state.target && !state.drawing;
    savedToastNode.style.display = showSaved ? 'flex' : 'none';
    if (showSaved) {
      $$('lp-saved-text').textContent = state.savedNotice;
      dockToast(savedToastNode, toastHome());
    }
    drawToastNode.style.display = state.drawing ? 'flex' : 'none';
    if (state.drawing) {
      // Parked at the edge away from the launcher, and never dodging: the
      // pointer is busy drawing, and a toast that moved would carry its own
      // Undo and Clear out from under the reviewer.
      dockToast(drawToastNode, toastHome());
      const count = state.strokes.length;
      $$('lp-draw-text').textContent =
        count === 0 ? 'Drag to draw' : count === 1 ? '1 stroke' : `${count} strokes`;
      $$('lp-draw-undo').disabled = count === 0;
      $$('lp-draw-clear').disabled = count === 0;
    }
    renderPins();
    updateHighlight();
    renderStrokes();
    renderQuoteButton();
  };

  // Copy for a failed mutation. Auth failures (401/403) never reach here — authFailed()
  // promotes them to the fatal state. A 404 is its own case: the comment stopped being
  // editable because the agent picked it up, so retrying would fail the same way.
  const errorText = (error) => {
    if (error && error.status === 404) {
      return 'Your agent has already picked that comment up, so it can’t be changed now.';
    }
    // A widget-side refusal carries its own wording and no HTTP status.
    if (error && !error.status && error.message) return error.message;
    return 'Couldn’t apply that change. Please try again.';
  };

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
    // not obscure the page; the scrim + toast are the only pick-mode UI. Add-anchor
    // mode is the exception: hiding the panel blurs the textarea, which would send
    // the reviewer's next ⌘V somewhere other than their draft, and it would hold the
    // chip list a pick behind the anchors it lists.
    const picking = state.target && !state.addAnchor;
    launcherNode.style.display = picking ? 'none' : '';
    // Absent rather than disabled while the instance offers no drawing, the
    // same as the in-panel control. The collapsed launcher has no room to
    // explain a control that does nothing.
    launchDrawBtn.style.display = drawingEnabled ? '' : 'none';
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
    panelNode.style.display = state.open && !picking ? 'flex' : 'none';
    if (!state.open || picking) return;

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
      // The drawing gets a pill of its own, so it stays visible and removable
      // after the reviewer leaves draw mode and goes back to typing.
      const strokeCount = state.editId == null ? state.strokes.length : 0;
      const strokeChip = strokeCount
        ? `<span class="lp-compose-chip">${ICON.pen(11)}<span>${
            strokeCount === 1 ? '1 stroke' : `${strokeCount} strokes`
          }</span><button class="lp-chip-x" type="button" data-stroke-clear="1" aria-label="Remove the strokes">×</button></span>`
        : '';
      if (ct.type === 'general') {
        // The hold points a page note at an element and keeps the draft, so the
        // note says the key does something rather than changing type in silence.
        composeHead.innerHTML =
          `<span class="lp-compose-general"><span class="lp-dot"></span>General comment</span>` +
          strokeChip +
          (state.editId == null
            ? `<span class="lp-compose-hint">Hold ${MOD_LABEL} to point at an element</span>`
            : '');
      } else {
        // One chip per anchor. An edit PATCHes the body alone and leaves the
        // stored anchors untouched, so the remove × is not offered while editing:
        // it would confirm a change that the save then discards.
        const anchors = ct.anchors || [];
        const editable = state.editId == null;
        const chips = anchors
          .map((anchor, anchorIndex) => {
            const quoted = !!anchor.quote;
            const label = anchor.label || (quoted ? 'Selected text' : 'Selected element');
            const noun = quoted ? 'text' : 'element';
            return `<span class="lp-compose-chip" data-anchor-chip="${anchorIndex}" data-anchor-kind="${
              quoted ? 'quote' : 'element'
            }"><button class="lp-chip-name" type="button" data-anchor-pill="${anchorIndex}" aria-label="Highlight ${escapeHtml(
              label,
            )}">${quoted ? ICON.quote(11) : ICON.glyph(11)}<span>${escapeHtml(label)}</span></button>${
              editable
                ? `<button class="lp-chip-x" data-anchor-remove="${anchorIndex}" aria-label="Remove this ${noun}">×</button>`
                : ''
            }</span>`;
          })
          .join('');
        const canAdd = editable && anchors.length < MAX_ANCHORS;
        composeHead.innerHTML =
          chips +
          strokeChip +
          (canAdd
            ? `<span class="lp-compose-hint">Hold ${MOD_LABEL} to add another</span>`
            : '');
        composeHead.querySelectorAll('[data-anchor-remove]').forEach((button) => {
          button.addEventListener('mousedown', (event) => event.preventDefault());
          button.addEventListener('click', () => {
            removeComposeAnchor(Number(button.dataset.anchorRemove));
            if (state.composing) focusTextarea();
          });
        });
        // Pointing at a pill emphasises the one element it names, and the rest
        // of the comment's anchors stay painted around it. Bound to the whole
        // pill, so reaching for its × keeps the element it removes emphasised.
        composeHead.querySelectorAll('[data-anchor-chip]').forEach((chip) => {
          const anchorIndex = Number(chip.dataset.anchorChip);
          chip.addEventListener('mouseenter', () => setHoverAnchor(anchorIndex));
          chip.addEventListener('mouseleave', () => setHoverAnchor(null));
          // focusin and focusout bubble, so they see the label and the ×. Moving
          // between the two must not blink the emphasis off on the way.
          chip.addEventListener('focusin', () => setHoverAnchor(anchorIndex));
          chip.addEventListener('focusout', (event) => {
            if (!chip.contains(event.relatedTarget)) setHoverAnchor(null);
          });
        });
        composeHead.querySelectorAll('[data-anchor-pill]').forEach((button) => {
          // A press must not take the caret out of the draft. The pill stays
          // focusable, so only the keyboard reaches it and only it sees a ring.
          button.addEventListener('mousedown', (event) => event.preventDefault());
          button.addEventListener('click', () => showAnchor(Number(button.dataset.anchorPill)));
        });
      }
      composeHead.querySelectorAll('[data-stroke-clear]').forEach((button) => {
        button.addEventListener('mousedown', (event) => event.preventDefault());
        button.addEventListener('click', () => clearStrokes());
      });
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
    // Hidden rather than disabled when the instance offers no drawing: the two
    // remaining modes then split the row, and there is nothing to explain.
    drawBtn.style.display = drawingEnabled ? '' : 'none';
    drawBtn.classList.toggle('active', state.drawing);
    drawBtn.setAttribute('aria-pressed', state.drawing ? 'true' : 'false');
    // An edit PATCHes the body alone, so a drawing added here would be dropped
    // by the save, the same way an anchor change is.
    drawBtn.disabled = state.editId != null;

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
          `<div class="lp-item-body"><div class="lp-item-text"></div><span class="lp-chip" style="display:none"></span>` +
          `<button class="lp-item-page" style="display:none" aria-label="Go to the page this comment was made on">` +
          `${ICON.arrowOut(11)}<span class="lp-item-page-label"></span></button></div>` +
          `<button class="lp-edit" aria-label="Edit comment">${ICON.edit(14)}</button>` +
          `<button class="lp-del" aria-label="Delete comment">${ICON.trash(14)}</button>`;
        row.addEventListener('mouseenter', () => {
          if (state.target || !comments[index] || !anchorsOf(comments[index]).length) return;
          state.hoverId = index;
          updateHighlight();
        });
        row.addEventListener('mouseleave', () => {
          state.hoverId = null;
          updateHighlight();
        });
        row.querySelector('.lp-item-page').addEventListener('click', () => {
          const target = comments[index];
          if (target && target.url) location.href = target.url;
        });
        row.querySelector('.lp-edit').addEventListener('click', () => openEditComposer(index));
        row.querySelector('.lp-del').addEventListener('click', () => {
          state.confirmDeleteId = index;
          renderList();
        });
        listNode.appendChild(row);
        rowNodes.set(index, row);
      }
      const anchors = anchorsOf(comment);
      const isElement = anchors.length > 0;
      const badge = row.querySelector('.lp-badge');
      badge.className = 'lp-badge ' + (isElement ? 'element' : 'general');
      badge.textContent = String(index + 1);
      row.querySelector('.lp-item-text').textContent = comment.body;
      const chipEl = row.querySelector('.lp-chip');
      // A multi-anchor row names its count, and says so when some of its
      // elements are missing, rather than showing one element's text.
      const { degraded, found } = anchorHealth(comment);
      let label = '';
      if (anchors.length > 1) {
        label = degraded
          ? `${found.length} of ${anchors.length} elements`
          : `${anchors.length} elements`;
      } else if (isElement) {
        label = anchorLabel(anchors[0]);
      }
      const showChip = isElement ? !!label : true;
      chipEl.style.display = showChip ? '' : 'none';
      chipEl.classList.toggle('degraded', isElement && degraded);
      if (showChip) chipEl.textContent = isElement ? label : 'General comment';
      // Only cross-page comments get the affordance: on the current page the row
      // already highlights its element, and a label saying "here" is noise.
      const pageEl = row.querySelector('.lp-item-page');
      const offPage = !!comment.url && comment.url !== location.href;
      pageEl.style.display = offPage ? '' : 'none';
      if (offPage) pageEl.querySelector('.lp-item-page-label').textContent = pageLabel(comment.url);
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
    state.strokes = [];
    textareaNode.value = '';
    state.open = true;
    setTargeting(false);
    setDrawing(false);
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
      state.strokes = [];
      textareaNode.value = '';
      setDrawing(false);
      sync();
    } else {
      openNoteComposer();
    }
  };
  const anchorFor = (el) => ({
    el,
    selector: selectorFor(el),
    // Array.from splits on code points, so a truncation cannot land inside an
    // emoji and produce the broken character the API rejects.
    text: Array.from((el.innerText || '').trim())
      .slice(0, TEXT_MAX)
      .join(''),
    label: firstLineLabel(el.innerText),
  });

  // The element a selection is anchored to: the smallest one holding the whole
  // range. A selection spanning several blocks keeps the wrapper around them,
  // because the quote inside it is still exact. <body> and <html> are refused,
  // because a selector naming either says only what a page note already says.
  const containerElementOf = (range) => {
    let el = range.commonAncestorContainer;
    if (el && el.nodeType !== 1) el = el.parentElement;
    if (!el || el.nodeType !== 1) return null;
    // getRootNode rather than insideWidget alone: Node.contains does not cross a
    // shadow boundary, so text selected in the widget's own panel reads as host
    // page text. A selector cannot reach into any shadow root either, so one
    // belonging to the host page is refused here for the same reason.
    if (el.getRootNode() !== document) return null;
    if (el === document.body || el === document.documentElement) return null;
    return insideWidget(el) ? null : el;
  };

  // A selected run of text as an anchor. The quote and its context are sliced
  // from the container's textContent, the same basis quoteRange searches, so
  // capture and match always count the same characters. Not Selection.toString():
  // Chrome puts a break between blocks there, and that string is then not a
  // substring of textContent, so nothing would re-anchor. `text` stays the
  // element's innerText, which is where those breaks survive.
  const anchorForRange = (range) => {
    const el = containerElementOf(range);
    if (!el) return null;
    const haystack = el.textContent || '';
    const start = textOffsetIn(el, range.startContainer, range.startOffset);
    const end = textOffsetIn(el, range.endContainer, range.endOffset);
    if (end <= start) return null;
    const quote = haystack.slice(start, end);
    if (!quote.trim()) return null;
    if (Array.from(quote).length > QUOTE_MAX) return { tooLong: true };
    return {
      ...anchorFor(el),
      range,
      quote,
      quotePrefix: beforeText(haystack, start, QUOTE_CONTEXT),
      quoteSuffix: afterText(haystack, end, QUOTE_CONTEXT),
      label: firstLineLabel(quote),
    };
  };

  // The anchors the composer currently holds, empty for a page note.
  const composeAnchors = () => {
    const ct = state.composeTarget;
    return ct && ct.type === 'element' ? ct.anchors : [];
  };

  // The element alone would swallow a second passage inside an element already
  // pointed at, and the quote alone would swallow the second of two identical
  // phrases — the pair the stored context exists to tell apart. So all three
  // fields decide, joined on NUL, which the HTML parser never leaves in page
  // text and which therefore cannot run two fields into another pair's key.
  const quoteKey = (anchor) =>
    [anchor.quote || '', anchor.quotePrefix || '', anchor.quoteSuffix || ''].join('\u0000');
  const sameAnchor = (a, b) => a.el === b.el && quoteKey(a) === quoteKey(b);

  // Add an anchor to the composer, whether it names an element or a run of text
  // inside one. A pick while a new comment is open extends it rather than
  // replacing its anchor, which is how one comment comes to say something about
  // several things. `keepPicking` holds pick mode open for the next one, which
  // is what the add-another modifier does.
  const addComposeAnchor = (anchor, keepPicking) => {
    if (state.composing && state.editId == null) {
      const existing = composeAnchors();
      let stayed = keepPicking;
      if (existing.length >= MAX_ANCHORS) {
        state.actionError = { message: AT_CAP_MESSAGE };
        stayed = false;
      } else if (!existing.some((entry) => sameAnchor(entry, anchor))) {
        state.composeTarget = { type: 'element', anchors: existing.concat([anchor]) };
      }
      state.open = true;
      if (!stayed) setTargeting(false);
      state.addAnchor = stayed;
      sync();
      if (!stayed && root.activeElement !== textareaNode) focusTextarea();
      return;
    }
    state.composing = true;
    state.composeTarget = { type: 'element', anchors: [anchor] };
    state.editId = null;
    state.draft = '';
    state.strokes = [];
    textareaNode.value = '';
    state.open = true;
    if (!keepPicking) setTargeting(false);
    state.addAnchor = !!keepPicking;
    sync();
    if (!keepPicking) focusTextarea();
  };
  const openElementComposer = (el, keepPicking) => addComposeAnchor(anchorFor(el), keepPicking);

  // Turn the reviewer's selection into a quoted anchor. The offer is what calls
  // this, never the selection itself: acting on every drag would take a gesture
  // the reviewer aimed at the host page.
  const commentOnSelection = () => {
    const anchor = state.quotePick && state.quotePick.anchor;
    clearQuotePick();
    if (!anchor) return;
    if (anchor.tooLong) {
      state.actionError = { message: LONG_QUOTE_MESSAGE };
      state.open = true;
      sync();
      return;
    }
    const selection = document.getSelection();
    if (selection) selection.removeAllRanges();
    addComposeAnchor(anchor, false);
  };

  // Drop one anchor from the composer. Removing the last one leaves the comment
  // as a page note rather than closing the composer under the reviewer.
  const removeComposeAnchor = (anchorIndex) => {
    const anchors = composeAnchors();
    if (!anchors.length || state.editId != null) return;
    anchors.splice(anchorIndex, 1);
    if (!anchors.length) state.composeTarget = { type: 'general' };
    // The index the removed anchor held now belongs to the next one. Emphasis
    // has to go with the anchor that is gone, not pass to its neighbour.
    setHoverAnchor(null);
    sync();
  };

  // Emphasise one anchor of the comment being composed, on the page and on its
  // pill. Either end can set it: a pill points at an element, and an element's
  // own box points back at its pill.
  const setHoverAnchor = (anchorIndex) => {
    if (state.hoverAnchor === anchorIndex) return;
    state.hoverAnchor = anchorIndex;
    composeHead.querySelectorAll('[data-anchor-chip]').forEach((chip) => {
      chip.classList.toggle('lit', Number(chip.dataset.anchorChip) === anchorIndex);
    });
    updateHighlight();
  };

  // Scroll to an anchor only when the reviewer asks by clicking its pill, and
  // only when it is off screen. Scrolling on hover would move the page under a
  // pointer that is only browsing the pills.
  const showAnchor = (anchorIndex) => {
    const anchor = composeAnchors()[anchorIndex];
    const r = anchor && anchor.el && rectOf(boxOf(anchor));
    if (!r) return;
    const onScreen =
      r.top >= 0 &&
      r.bottom <= window.innerHeight &&
      r.left >= 0 &&
      r.right <= window.innerWidth;
    if (onScreen) return;
    anchor.el.scrollIntoView({ block: 'center', inline: 'center', behavior: 'smooth' });
  };

  // ---- drawing mode ----
  // A stroke creates no anchor and promotes nothing under it. It says "look
  // here" beside whatever the comment already points at, so a reviewer can
  // target an element and draw an arrow showing where it should move.
  const setDrawing = (on) => {
    if (on && hidden.matches) return;
    if (on) setTargeting(false);
    state.drawing = on;
    if (!on) {
      liveStroke = null;
      return;
    }
    // The boxes stop taking pointer events on this tick, so a hover the pointer
    // is already inside would never see its mouseout and would stay emphasised.
    state.hoverAnchor = null;
  };
  const toggleDraw = () => {
    if (state.fatal || !drawingEnabled) return;
    if (state.drawing) {
      setDrawing(false);
      sync();
      if (state.composing) focusTextarea();
      return;
    }
    if (state.editId != null) return;
    if (!state.composing) {
      state.composing = true;
      state.composeTarget = { type: 'general' };
      state.strokes = [];
      state.draft = '';
      textareaNode.value = '';
    }
    state.open = true;
    setDrawing(true);
    // The reviewer is drawing, not typing: the next ⌘Z has to undo a stroke,
    // and a stray keystroke must not land in a field nobody is looking at.
    textareaNode.blur();
    sync();
  };
  const undoStroke = () => {
    if (!state.strokes.length) return;
    state.strokes.pop();
    sync();
  };
  const clearStrokes = () => {
    if (!state.strokes.length) return;
    state.strokes = [];
    sync();
  };
  const beginStroke = (event) => {
    if (!state.drawing || event.button !== 0 || liveStroke) return;
    if (state.strokes.length >= MAX_STROKES) {
      state.actionError = { message: AT_STROKE_CAP_MESSAGE };
      sync();
      return;
    }
    event.preventDefault();
    // Capture, so a drag that wanders over the panel or leaves the window still
    // ends on this node instead of leaving a stroke open behind the pointer.
    if (canvasNode.setPointerCapture) canvasNode.setPointerCapture(event.pointerId);
    liveStroke = [docPointOf(event)];
  };
  const extendStroke = (event) => {
    if (!liveStroke || liveStroke.length >= MAX_STROKE_POINTS) return;
    const point = docPointOf(event);
    const last = liveStroke[liveStroke.length - 1];
    if (Math.abs(point[0] - last[0]) + Math.abs(point[1] - last[1]) < MIN_POINT_GAP) return;
    liveStroke.push(point);
    renderStrokes();
  };
  // A press that never moved is not a stroke, and the API refuses a one-point one.
  const endStroke = (keep) => {
    if (!liveStroke) return;
    const points = liveStroke;
    liveStroke = null;
    if (keep && points.length > 1) state.strokes.push(points);
    sync();
  };

  // Add-anchor mode. Holding the modifier over an open composer brings the picker
  // back, so a click adds another anchor without discarding the draft. The mode
  // lasts as long as the hold. At the cap it says so instead of picking.
  const enterAddAnchor = () => {
    if (state.modCancelled || state.addAnchor) return;
    if (state.fatal || !state.composing || state.editId != null || hidden.matches) return;
    // A modifier held while drawing belongs to the drag, not to the picker.
    if (state.drawing) return;
    if (composeAnchors().length >= MAX_ANCHORS) {
      state.actionError = { message: AT_CAP_MESSAGE };
    } else {
      setTargeting(true);
    }
    state.addAnchor = true;
    sync();
  };
  // `cancelled` marks the hold spent, so another key pressed with the modifier
  // down does not re-enter the mode before the reviewer lets go.
  const exitAddAnchor = (cancelled) => {
    state.modCancelled = cancelled;
    if (!state.addAnchor) return;
    state.addAnchor = false;
    setTargeting(false);
    sync();
    if (state.composing && root.activeElement !== textareaNode) focusTextarea();
  };
  // Re-open the composer pre-filled to edit an existing comment in place. The anchors
  // are preserved and rebuilt from storage; only the body is editable.
  const openEditComposer = (index) => {
    const comment = comments[index];
    if (!comment) return;
    // Read the stored anchors rather than the resolved ones: a comment made on
    // another page still has its anchors, and editing must not silently demote
    // it to a page note.
    const stored = anchorsOf(comment).filter((anchor) => anchor.selector);
    const onThisPage = comment.url === location.href;
    state.composing = true;
    state.editId = comment.id;
    state.composeTarget = stored.length
      ? {
          type: 'element',
          anchors: stored.map((anchor) => {
            const el = onThisPage ? queryOne(anchor.selector) : null; // null off-page — fine
            return {
              el,
              range: quoteRange(el, anchor),
              selector: anchor.selector,
              text: anchor.text,
              quote: anchor.quote,
              quotePrefix: anchor.quotePrefix,
              quoteSuffix: anchor.quoteSuffix,
              label: anchorLabel(anchor),
            };
          }),
        }
      : { type: 'general' };
    state.draft = comment.body;
    // An edit PATCHes the body alone, so the stored drawing is left where it
    // is and the composer offers no stroke controls.
    state.strokes = [];
    textareaNode.value = comment.body;
    state.open = true;
    setTargeting(false);
    setDrawing(false);
    sync();
    focusTextarea();
  };
  const cancelCompose = () => {
    state.composing = false;
    state.composeTarget = null;
    state.editId = null;
    state.draft = '';
    state.strokes = [];
    textareaNode.value = '';
    setDrawing(false);
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
        const kept = composeAnchors().filter((entry) => entry.selector);
        // The quote fields go over the wire only when the anchor names a run of
        // text; an element anchor sends none, and the API stores null.
        const anchors = kept.map((entry) => ({
          selector: entry.selector,
          text: entry.text,
          ...(entry.quote
            ? {
                quote: entry.quote,
                quotePrefix: entry.quotePrefix,
                quoteSuffix: entry.quoteSuffix,
              }
            : {}),
        }));
        // The space is decided here rather than while drawing, because a pick
        // after the drag changes which box the fractions are measured against.
        // A quoted anchor measures against the element that holds the run.
        const box = strokeBoxOf(kept);
        const strokes = state.strokes.map((points) => storeStroke(points, box));
        // selector/text repeat the first anchor for an instance that predates
        // anchors[]. This script's URL carries no version, so a browser can hold
        // this copy long after a rollback, and that instance would otherwise save
        // every comment as an unanchored note. The current API prefers anchors[].
        const first = anchors[0];
        const comment = {
          body,
          url: location.href,
          anchors,
          strokes,
          selector: first ? first.selector : '',
          text: first ? first.text : '',
        };
        const { commentId } = await api('POST', '/api/site-review/comments', comment);
        comments.push({ id: commentId, ...comment });
      }
      state.composing = false;
      state.composeTarget = null;
      state.editId = null;
      state.draft = '';
      state.strokes = [];
      textareaNode.value = '';
      setDrawing(false);
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
    state.strokes = [];
    setDrawing(false);
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
  // Mirrors the :host rule above. Pick mode does not live in the shadow roots
  // that rule hides: its click and contextmenu handlers are on the document and
  // the crosshair is a stylesheet on the root. An invisible widget eating the
  // page's clicks is worse than no widget, so picking is stood down on the way
  // in and refused while hidden — the launcher goes, the 't' shortcut does not.
  const hidden = window.matchMedia(
    '(max-width:639px),(hover:none) and (pointer:coarse)',
  );
  const setTargeting = (on) => {
    if (on && hidden.matches) return;
    // The two modes both own the pointer, so only one of them can be up.
    if (on) setDrawing(false);
    state.target = on;
    if (!on) {
      state.moveHL = null;
      state.expandLevel = 0;
      state.addAnchor = false;
      moveBase = null;
    } else {
      state.toastDock = toastHome();
    }
    cursorStyle.textContent = on ? '*{cursor:crosshair !important}' : '';
    if (on) {
      document.addEventListener('mousemove', onMove, true);
      document.addEventListener('mousedown', onDown, true);
      document.addEventListener('click', onClick, true);
      document.addEventListener('contextmenu', onContext, true);
      window.addEventListener('wheel', onWheel, { passive: false });
    } else {
      document.removeEventListener('mousemove', onMove, true);
      document.removeEventListener('mousedown', onDown, true);
      document.removeEventListener('click', onClick, true);
      document.removeEventListener('contextmenu', onContext, true);
      window.removeEventListener('wheel', onWheel);
    }
  };

  hidden.addEventListener('change', (event) => {
    if (event.matches) {
      setTargeting(false);
      setDrawing(false);
      // setTargeting only moves state; without this the widget comes back on a
      // widen still showing the pick toast it is no longer in.
      sync();
    }
  });
  const toggleTarget = () => {
    if (state.fatal) return;
    const on = !state.target;
    // Picking while a new comment is open adds to it. Discarding the draft
    // there would lose the body, the anchors already chosen and the drawing.
    const extending =
      state.composing &&
      state.editId == null &&
      (composeAnchors().length > 0 || state.strokes.length > 0);
    if (on && !extending) {
      state.composing = false;
      state.composeTarget = null;
      state.editId = null;
      state.draft = '';
      state.strokes = [];
      textareaNode.value = '';
    }
    if (on) state.open = true;
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
  // A mousedown focuses what it lands on. During a pick that gives the host
  // page's link, input or button a focus ring and takes the caret out of the
  // composer, for a gesture that only meant to select. The click still fires.
  const onDown = (event) => {
    if (!state.target || !pickEl(event)) return;
    event.preventDefault();
  };
  const onClick = (event) => {
    if (!state.target) return;
    const base = pickEl(event);
    // A click that isn't on the host page (e.g. on the panel itself) is left alone.
    if (!base) return;
    event.preventDefault();
    event.stopPropagation();
    const current = climbUp(base, state.expandLevel);
    // The modifier read at click time is what makes a hold that started before
    // the first pick work: no composer was open, so no keydown could arm it.
    openElementComposer(current, modHeld(event) && !state.modCancelled);
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

  // ---- moving the launcher ----
  const launcherNode = $('lp-launcher');
  const moveBtn = $('lp-move');
  const nextCorner = () => CORNERS[(CORNERS.indexOf(state.corner) + 1) % CORNERS.length];

  const applyCorner = () => {
    const atTop = state.corner.startsWith('top');
    const atLeft = state.corner.endsWith('left');
    [launcherNode, panelNode].forEach((node) => {
      node.classList.toggle('at-top', atTop);
      node.classList.toggle('at-left', atLeft);
    });
    // Told to the host page so an embedder can move its own pinned chrome out
    // of the way. `data-loupe-review-open` is the same contract.
    document.documentElement.setAttribute('data-loupe-review-corner', state.corner);
    // "Review" stays out of this name: it is the launcher's own accessible name,
    // and a label containing it makes every by-name lookup ambiguous.
    const label = `Move the launcher to the ${CORNER_LABEL[nextCorner()]}`;
    moveBtn.setAttribute('aria-label', label);
    moveBtn.setAttribute('title', label);
  };
  const setCorner = (corner) => {
    if (!CORNERS.includes(corner)) return;
    if (corner !== state.corner) {
      state.corner = corner;
      writeCorner(corner);
    }
    applyCorner();
    sync();
  };

  const DRAG_THRESHOLD = 4; // px of travel before a press counts as a drag
  let drag = null;

  // A drag ends in a click on whatever the pointer was released over, and that
  // gesture meant "move", not "open". Swallowing it at the top of the capture
  // path beats a timer: the listener removes itself on the click it eats, and
  // the next press removes it too, so a drag that ends off the page leaves
  // nothing armed against an unrelated click.
  const swallowNextClick = (event) => {
    window.removeEventListener('click', swallowNextClick, true);
    event.preventDefault();
    event.stopPropagation();
  };
  const disarmClickSwallow = () => window.removeEventListener('click', swallowNextClick, true);
  const armClickSwallow = () => {
    disarmClickSwallow();
    window.addEventListener('click', swallowNextClick, true);
  };

  const nearestCorner = (rect) =>
    (rect.top + rect.height / 2 < window.innerHeight / 2 ? 'top' : 'bottom') +
    '-' +
    (rect.left + rect.width / 2 < window.innerWidth / 2 ? 'left' : 'right');

  const onDragMove = (event) => {
    if (!drag || event.pointerId !== drag.pointerId) return;
    if (!drag.moved) {
      if (
        Math.abs(event.clientX - drag.startX) < DRAG_THRESHOLD &&
        Math.abs(event.clientY - drag.startY) < DRAG_THRESHOLD
      ) {
        return;
      }
      drag.moved = true;
      launcherNode.classList.add('dragging');
    }
    // Clamped to the viewport, so a launcher can never be dropped where its own
    // corner control is out of reach.
    const clamp = (value, limit) => Math.min(Math.max(value, 0), Math.max(0, limit));
    launcherNode.style.right = 'auto';
    launcherNode.style.bottom = 'auto';
    launcherNode.style.left =
      clamp(event.clientX - drag.offsetX, window.innerWidth - drag.width) + 'px';
    launcherNode.style.top =
      clamp(event.clientY - drag.offsetY, window.innerHeight - drag.height) + 'px';
  };
  const stopDrag = () => {
    document.removeEventListener('pointermove', onDragMove, true);
    document.removeEventListener('pointerup', onDragEnd, true);
    document.removeEventListener('pointercancel', onDragCancel, true);
    window.removeEventListener('blur', onDragCancel);
    launcherNode.classList.remove('dragging');
    ['left', 'top', 'right', 'bottom'].forEach((side) => {
      launcherNode.style[side] = '';
    });
    drag = null;
  };
  // A pointer lost mid-drag (a window switch, a cancelled gesture) returns the
  // launcher to the corner it started from rather than leaving it loose. It
  // arms no swallow: the browser sends no click after a cancelled gesture, so
  // one armed here would sit and eat the reviewer's next click on the page.
  const onDragCancel = () => {
    if (!drag) return;
    stopDrag();
  };
  const onDragEnd = (event) => {
    if (!drag || event.pointerId !== drag.pointerId) return;
    const moved = drag.moved;
    // Measured before stopDrag clears the inline offsets that put it there.
    const rect = launcherNode.getBoundingClientRect();
    stopDrag();
    if (!moved) return;
    armClickSwallow();
    setCorner(nearestCorner(rect));
  };

  launcherNode.addEventListener('pointerdown', (event) => {
    // Primary button only, and never while picking: the picker owns the page's
    // clicks, and the launcher is only up there to hold an open draft.
    if (0 !== event.button || state.target || drag) return;
    const rect = launcherNode.getBoundingClientRect();
    drag = {
      pointerId: event.pointerId,
      startX: event.clientX,
      startY: event.clientY,
      offsetX: event.clientX - rect.left,
      offsetY: event.clientY - rect.top,
      width: rect.width,
      height: rect.height,
      moved: false,
    };
    document.addEventListener('pointermove', onDragMove, true);
    document.addEventListener('pointerup', onDragEnd, true);
    document.addEventListener('pointercancel', onDragCancel, true);
    window.addEventListener('blur', onDragCancel);
  });
  // Any fresh press clears a swallow that somehow outlived the click it was
  // armed for, so a stale one can never reach an unrelated gesture.
  document.addEventListener('pointerdown', disarmClickSwallow, true);

  // A press must not take the caret out of the draft. The button stays
  // focusable, so only the keyboard reaches it and only it sees a ring.
  moveBtn.addEventListener('mousedown', (event) => event.preventDefault());
  moveBtn.addEventListener('click', () => {
    setCorner(nextCorner());
    if (state.composing && root.activeElement !== moveBtn) focusTextarea();
  });

  // ---- panel open/close ----
  const togglePanel = () => {
    state.open = !state.open;
    if (!state.open) {
      setTargeting(false);
      setDrawing(false);
      state.composing = false;
      state.composeTarget = null;
      state.editId = null;
      state.draft = '';
      state.strokes = [];
      textareaNode.value = '';
    }
    sync();
  };

  // ---- static bindings (persistent nodes) ----
  $('lp-launch-main').addEventListener('click', togglePanel);
  $('lp-launch-note').addEventListener('click', openNoteComposer);
  $('lp-launch-target').addEventListener('click', toggleTarget);
  $('lp-launch-draw').addEventListener('click', toggleDraw);
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
  drawBtn.addEventListener('click', toggleDraw);
  canvasNode.addEventListener('pointerdown', beginStroke);
  canvasNode.addEventListener('pointermove', extendStroke);
  canvasNode.addEventListener('pointerup', () => endStroke(true));
  canvasNode.addEventListener('pointercancel', () => endStroke(false));
  $$('lp-draw-undo').addEventListener('click', undoStroke);
  $$('lp-draw-clear').addEventListener('click', clearStrokes);
  $$('lp-draw-done').addEventListener('click', toggleDraw);
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
  // The remove control sits in the overlay above the page, so the click is
  // stopped here rather than falling through to the element it is drawn over.
  ovRoot.addEventListener('click', (event) => {
    const button = event.target.closest && event.target.closest('[data-anchor-remove]');
    if (!button) return;
    event.preventDefault();
    event.stopPropagation();
    removeComposeAnchor(Number(button.dataset.anchorRemove));
    // The reviewer types next, and the removed control is gone, so the caret
    // goes back to the draft rather than falling to the document.
    if (state.composing) focusTextarea();
  });
  // A mouse press must not focus the control. It stays focusable for the
  // keyboard, and :focus-visible is what draws the ring.
  ovRoot.addEventListener('mousedown', (event) => {
    if (event.target.closest && event.target.closest('[data-anchor-remove]')) event.preventDefault();
  });
  // Reveal the control of whichever removable box the pointer is over.
  // A box or its own remove control. Reaching for the control must not drop the
  // emphasis, because the pointer leaves the box to get there.
  const hoverAnchorOf = (target) => {
    if (!target || !target.closest) return null;
    const control = target.closest('[data-anchor-remove]');
    if (control) return Number(control.dataset.anchorRemove);
    const box = target.closest('.highlight.removable');
    const value = box && box.dataset.anchorHover;
    return value ? Number(value) : null;
  };
  ovRoot.addEventListener('mouseover', (event) => setHoverAnchor(hoverAnchorOf(event.target)));
  ovRoot.addEventListener('mouseout', (event) => {
    if (hoverAnchorOf(event.relatedTarget) == null) setHoverAnchor(null);
  });
  // A press must not reach the page, which would collapse the very selection
  // the button is offering to quote before the click ever fires.
  quoteBtn.addEventListener('mousedown', (event) => event.preventDefault());
  quoteBtn.addEventListener('click', (event) => {
    event.preventDefault();
    event.stopPropagation();
    commentOnSelection();
  });

  // A selection offers to become a quoted anchor only while the panel is open
  // and no saved comment is being edited: an edit sends the body alone, so
  // taking the selection would start a new comment and lose the draft.
  // selectionchange rather than mouseup, so a keyboard selection reaches it.
  const readSelection = () => {
    if (state.fatal || state.target || !state.open || state.editId != null || hidden.matches) {
      return clearQuotePick();
    }
    const selection = document.getSelection();
    if (!selection || selection.isCollapsed || !selection.rangeCount) return clearQuotePick();
    const range = selection.getRangeAt(0);
    const anchor = anchorForRange(range.cloneRange());
    if (!anchor) return clearQuotePick();
    state.quotePick = { range: range.cloneRange(), anchor };
    renderQuoteButton();
  };
  let selectionFrame = 0;
  document.addEventListener('selectionchange', () => {
    if (selectionFrame) return;
    selectionFrame = requestAnimationFrame(() => {
      selectionFrame = 0;
      readSelection();
    });
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

  // Add-anchor mode is armed by the modifier alone, before the typing guard below,
  // because the composer's textarea holds focus while the reviewer picks. Any other
  // key spends the hold, which leaves ⌘A, ⌘C and ⌘V doing what they always did.
  document.addEventListener('keyup', (event) => {
    if (event.key === MOD_KEY) exitAddAnchor(false);
  });
  // A keyup never arrives when the hold ends outside the page, so ⌘-Tab away and
  // a window switch both stand the picker down.
  window.addEventListener('blur', () => exitAddAnchor(false));
  document.addEventListener('visibilitychange', () => {
    if (document.hidden) exitAddAnchor(false);
  });

  document.addEventListener('keydown', (event) => {
    // Undo a stroke, not the draft. Only while drawing, and only with the caret
    // out of the textarea, so clicking back into it restores text undo.
    if (
      state.drawing &&
      !isTyping() &&
      (event.metaKey || event.ctrlKey) &&
      !event.shiftKey &&
      event.key.toLowerCase() === 'z'
    ) {
      event.preventDefault();
      undoStroke();
      return;
    }
    if (event.key === MOD_KEY) {
      if (!event.repeat) enterAddAnchor();
      return;
    }
    // Any other key spends the hold. Escape stops there rather than falling
    // through to cancelCompose, which would throw the draft away.
    if (state.addAnchor) {
      exitAddAnchor(true);
      return;
    }
    if (event.key === 'Escape') {
      if (state.target) {
        setTargeting(false);
        sync();
        return;
      }
      // Leaving draw mode keeps the strokes and the draft, the way leaving pick
      // mode keeps the anchors already chosen.
      if (state.drawing) {
        setDrawing(false);
        sync();
        if (state.composing) focusTextarea();
        return;
      }
      if (state.composing) {
        cancelCompose();
        return;
      }
    }
    // Single-key shortcuts only while the panel is open, no modifier held, not typing —
    // and never in the fatal state, where there is nothing to compose. Nor while
    // the breakpoint hides the widget: swallowing the host page's 'c' and 't' to
    // drive UI nobody can see is worse than not being there.
    if (!state.open || state.fatal || hidden.matches) return;
    if (event.metaKey || event.ctrlKey || event.altKey || isTyping()) return;
    if (event.key === 'c') {
      event.preventDefault();
      openNoteComposer();
    } else if (event.key === 't') {
      event.preventDefault();
      toggleTarget();
    } else if (event.key === 'd') {
      event.preventDefault();
      toggleDraw();
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
      renderStrokes();
      renderQuoteButton();
      // A toast resting against the bottom is placed from innerHeight, so a
      // resize moves it.
      if (state.target) positionToast();
      else if (state.savedNotice) dockToast(savedToastNode, toastHome());
      if (state.drawing) dockToast(drawToastNode, toastHome());
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
    renderStrokes();
    renderQuoteButton();
  };
  let lastSeenUrl = location.href;
  const handleLocationChange = () => {
    if (location.href === lastSeenUrl) return;
    lastSeenUrl = location.href;
    state.hoverId = null;
    state.hoverPinId = null;
    state.pinConfirmId = null;
    // A selection held from the old page names an element that is about to go,
    // and saving it would store that selector against the new URL.
    clearQuotePick();
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

  applyCorner();
  const ready = refresh({ firstLoad: true });
  sync();
  ready.then(sync);
})();
