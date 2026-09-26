/** @vitest-environment jsdom */
import { Application } from '@hotwired/stimulus';
import { afterEach, beforeEach, expect, it } from 'vitest';
import CostChartController from '../../assets/controllers/cost_chart_controller.js';

let application;
const settle = () => new Promise((resolve) => setTimeout(resolve, 0));

const bar = (index) =>
    document.querySelectorAll('[data-cost-chart-target="bar"]')[index];
const card = (index) => document.getElementById(`cost-card-${index}`);

beforeEach(async () => {
    document.body.innerHTML = `<figure data-controller="cost-chart" data-action="keydown.esc->cost-chart#hide">
        <div class="lp-cost-chart__plot">
            <svg>
                <a href="/projects/p/board/cards/one" data-cost-chart-target="bar" data-cost-chart-index-param="0"
                   data-action="mouseenter->cost-chart#show focus->cost-chart#show mouseleave->cost-chart#hide blur->cost-chart#hide"><rect /><path /></a>
                <a href="/projects/p/board/cards/two" data-cost-chart-target="bar" data-cost-chart-index-param="1"
                   data-action="mouseenter->cost-chart#show focus->cost-chart#show mouseleave->cost-chart#hide blur->cost-chart#hide"><rect /><path /></a>
            </svg>
            <div id="cost-card-0" role="tooltip" hidden data-cost-chart-target="card">#1 First</div>
            <div id="cost-card-1" role="tooltip" hidden data-cost-chart-target="card">#2 Second</div>
        </div>
    </figure>`;
    const plot = document.querySelector('.lp-cost-chart__plot');
    plot.getBoundingClientRect = () => ({
        left: 100,
        top: 50,
        width: 800,
        height: 300,
    });
    // The link holds a hit area as tall as the plot, and the painted bar sits lower.
    bar(0).getBoundingClientRect = () => ({
        left: 200,
        top: 60,
        width: 10,
        height: 190,
    });
    bar(0).querySelector('path').getBoundingClientRect = () => ({ top: 150 });
    bar(1).getBoundingClientRect = () => ({
        left: 880,
        top: 60,
        width: 10,
        height: 190,
    });
    bar(1).querySelector('path').getBoundingClientRect = () => ({ top: 70 });
    application = Application.start();
    application.register('cost-chart', CostChartController);
    await settle();
});

afterEach(async () => {
    document.body.replaceChildren();
    await settle();
    application.stop();
});

it('shows the hover card of a bar above it when the pointer enters', () => {
    bar(0).dispatchEvent(new MouseEvent('mouseenter'));

    expect(card(0).hidden).toBe(false);
    expect(card(1).hidden).toBe(true);
    expect(card(0).style.left).toBe('105px');
    expect(card(0).style.top).toBe('92px');
});

it('shows one hover card at a time and hides it when the pointer leaves', () => {
    bar(0).dispatchEvent(new MouseEvent('mouseenter'));
    bar(1).dispatchEvent(new MouseEvent('mouseenter'));

    expect(card(0).hidden).toBe(true);
    expect(card(1).hidden).toBe(false);

    bar(1).dispatchEvent(new MouseEvent('mouseleave'));
    expect(card(1).hidden).toBe(true);
});

it('shows the hover card on keyboard focus and hides it on Escape', () => {
    bar(1).dispatchEvent(new FocusEvent('focus'));
    expect(card(1).hidden).toBe(false);

    bar(1).dispatchEvent(
        new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }),
    );
    expect(card(1).hidden).toBe(true);
});

it('keeps a hover card inside the plot near its right edge', () => {
    card(1).getBoundingClientRect = () => ({ width: 256, height: 120 });
    bar(1).dispatchEvent(new MouseEvent('mouseenter'));

    // Centred at 128 from each edge, so a card 256 wide never overflows.
    expect(card(1).style.left).toBe('672px');
});

it('keeps a hover card inside a plot that is narrower than the card', () => {
    const plot = document.querySelector('.lp-cost-chart__plot');
    plot.getBoundingClientRect = () => ({
        left: 10,
        top: 50,
        width: 200,
        height: 300,
    });
    bar(0).getBoundingClientRect = () => ({
        left: 15,
        top: 60,
        width: 4,
        height: 190,
    });
    card(0).getBoundingClientRect = () => ({ width: 256, height: 120 });
    bar(0).dispatchEvent(new MouseEvent('mouseenter'));

    // CSS caps the card at the plot width, so its centre sits in the middle.
    expect(card(0).style.left).toBe('100px');
});

it('places the hover card above the labels of a bar', () => {
    const label = document.createElementNS(
        'http://www.w3.org/2000/svg',
        'text',
    );
    label.getBoundingClientRect = () => ({ top: 120 });
    bar(0).appendChild(label);
    bar(0).dispatchEvent(new MouseEvent('mouseenter'));

    expect(card(0).style.top).toBe('62px');
});

it('opens a hover card below the plot when the room above the bar is too small', () => {
    card(1).getBoundingClientRect = () => ({ width: 256, height: 400 });
    bar(1).dispatchEvent(new MouseEvent('mouseenter'));

    expect(card(1).classList.contains('lp-cost-card--below')).toBe(true);
    // The bar box ends at 250 in the viewport and the plot starts at 50.
    expect(card(1).style.top).toBe('208px');
    // jsdom's window is 768 tall, so 510 is left below the plot.
    expect(card(1).style.maxHeight).toBe('');
});

it('cuts a hover card that fits neither above nor below to the larger side', () => {
    window.innerHeight = 400;
    card(1).getBoundingClientRect = () => ({ width: 256, height: 900 });
    bar(1).dispatchEvent(new MouseEvent('mouseenter'));

    // 142 below the plot beats 62 above the painted marks.
    expect(card(1).classList.contains('lp-cost-card--below')).toBe(true);
    expect(card(1).style.maxHeight).toBe('142px');
    window.innerHeight = 768;
});
