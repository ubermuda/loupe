/** @vitest-environment jsdom */
import { Application } from '@hotwired/stimulus';
import { afterEach, beforeEach, expect, it } from 'vitest';
import SiteReviewFilterController from '../../assets/controllers/site_review_filter_controller.js';

let application;
let controller;
const settle = () => new Promise((resolve) => setTimeout(resolve, 0));

beforeEach(async () => {
    document.body.innerHTML = `<div data-controller="site-review-filter">
        <button data-site-review-filter-target="scope" data-site-review-filter-scope-param="all" aria-pressed="true">All</button>
        <button data-site-review-filter-target="scope" data-site-review-filter-scope-param="unlinked" aria-pressed="false">Needs a card</button>
        <input data-site-review-filter-target="query">
        <select data-site-review-filter-target="status"><option value="all">All</option><option value="pending">Pending</option></select>
        <button data-site-review-filter-target="row" data-linked="true" data-status="pending">Linked capture</button>
        <button data-site-review-filter-target="row" data-linked="false" data-status="resolved">Loose capture</button>
        <article data-site-review-filter-target="panel"></article>
        <p data-site-review-filter-target="empty" hidden>No matches</p>
    </div>`;
    application = Application.start();
    application.register('site-review-filter', SiteReviewFilterController);
    await settle();
    controller = application.getControllerForElementAndIdentifier(
        document.querySelector('[data-controller]'),
        'site-review-filter',
    );
});

afterEach(async () => {
    document.body.replaceChildren();
    await settle();
    application.stop();
});

it('connects, and the Needs a card scope keeps only unlinked feedback', () => {
    expect(controller).not.toBeNull();
    controller.setScope({ params: { scope: 'unlinked' } });
    const [linked, loose] = controller.rowTargets;
    expect(linked.hidden).toBe(true);
    expect(loose.hidden).toBe(false);
    expect(controller.scopeTargets[1].getAttribute('aria-pressed')).toBe('true');
    expect(controller.scopeTargets[0].getAttribute('aria-pressed')).toBe('false');
});

it('filters by status and says so when nothing matches', () => {
    controller.statusTarget.value = 'pending';
    controller.setScope({ params: { scope: 'unlinked' } });
    expect(controller.rowTargets.every((row) => row.hidden)).toBe(true);
    expect(controller.emptyTarget.hidden).toBe(false);
});
