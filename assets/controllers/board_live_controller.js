import { Controller } from '@hotwired/stimulus';
import { renderStreamMessage } from '@hotwired/turbo';
import { on, status } from '../lib/live.js';

/**
 * Shows each card change on the board as it happens. A burst of messages for
 * one card costs one placement fetch, a card in a drag waits until the drag
 * settles, and a card another person changed is marked for a moment. A failed
 * placement retries with a growing wait, and after the last retry the card is
 * marked stale. A reconnect places each card the page missed.
 *
 * A column change, or a reconnect that finds another board structure, updates
 * the columns and lanes in place. A burst costs one structure fetch, and a
 * drag, a pending move or an open dialog defers it.
 *
 * A failed catch-up or structure update retries with the same waits. After
 * the last retry, the live region says that live updates stopped.
 *
 * `own` never skips the fetch. A member can send another tab's origin, so the
 * flag may only suppress the mark.
 */

const SETTLE_MILLISECONDS = 150;
const BUSY_RETRY_MILLISECONDS = 200;
// A stalled request would hold the queue for every card.
const FETCH_TIMEOUT_MILLISECONDS = 10000;
const RETRY_MILLISECONDS = [1000, 3000, 9000];
const RETRY_JITTER = 0.2;
const FLASH_MILLISECONDS = 1500;
const STREAM_TYPE = 'text/vnd.turbo-stream.html';
const FLASH_CLASS = 'lp-board-card--flash';
// A lane epic has no card face. The head of its lane stands for it.
const STALE_MARKS = [
    [
        (cardId) => document.getElementById(`board-card-${cardId}`),
        'lp-board-card--stale',
    ],
    [
        (cardId) => document.getElementById(`board-row-${cardId}`),
        'lp-board-list__row--stale',
    ],
    [laneHeadOf, 'lp-board-lane__head--stale'],
];
const RETRY = 'retry';
const STALE = 'stale';
const STRUCTURE_SETTLE_MILLISECONDS = 300;
const STRUCTURE_MAX_WAIT_MILLISECONDS = 2000;
const RENDER_TIMEOUT_MILLISECONDS = 5000;
const CONNECTED_ATTRIBUTE = 'data-board-live-connected';
const BUSY_SELECTOR = [
    '.lp-board--dragging',
    '[data-board-drag-target="card"][aria-busy="true"]',
    'dialog[open]',
].join(', ');

export default class extends Controller {
    static targets = ['paused'];
    static values = {
        placement: String,
        placeholder: String,
        stale: String,
        manifest: String,
        structure: String,
    };

    initialize() {
        this.liveState = 'off';
        this.failed = false;
    }

    connect() {
        this.pending = new Map();
        this.expected = new Map();
        this.flashes = new Map();
        this.queue = Promise.resolve();
        this.onPlaced = (event) => this.placed(event.detail ?? {});
        this.onMissed = (event) => this.missed(event.detail ?? {});
        // A row that changed while the list loaded missed its placement.
        this.onFrameRender = (event) => {
            if (event.target.id === 'board-list') {
                this.catchUp();
            }
        };
        document.addEventListener('board:placed', this.onPlaced);
        document.addEventListener('board:place-missed', this.onMissed);
        document.addEventListener('turbo:frame-render', this.onFrameRender);
        this.unsubscribe = on(
            ['board.card_changed', 'worker_run.card_warning_changed'],
            (change) => this.receive(change),
            { onReconnect: () => this.catchUp() },
        );
        this.resyncRunning = false;
        this.resyncAgain = false;
        this.resyncAttempts = 0;
        this.stopColumns = on(
            'board.columns_changed',
            () => this.resyncStructure(),
            {
                onOpen: () =>
                    this.element.setAttribute(CONNECTED_ATTRIBUTE, ''),
                onError: () =>
                    this.element.removeAttribute(CONNECTED_ATTRIBUTE),
            },
        );
        this.stopStatus = status((state) => {
            this.liveState = state;
            this.pausedTargets.forEach((element) => this.showStatus(element));
        });
    }

    disconnect() {
        this.unsubscribe?.();
        this.stopColumns?.();
        this.stopStatus?.();
        this.manifestAbort?.abort();
        this.manifestAbort = undefined;
        this.structureAbort?.abort();
        this.structureAbort = undefined;
        clearTimeout(this.resyncTimer);
        this.resyncTimer = undefined;
        clearTimeout(this.catchUpTimer);
        this.catchUpTimer = undefined;
        this.resyncSince = undefined;
        this.resyncRunning = false;
        this.resyncAgain = false;
        this.element.removeAttribute(CONNECTED_ATTRIBUTE);
        document.removeEventListener('board:placed', this.onPlaced);
        document.removeEventListener('board:place-missed', this.onMissed);
        document.removeEventListener('turbo:frame-render', this.onFrameRender);
        this.pending.forEach((entry) => clearTimeout(entry.timer));
        this.pending.clear();
        this.expected.clear();
        this.flashes.forEach((timer) => clearTimeout(timer));
        this.flashes.clear();
    }

    pausedTargetConnected(element) {
        this.showStatus(element);
    }

    /** The live region stays in place, so a screen reader hears the new text. */
    showStatus(element) {
        let text = '';
        if (this.liveState === 'paused') {
            text = element.dataset.message ?? '';
        } else if (this.failed) {
            text = element.dataset.failedMessage ?? '';
        }
        if (element.textContent !== text) {
            element.textContent = text;
        }
    }

    setFailed(failed) {
        this.failed = failed;
        this.pausedTargets.forEach((element) => this.showStatus(element));
    }

    /**
     * After a reconnect, compares the page with the board manifest and places
     * each card that the page missed or shows out of order.
     */
    async catchUp(attempt = 0) {
        clearTimeout(this.catchUpTimer);
        this.catchUpTimer = undefined;
        const read = await this.readManifest();
        if (read === undefined) {
            return;
        }
        const { manifest, final } = read;
        if (!isManifest(manifest)) {
            if (final || attempt >= RETRY_MILLISECONDS.length) {
                this.setFailed(true);

                return;
            }
            this.catchUpTimer = setTimeout(
                () => this.catchUp(attempt + 1),
                retryWait(attempt),
            );

            return;
        }
        const structure =
            this.element.querySelector('#board')?.dataset.boardStructureDigest;
        if (manifest.structure !== structure) {
            this.resyncStructure();

            return;
        }
        this.cardPass(manifest, { afterResync: false });
        this.setFailed(false);
    }

    /**
     * Answers undefined when a newer read or a disconnect superseded this one.
     * The manifest is null when the read failed, and `final` is true when the
     * answer says that a retry cannot help.
     */
    async readManifest() {
        this.manifestAbort?.abort();
        const abort = new AbortController();
        this.manifestAbort = abort;
        const timeout = setTimeout(
            () => abort.abort(),
            FETCH_TIMEOUT_MILLISECONDS,
        );
        let manifest = null;
        let final = false;
        try {
            // A read of the board's card digests, with no form to submit.
            // eslint-disable-next-line no-restricted-syntax
            const response = await fetch(this.manifestValue, {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
                signal: abort.signal,
            });
            if (response.ok) {
                manifest = await response.json();
            } else {
                final = !this.transient(response.status);
            }
        } catch {
            manifest = null;
        } finally {
            clearTimeout(timeout);
        }
        if (this.manifestAbort !== abort) {
            return undefined;
        }
        this.manifestAbort = undefined;

        return { manifest, final };
    }

    /** The first request of a burst bounds the wait, so a steady stream still resyncs. */
    resyncStructure() {
        if (this.resyncRunning) {
            this.resyncAgain = true;

            return;
        }
        this.resyncSince ??= Date.now();
        const untilMaxWait =
            this.resyncSince + STRUCTURE_MAX_WAIT_MILLISECONDS - Date.now();
        this.scheduleResync(
            Math.max(0, Math.min(STRUCTURE_SETTLE_MILLISECONDS, untilMaxWait)),
        );
    }

    scheduleResync(delay) {
        clearTimeout(this.resyncTimer);
        this.resyncTimer = setTimeout(() => this.runResync(), delay);
    }

    /**
     * A render during a drag swaps the elements the drag holds, and one under
     * a dialog closes it. The manifest pass skips the structure check, so a
     * digest that lags the render cannot start another resync.
     */
    async runResync() {
        this.resyncTimer = undefined;
        if (this.boardBusy()) {
            this.scheduleResync(STRUCTURE_SETTLE_MILLISECONDS);

            return;
        }
        this.resyncSince = undefined;
        this.resyncRunning = true;
        clearTimeout(this.catchUpTimer);
        this.catchUpTimer = undefined;
        const abort = new AbortController();
        this.structureAbort = abort;
        const { html, final } = await this.readStructure(abort);
        if (this.structureAbort !== abort) {
            return;
        }
        if (html === null) {
            this.resyncFailed(final);

            return;
        }
        if (this.boardBusy()) {
            this.structureAbort = undefined;
            this.resyncRunning = false;
            this.resyncAgain = false;
            this.scheduleResync(STRUCTURE_SETTLE_MILLISECONDS);

            return;
        }
        // The stream renders on a later frame, and the card pass reads the new skeleton.
        const rendered = this.structureRendered(abort.signal);
        renderStreamMessage(html);
        const [read, applied] = await Promise.all([
            this.readManifest(),
            rendered,
        ]);
        if (this.structureAbort !== abort) {
            return;
        }
        // A superseded read leaves the card pass to the reconnect that took over.
        if (read === undefined && applied) {
            this.resyncAttempts = 0;
            this.finishResync();

            return;
        }
        if (!applied || !isManifest(read?.manifest)) {
            this.resyncFailed(read?.final ?? false);

            return;
        }
        this.cardPass(read.manifest, { afterResync: true });
        this.resyncAttempts = 0;
        this.setFailed(false);
        this.finishResync();
    }

    /** A retry reads the structure again, so it covers the changes that arrived meanwhile. */
    resyncFailed(final) {
        this.structureAbort = undefined;
        this.resyncRunning = false;
        this.resyncAgain = false;
        this.resyncAttempts += 1;
        if (final || this.resyncAttempts > RETRY_MILLISECONDS.length) {
            this.resyncAttempts = 0;
            this.setFailed(true);

            return;
        }
        this.scheduleResync(retryWait(this.resyncAttempts - 1));
    }

    /** Resolves true once the structure stream applied, or false on a timeout or an abort. */
    structureRendered(signal) {
        return new Promise((resolve) => {
            const done = (applied) => {
                clearTimeout(timeout);
                document.removeEventListener(
                    'board:structure-changed',
                    onChanged,
                );
                signal.removeEventListener('abort', onAbort);
                resolve(applied);
            };
            const onChanged = () => done(true);
            const onAbort = () => done(false);
            const timeout = setTimeout(
                () => done(false),
                RENDER_TIMEOUT_MILLISECONDS,
            );
            document.addEventListener('board:structure-changed', onChanged);
            signal.addEventListener('abort', onAbort);
        });
    }

    /** Answers the stream, or null; `final` is true when a retry cannot help. */
    async readStructure(abort) {
        const timeout = setTimeout(
            () => abort.abort(),
            FETCH_TIMEOUT_MILLISECONDS,
        );
        try {
            // A read of the board's columns and lanes, with no form to submit.
            // eslint-disable-next-line no-restricted-syntax
            const response = await fetch(this.structureValue, {
                headers: { Accept: STREAM_TYPE },
                credentials: 'same-origin',
                signal: abort.signal,
            });
            const type = response.headers.get('Content-Type') ?? '';
            if (response.ok && type.startsWith(STREAM_TYPE)) {
                return { html: await response.text(), final: false };
            }

            return {
                html: null,
                final: !response.ok && !this.transient(response.status),
            };
        } catch {
            return { html: null, final: false };
        } finally {
            clearTimeout(timeout);
        }
    }

    finishResync() {
        this.structureAbort = undefined;
        this.resyncRunning = false;
        if (this.resyncAgain) {
            this.resyncAgain = false;
            this.resyncStructure();
        }
    }

    boardBusy() {
        return this.element.querySelector(BUSY_SELECTOR) !== null;
    }

    /**
     * Places each card that the page misses, shows out of order, in another
     * lane, or with another digest than the manifest has.
     */
    cardPass(manifest, { afterResync }) {
        const shown = new Map();
        this.element
            .querySelectorAll('.lp-board-card[data-card-digest]')
            .forEach((card) =>
                shown.set(card.dataset.cardId, card.dataset.cardDigest),
            );
        // A lane epic has no card face: its lane head digest comes fifth, and
        // it counts for the list order alone.
        const listed = new Map(
            manifest.cards.map(
                ([cardId, , columnId, laneKey, headDigest], index) => [
                    cardId,
                    {
                        columnId,
                        laneKey,
                        index,
                        laneHead: headDigest !== undefined,
                    },
                ],
            ),
        );
        const faces = manifest.cards.filter(
            ([, , , , headDigest]) => headDigest === undefined,
        );
        const backlogId = this.element.querySelector(
            '.lp-board-backlog[data-column]',
        )?.dataset.column;
        // A Backlog card with no epic has no placement to carry the count.
        const backlogCount = document.getElementById(
            `board-count-${backlogId}`,
        );
        if (backlogCount !== null && Number.isInteger(manifest.backlogCount)) {
            backlogCount.textContent = String(manifest.backlogCount);
        }
        const rowIds = new Set(
            [
                ...this.element.querySelectorAll(
                    '.lp-board-list__row[data-card-id]',
                ),
            ].map((row) => row.dataset.cardId),
        );
        // A lane epic has no face, so only its list row shows that it is gone.
        const removed = [
            ...new Set([
                ...[...shown.keys()].filter(
                    (cardId) =>
                        !listed.has(cardId) || listed.get(cardId).laneHead,
                ),
                ...[...rowIds].filter((cardId) => !listed.has(cardId)),
            ]),
        ];
        const moved = new Set();
        this.element
            .querySelectorAll(
                '.lp-board__group[data-column], .lp-board-lane__cell[data-column]',
            )
            .forEach((group) => {
                const lane = group.matches('.lp-board-lane__cell')
                    ? group.dataset.lane
                    : null;
                const cards = [...group.querySelectorAll('.lp-board-card')]
                    .filter((card) => listed.has(card.dataset.cardId))
                    .map((card) => ({
                        cardId: card.dataset.cardId,
                        ...listed.get(card.dataset.cardId),
                    }))
                    .filter((card) => !card.laneHead);
                cards
                    .filter((card) => card.laneKey !== lane)
                    .forEach((card) => moved.add(card.cardId));
                outOfOrder(
                    cards.filter(
                        (card) => card.columnId === group.dataset.column,
                    ),
                ).forEach((cardId) => moved.add(cardId));
            });
        // Two lane cells of one column can each keep their order while the
        // list, which runs through the whole column, does not.
        const rows = [
            ...this.element.querySelectorAll(
                '.lp-board-list__row[data-card-id]',
            ),
        ]
            .filter((row) => listed.has(row.dataset.cardId))
            .map((row) => ({
                cardId: row.dataset.cardId,
                ...listed.get(row.dataset.cardId),
            }));
        outOfOrder(rows).forEach((cardId) => moved.add(cardId));
        // A row shares its card's digest. The list loads only while shown,
        // so a page with no list checks the faces and lane heads alone.
        const hasList = this.element.querySelector('.lp-board-list') !== null;
        const rowsById = new Map();
        this.element
            .querySelectorAll('.lp-board-list__row[data-card-digest]')
            .forEach((row) => rowsById.set(row.dataset.cardId, row));
        const changed = manifest.cards
            .filter(([cardId, digest, columnId, , headDigest]) => {
                if (moved.has(cardId)) {
                    return true;
                }
                if (headDigest === undefined) {
                    return (
                        shown.get(cardId) !== digest ||
                        (hasList &&
                            rowsById.get(cardId)?.dataset.cardDigest !== digest)
                    );
                }
                // A lane epic in the Backlog has a head and no list row.
                if (hasList && columnId !== backlogId && !rowIds.has(cardId)) {
                    return true;
                }
                const head = laneHeadOf(cardId);

                return (
                    (rowsById.has(cardId) &&
                        rowsById.get(cardId).dataset.cardDigest !== digest) ||
                    (head?.dataset.laneDigest !== undefined &&
                        head.dataset.laneDigest !== headDigest)
                );
            })
            .map(([cardId]) => cardId);
        const queued = [...removed, ...changed];
        // Any placement rewrites every history link, so one card is enough.
        // With no card face, the structure render rewrites them, once.
        if (queued.length === 0 && staleHistory(manifest.terminalTotals)) {
            if (faces.length > 0) {
                queued.push(faces[0][0]);
            } else if (!afterResync) {
                this.resyncStructure();
            }
        }
        queued.forEach((cardId) =>
            this.receive({ cardId, local: false, own: false }),
        );
    }

    receive(change) {
        const cardId = change.cardId;
        if (typeof cardId !== 'string' || cardId === '') {
            return;
        }
        const entry = this.entryFor(cardId);
        entry.attempts = 0;
        entry.remote ||= !change.local && !change.own;
        if (entry.inFlight) {
            entry.again = true;

            return;
        }
        this.schedule(cardId, entry, SETTLE_MILLISECONDS);
    }

    /**
     * An entry lives until its card is placed or marked stale, so the count
     * of failed attempts survives a render that reports a miss later.
     */
    entryFor(cardId) {
        if (!this.pending.has(cardId)) {
            this.pending.set(cardId, {
                timer: undefined,
                inFlight: false,
                again: false,
                remote: false,
                attempts: 0,
            });
        }

        return this.pending.get(cardId);
    }

    schedule(cardId, entry, delay) {
        clearTimeout(entry.timer);
        entry.timer = setTimeout(
            () => this.fetchPlacement(cardId, entry),
            delay,
        );
    }

    /** One placement at a time, so an older answer never renders last. */
    fetchPlacement(cardId, entry) {
        entry.timer = undefined;
        entry.inFlight = true;
        const run = this.queue.then(() => this.place(cardId, entry));
        this.queue = run.catch(() => {});
    }

    async place(cardId, entry) {
        if (this.pending.get(cardId) !== entry) {
            return;
        }
        const card = document.getElementById(`board-card-${cardId}`);
        if (this.busyFor(cardId)) {
            entry.inFlight = false;
            entry.again = false;
            this.schedule(cardId, entry, BUSY_RETRY_MILLISECONDS);

            return;
        }

        this.expected.set(cardId, {
            digest: card?.dataset.cardDigest,
            group: card?.parentElement,
            previous: previousCardId(card),
            remote: entry.remote,
        });
        entry.remote = false;
        entry.again = false;

        let html = null;
        let failure = RETRY;
        const abort = new AbortController();
        const timeout = setTimeout(
            () => abort.abort(),
            FETCH_TIMEOUT_MILLISECONDS,
        );
        try {
            // A read of one card's placement, with no form to submit.
            // eslint-disable-next-line no-restricted-syntax
            const response = await fetch(this.urlFor(cardId), {
                headers: { Accept: STREAM_TYPE },
                credentials: 'same-origin',
                signal: abort.signal,
            });
            const type = response.headers.get('Content-Type') ?? '';
            if (response.ok && type.startsWith(STREAM_TYPE)) {
                html = await response.text();
            } else if (!response.ok && !this.transient(response.status)) {
                failure = STALE;
            }
        } catch {
            html = null;
        } finally {
            clearTimeout(timeout);
        }

        entry.inFlight = false;
        if (this.pending.get(cardId) !== entry) {
            return;
        }
        if (this.busyFor(cardId)) {
            entry.remote ||= this.expected.get(cardId)?.remote ?? false;
            entry.again = false;
            this.expected.delete(cardId);
            this.schedule(cardId, entry, BUSY_RETRY_MILLISECONDS);

            return;
        }
        if (entry.again) {
            entry.again = false;
            this.schedule(cardId, entry, SETTLE_MILLISECONDS);
        }
        if (html === null) {
            entry.remote ||= this.expected.get(cardId)?.remote ?? false;
            this.expected.delete(cardId);
            if (entry.timer === undefined) {
                this.fail(cardId, entry, failure);
            }
        } else {
            // A miss can report inside this call or on a later frame.
            renderStreamMessage(html);
        }
    }

    /** A 403 or a 404 means the board is off or access is gone, so a retry cannot help. */
    transient(status) {
        return status >= 500 || status === 429 || status === 408;
    }

    fail(cardId, entry, failure) {
        entry.attempts += 1;
        if (failure === STALE || entry.attempts > RETRY_MILLISECONDS.length) {
            this.pending.delete(cardId);
            this.markStale(cardId);

            return;
        }
        this.schedule(cardId, entry, retryWait(entry.attempts - 1));
    }

    markStale(cardId) {
        STALE_MARKS.forEach(([find, className]) => {
            const element = find(cardId);
            if (element === null || element.hasAttribute('data-board-stale')) {
                return;
            }
            element.classList.add(className);
            element.setAttribute('data-board-stale', '');
            element.title = this.staleValue;
            const text = document.createElement('span');
            text.className = 'sr-only';
            text.dataset.boardStaleText = '';
            text.textContent = this.staleValue;
            element.append(text);
        });
    }

    /** The morph of fresh markup drops the mark too; this covers any other render. */
    clearStale(cardId) {
        STALE_MARKS.forEach(([find, className]) => {
            const element = find(cardId);
            if (element === null || !element.hasAttribute('data-board-stale')) {
                return;
            }
            element.classList.remove(className);
            element.removeAttribute('data-board-stale');
            element.removeAttribute('title');
            element.querySelector('[data-board-stale-text]')?.remove();
        });
    }

    /**
     * Whether the card is in a drag or has a move the server has not placed
     * yet. The head of a lane epic morphs its whole deck, so a deck card in a
     * drag holds the epic too.
     */
    busyFor(cardId) {
        const element =
            document.getElementById(`board-card-${cardId}`) ??
            deckCardOf(cardId) ??
            laneHeadOf(cardId);

        return (
            element !== null &&
            (busy(element) ||
                [...element.querySelectorAll('.lp-deck__card')].some(busy))
        );
    }

    urlFor(cardId) {
        return this.placementValue.replace(
            this.placeholderValue,
            encodeURIComponent(cardId),
        );
    }

    placed({ cardId, removed, leftDeck, deckEpic }) {
        this.clearStale(cardId);
        // A Backlog card has no placement of its own, so its epic redraws the
        // deck that shows it. A card that changes its epic leaves one deck
        // and joins another, and a card new to the Backlog joins one only.
        const redrawn = removed
            ? [deckCardOf(cardId)?.closest('.lp-deck')?.dataset.lane, deckEpic]
            : [leftDeck];
        new Set(redrawn.filter((epic) => epic != null && epic !== '')).forEach(
            (epic) => this.receive({ cardId: epic, local: false, own: false }),
        );
        const entry = this.pending.get(cardId);
        if (entry !== undefined) {
            entry.attempts = 0;
            if (!entry.inFlight && entry.timer === undefined) {
                this.pending.delete(cardId);
            }
        }
        const expected = this.expected.get(cardId);
        if (expected === undefined) {
            return;
        }
        this.expected.delete(cardId);
        const card = document.getElementById(`board-card-${cardId}`);
        if (
            removed ||
            !expected.remote ||
            card === null ||
            (card.dataset.cardDigest === expected.digest &&
                card.parentElement === expected.group &&
                previousCardId(card) === expected.previous)
        ) {
            return;
        }
        this.flash(cardId, card);
    }

    flash(cardId, card) {
        clearTimeout(this.flashes.get(cardId));
        card.classList.remove(FLASH_CLASS);
        // Reading the layout makes the browser start the animation again.
        void card.offsetWidth;
        card.classList.add(FLASH_CLASS);
        this.flashes.set(
            cardId,
            setTimeout(() => {
                this.flashes.delete(cardId);
                card.classList.remove(FLASH_CLASS);
            }, FLASH_MILLISECONDS),
        );
    }

    /** A newer fetch or a waiting one supersedes the attempt that missed. */
    missed({ cardId }) {
        if (typeof cardId !== 'string' || cardId === '') {
            return;
        }
        const remote = this.expected.get(cardId)?.remote ?? false;
        this.expected.delete(cardId);
        const entry = this.entryFor(cardId);
        entry.remote ||= remote;
        if (entry.inFlight || entry.timer !== undefined) {
            return;
        }
        this.fail(cardId, entry, RETRY);
    }
}

function retryWait(index) {
    return RETRY_MILLISECONDS[index] * (1 + RETRY_JITTER * Math.random());
}

function isManifest(manifest) {
    return (
        typeof manifest?.structure === 'string' &&
        Array.isArray(manifest.cards) &&
        manifest.cards.every(
            (entry) =>
                Array.isArray(entry) &&
                typeof entry[0] === 'string' &&
                typeof entry[1] === 'string' &&
                typeof entry[2] === 'string' &&
                (typeof entry[3] === 'string' || entry[3] === null) &&
                (entry.length === 4 ||
                    (entry.length === 5 && typeof entry[4] === 'string')),
        )
    );
}

/** A terminal column whose history link on the page shows another total. */
function staleHistory(totals) {
    return Object.entries(totals ?? {}).some(([columnId, total]) => {
        const link = document.getElementById(`board-history-${columnId}`);

        return link !== null && link.dataset.historyTotal !== String(total);
    });
}

function laneHeadOf(cardId) {
    return (
        document
            .getElementById(`board-lane-${cardId}`)
            ?.querySelector('.lp-board-lane__head') ?? null
    );
}

/** A card in a drag, or with a move the server has not placed yet. */
function busy(card) {
    return (
        card.getAttribute('aria-busy') === 'true' ||
        card.classList.contains('lp-board-card--dragging')
    );
}

function deckCardOf(cardId) {
    return document.getElementById(`board-deck-card-${cardId}`);
}

function previousCardId(card) {
    let sibling = card?.previousElementSibling;
    while (sibling && !sibling.matches('.lp-board-card')) {
        sibling = sibling.previousElementSibling;
    }

    return sibling?.dataset.cardId ?? null;
}

/**
 * The ids of the cards, in page order, that fall outside a longest run whose
 * manifest indexes rise. Only those cards moved, so only those need a fetch.
 */
function outOfOrder(cards) {
    const tails = [];
    const before = [];
    cards.forEach(({ index }, position) => {
        let low = 0;
        let high = tails.length;
        while (low < high) {
            const middle = Math.floor((low + high) / 2);
            if (cards[tails[middle]].index < index) {
                low = middle + 1;
            } else {
                high = middle;
            }
        }
        before[position] = low > 0 ? tails[low - 1] : -1;
        tails[low] = position;
    });
    const kept = new Set();
    for (
        let position = tails.at(-1) ?? -1;
        position !== -1;
        position = before[position]
    ) {
        kept.add(position);
    }

    return cards
        .filter((card, position) => !kept.has(position))
        .map(({ cardId }) => cardId);
}
