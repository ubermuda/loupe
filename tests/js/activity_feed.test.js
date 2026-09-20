/** @vitest-environment jsdom */
import { Application } from '@hotwired/stimulus';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import ActivityController from '../../assets/controllers/activity_filter_controller.js';

let application;
let controller;
const settle = () => new Promise((resolve) => setTimeout(resolve, 0));
const row = (id, family = 'board') =>
    `<article data-activity-event-id="${id}" data-event-family="${family}" data-activity-filter-target="row"><a href="#${id}">${id}</a></article>`;
const snapshot = (rows, project = 'project-a') =>
    `<div data-activity-filter-project-value="${project}">${rows}</div>`;
const response = (rows, project) => ({
    ok: true,
    redirected: false,
    text: async () => snapshot(rows, project),
});

beforeEach(async () => {
    vi.stubGlobal(
        'fetch',
        vi.fn().mockResolvedValue(response(row('original'))),
    );
    window.scrollBy = vi.fn();
    application = Application.start();
    application.register('activity-filter', ActivityController);
    document.body.innerHTML = `<div data-controller="activity-filter"
        data-activity-filter-url-value="/projects/project-a/activity"
        data-activity-filter-project-value="project-a"
        data-activity-filter-page-size-value="50"
        data-activity-filter-labels-value='{"connecting":"Connecting","listening":"Live","paused":"Paused","failed":"Failed","stale":"Stale","pause":"Pause","resume":"Resume","listeningTitle":"Updates every 10 seconds"}'>
        <button data-activity-filter-target="toggle" data-action="activity-filter#toggle" disabled>Pause</button>
        <span data-activity-filter-target="status"></span>
        <div data-activity-filter-target="filters"></div>
        <input data-activity-filter-target="query">
        <select data-activity-filter-target="family"><option value="all">All</option><option value="document">Document</option></select>
        <span data-activity-filter-target="count"></span>
        <p data-activity-filter-target="gap" hidden>Gap</p>
        <div data-activity-filter-target="feed">${row('original')}</div>
        <p data-activity-filter-target="empty" hidden>No matches</p>
        <p data-activity-filter-target="noEvents" hidden>No events</p>
    </div>`;
    await settle();
    controller = application.getControllerForElementAndIdentifier(
        document.querySelector('[data-controller]'),
        'activity-filter',
    );
});

afterEach(async () => {
    document.body.replaceChildren();
    await settle();
    application.stop();
    vi.unstubAllGlobals();
});

it('reconciles on resume without duplicate rows and keeps the active filter', async () => {
    controller.familyTarget.value = 'document';
    controller.toggle();
    expect(controller.element.dataset.activityState).toBe('paused');
    expect(controller.toggleTarget.getAttribute('aria-pressed')).toBe('true');
    fetch.mockResolvedValue(response(row('new', 'document') + row('original')));
    controller.toggle();
    await settle();
    expect(controller.element.dataset.activityState).toBe('listening');
    expect(
        controller.rowTargets.map((element) => element.dataset.activityEventId),
    ).toEqual(['new', 'original']);
    expect(controller.rowTargets[1].hidden).toBe(true);
    expect(controller.countTarget.textContent).toBe('1 / 2');
    await controller.refresh();
    expect(controller.rowTargets).toHaveLength(2);
});

it('ignores an in-flight response after pause', async () => {
    let finish;
    fetch.mockReturnValue(
        new Promise((resolve) => {
            finish = resolve;
        }),
    );
    const pending = controller.refresh();
    controller.toggle();
    finish(response(row('late')));
    await pending;
    expect(controller.rowTargets).toHaveLength(1);
    expect(controller.rowTargets[0].textContent).toBe('original');
    expect(controller.element.dataset.activityState).toBe('paused');
});

it('polls automatically, stops while paused, and resumes immediately', async () => {
    clearTimeout(controller.timer);
    vi.useFakeTimers();
    try {
        await controller.refresh();
        fetch.mockClear();
        await vi.advanceTimersByTimeAsync(10000);
        expect(fetch).toHaveBeenCalledTimes(1);
        controller.toggle();
        await vi.advanceTimersByTimeAsync(30000);
        expect(fetch).toHaveBeenCalledTimes(1);
        controller.toggle();
        await vi.advanceTimersByTimeAsync(0);
        expect(fetch).toHaveBeenCalledTimes(2);
    } finally {
        controller.disconnect();
        vi.useRealTimers();
    }
});

it('ignores a response from an earlier connection after reconnecting', async () => {
    let finish;
    fetch.mockReturnValueOnce(
        new Promise((resolve) => {
            finish = resolve;
        }),
    );
    const previous = controller.refresh();
    controller.disconnect();
    fetch.mockResolvedValue(response(row('current')));
    controller.connect();
    await settle();
    finish(response(row('obsolete')));
    await previous;
    expect(
        controller.rowTargets.map((element) => element.dataset.activityEventId),
    ).toEqual(['current', 'original']);
});

it('retains history on failure and rejects another project or a login redirect', async () => {
    for (const result of [
        { ok: false },
        response(row('foreign'), 'project-b'),
        { ok: true, redirected: true },
    ]) {
        fetch.mockResolvedValue(result);
        await controller.refresh();
        expect(controller.element.dataset.activityState).toBe('stale');
        expect(controller.rowTargets).toHaveLength(1);
        expect(controller.rowTargets[0].textContent).toBe('original');
        expect(controller.noEventsTarget.hidden).toBe(true);
    }
});

it('keeps focus and compensates for new rows above the reading position', async () => {
    const scroller = controller.element;
    scroller.style.overflowY = 'auto';
    Object.defineProperty(scroller, 'scrollHeight', { value: 1000 });
    Object.defineProperty(scroller, 'clientHeight', { value: 500 });
    scroller.scrollTop = 100;
    const original = controller.rowTargets[0];
    original.querySelector('a').focus();
    original.getBoundingClientRect = vi
        .fn()
        .mockReturnValueOnce({ top: 20, bottom: 40 })
        .mockReturnValueOnce({ top: 20, bottom: 40 })
        .mockReturnValueOnce({ top: 20, bottom: 40 })
        .mockReturnValueOnce({ top: 70, bottom: 90 });
    fetch.mockResolvedValue(response(row('new') + row('original')));
    await controller.refresh();
    expect(document.activeElement).toBe(original.querySelector('a'));
    expect(scroller.scrollTop).toBe(150);
    expect(window.scrollBy).not.toHaveBeenCalled();
});

it('uses the server page size to detect a full non-overlapping window', async () => {
    fetch.mockResolvedValue(
        response(
            Array.from({ length: 50 }, (_, index) => row(`new-${index}`)).join(
                '',
            ),
        ),
    );
    await controller.refresh();
    expect(controller.gapTarget.hidden).toBe(false);
    expect(controller.rowTargets).toHaveLength(51);
});

it('distinguishes failed loading from an empty successful snapshot', async () => {
    controller.hasRefreshed = false;
    fetch.mockRejectedValue(new Error('Offline'));
    await controller.refresh();
    expect(controller.element.dataset.activityState).toBe('failed');
    controller.feedTarget.replaceChildren();
    fetch.mockResolvedValue(response(''));
    await controller.refresh();
    expect(controller.element.dataset.activityState).toBe('listening');
    expect(controller.noEventsTarget.hidden).toBe(false);
    expect(controller.emptyTarget.hidden).toBe(true);
});

it('hides the filter bar until the project has an event', async () => {
    controller.feedTarget.replaceChildren();
    fetch.mockResolvedValue(response(''));
    await controller.refresh();
    expect(controller.filtersTarget.hidden).toBe(true);
    fetch.mockResolvedValue(response(row('first')));
    await controller.refresh();
    expect(controller.filtersTarget.hidden).toBe(false);
});

it('names the refresh interval only while the feed is live', async () => {
    await controller.refresh();
    expect(controller.statusTarget.textContent).toBe('Live');
    expect(controller.statusTarget.title).toBe('Updates every 10 seconds');
    controller.toggle();
    expect(controller.statusTarget.title).toBe('');
});
