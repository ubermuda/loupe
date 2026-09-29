/** @vitest-environment jsdom */
import { Application } from '@hotwired/stimulus';
import { afterEach, expect, it, vi } from 'vitest';
import MermaidController from '../../assets/controllers/mermaid_controller.js';

let application;
const settle = () => new Promise((resolve) => setTimeout(resolve, 0));

const page = (enabled) => `<div data-controller="mermaid"
        data-mermaid-enabled-value="${enabled}"
        data-mermaid-module-url-value="https://cdn.example.test/mermaid.esm.min.mjs">
    <div data-mermaid-target="doc"><p>Before the diagrams.</p>
<pre><code class="language-mermaid">graph TD
  A --> B</code></pre>
<p>Between.</p>
<pre><code class="language-mermaid">broken diagram</code></pre>
<pre><code class="language-php">echo 1;</code></pre>
<p>After.</p></div>
    <template data-mermaid-target="disabledNotice"><p class="lp-mermaid__notice" data-notice="disabled">Diagrams are off.</p></template>
    <template data-mermaid-target="errorNotice"><p class="lp-mermaid__notice" data-notice="error">Could not render.</p></template>
    <template data-mermaid-target="toggle"><button type="button" class="lp-mermaid__toggle" aria-expanded="false" data-label-show="Show source" data-label-hide="Hide source"><span data-label>Show source</span></button></template>
</div>`;

const doc = () => document.querySelector('[data-mermaid-target="doc"]');
const mermaidPres = () =>
    [...doc().querySelectorAll('code.language-mermaid')].map(
        (code) => code.parentElement,
    );
const hostAfter = (pre) => {
    const host = pre.nextElementSibling;
    expect(host.classList.contains('lp-mermaid')).toBe(true);
    return host;
};

async function start(enabled, library) {
    document.body.innerHTML = page(enabled);
    const before = doc().textContent;
    const loadLibrary = vi
        .spyOn(MermaidController.prototype, 'loadLibrary')
        .mockImplementation(library);
    application = Application.start();
    application.register('mermaid', MermaidController);
    await settle();
    const controller = application.getControllerForElementAndIdentifier(
        document.querySelector('[data-controller]'),
        'mermaid',
    );
    await controller.rendering;
    return { before, controller, loadLibrary };
}

function fakeMermaid() {
    return {
        initialize: vi.fn(),
        render: vi.fn(async (id, source) => {
            if (source.includes('broken')) {
                throw new Error('Parse error');
            }
            return { svg: `<svg id="${id}"><text>diagram</text></svg>` };
        }),
    };
}

afterEach(async () => {
    document.body.replaceChildren();
    await settle();
    application.stop();
    vi.restoreAllMocks();
});

it('shows the disabled notice and loads nothing when the flag is off', async () => {
    const { before, loadLibrary } = await start(false, async () => {
        throw new Error('must not load');
    });

    expect(loadLibrary).not.toHaveBeenCalled();
    for (const pre of mermaidPres()) {
        expect(pre.hidden).toBe(false);
        const host = hostAfter(pre);
        expect(host.childNodes).toHaveLength(0);
        expect(
            host.shadowRoot.querySelector('[data-notice="disabled"]'),
        ).not.toBeNull();
    }
    expect(doc().querySelectorAll('.lp-mermaid')).toHaveLength(2);
    expect(doc().textContent).toBe(before);
});

it('renders each diagram into a shadow root and isolates a failing one', async () => {
    const mermaid = fakeMermaid();
    const { before } = await start(true, async () => mermaid);

    expect(mermaid.initialize).toHaveBeenCalledWith(
        expect.objectContaining({
            startOnLoad: false,
            securityLevel: 'strict',
        }),
    );
    const [rendered, broken] = mermaidPres();

    expect(rendered.hidden).toBe(true);
    const renderedHost = hostAfter(rendered);
    expect(renderedHost.childNodes).toHaveLength(0);
    expect(renderedHost.shadowRoot.querySelector('svg')).not.toBeNull();
    expect(renderedHost.shadowRoot.querySelector('[data-notice]')).toBeNull();

    expect(broken.hidden).toBe(false);
    const brokenHost = hostAfter(broken);
    expect(brokenHost.shadowRoot.querySelector('svg')).toBeNull();
    expect(
        brokenHost.shadowRoot.querySelector('[data-notice="error"]'),
    ).not.toBeNull();

    expect(
        doc().querySelector('code.language-php').parentElement
            .nextElementSibling.tagName,
    ).toBe('P');
    expect(doc().textContent).toBe(before);
});

it('flips the source and its own label from the toggle', async () => {
    const { before } = await start(true, async () => fakeMermaid());
    const [rendered] = mermaidPres();
    const toggle = hostAfter(rendered).shadowRoot.querySelector('button');

    toggle.click();
    expect(rendered.hidden).toBe(false);
    expect(toggle.textContent).toBe('Hide source');
    expect(toggle.getAttribute('aria-expanded')).toBe('true');

    toggle.click();
    expect(rendered.hidden).toBe(true);
    expect(toggle.textContent).toBe('Show source');
    expect(toggle.getAttribute('aria-expanded')).toBe('false');
    expect(doc().textContent).toBe(before);
});

it('keeps every source visible with the error notice when the import rejects', async () => {
    const { before } = await start(true, async () => {
        throw new Error('Network error');
    });

    for (const pre of mermaidPres()) {
        expect(pre.hidden).toBe(false);
        expect(
            hostAfter(pre).shadowRoot.querySelector('[data-notice="error"]'),
        ).not.toBeNull();
    }
    expect(doc().textContent).toBe(before);
});

it('removes its hosts and shows the sources again on disconnect', async () => {
    const { before, controller } = await start(true, async () => fakeMermaid());

    controller.disconnect();

    expect(doc().querySelectorAll('.lp-mermaid')).toHaveLength(0);
    for (const pre of mermaidPres()) {
        expect(pre.hidden).toBe(false);
    }
    expect(doc().textContent).toBe(before);
});

it('adds no second host when it reconnects on a restored snapshot', async () => {
    const { before } = await start(true, async () => fakeMermaid());
    const snapshot = document.body.innerHTML;

    document.body.innerHTML = snapshot;
    await settle();
    const controller = application.getControllerForElementAndIdentifier(
        document.querySelector('[data-controller]'),
        'mermaid',
    );
    await controller.rendering;

    expect(doc().querySelectorAll('.lp-mermaid')).toHaveLength(2);
    expect(mermaidPres()[0].hidden).toBe(true);
    expect(doc().textContent).toBe(before);
});
