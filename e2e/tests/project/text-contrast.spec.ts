import { expect, type Locator } from '@playwright/test';
import { createTest } from '../fixtures';

const test = createTest({
    email: 'e2e-text-contrast@example.com',
    password: 'e2e_password_123',
});

async function contrast(locator: Locator): Promise<number> {
    return locator.evaluate((element) => {
        const canvas = document.createElement('canvas');
        canvas.width = canvas.height = 1;
        const context = canvas.getContext('2d', { willReadFrequently: true });
        if (!context)
            throw new Error(
                'A canvas context is required to resolve CSS colors.',
            );
        function color(value: string): number[] {
            context!.clearRect(0, 0, 1, 1);
            context!.fillStyle = value;
            context!.fillRect(0, 0, 1, 1);
            return Array.from(context!.getImageData(0, 0, 1, 1).data).map(
                (channel, index) => (index === 3 ? channel / 255 : channel),
            );
        }
        function blend(front: number[], back: number[]): number[] {
            return front
                .slice(0, 3)
                .map(
                    (channel, index) =>
                        channel * front[3] + back[index] * (1 - front[3]),
                )
                .concat(1);
        }
        function luminance(channels: number[]): number {
            return channels
                .slice(0, 3)
                .map((channel) => channel / 255)
                .map((channel) =>
                    channel <= 0.04045
                        ? channel / 12.92
                        : ((channel + 0.055) / 1.055) ** 2.4,
                )
                .reduce(
                    (sum, channel, index) =>
                        sum + channel * [0.2126, 0.7152, 0.0722][index],
                    0,
                );
        }
        const ancestors: CSSStyleDeclaration[] = [];
        for (
            let ancestor: Element | null = element;
            ancestor;
            ancestor = ancestor.parentElement
        )
            ancestors.unshift(getComputedStyle(ancestor));
        let background = [255, 255, 255, 1];
        for (const style of ancestors) {
            if (
                style.backgroundImage !== 'none' ||
                Number(style.opacity) !== 1 ||
                style.mixBlendMode !== 'normal'
            )
                throw new Error(
                    'This contrast check requires flat backgrounds and opaque elements.',
                );
            background = blend(color(style.backgroundColor), background);
        }
        const foreground = blend(
            color(getComputedStyle(element).color),
            background,
        );
        const values = [luminance(foreground), luminance(background)].sort(
            (left, right) => left - right,
        );
        return (values[1] + 0.05) / (values[0] + 0.05);
    });
}

test('contrast measurement distinguishes black from white and identical colors', async ({
    page,
}) => {
    await page.setContent(
        '<style>body { background: white; } p { color: black; }</style><p>Contrast sample</p>',
    );
    const sample = page.getByText('Contrast sample', { exact: true });
    expect(await contrast(sample)).toBe(21);
    await sample.evaluate((element) => {
        element.style.color = 'white';
    });
    expect(await contrast(sample)).toBe(1);
});

test('secondary text and active navigation counts remain legible on their rendered backgrounds', async ({
    page,
}) => {
    const seeded = await page.request.post('/dev/seed/document', {
        form: {
            title: 'Readable document',
            markdown: '# Contrast\n\nReadable document content.',
        },
    });
    expect(seeded.status()).toBe(201);
    const { projectId } = await seeded.json();
    await page.goto(`/projects/${projectId}/documents`);
    for (const selector of [
        '.lp-workspace-desc',
        '.lp-topbar__crumb',
        '.lp-topbar__sep',
        '.lp-filter-count',
        '.lp-sidebar__link--active .lp-sidebar__pill',
    ]) {
        const text = page.locator(selector);
        await expect(text).toBeVisible();
        expect.soft(await contrast(text), selector).toBeGreaterThanOrEqual(4.5);
    }
    await page.goto(`/projects/${projectId}`);
    const descriptions = page.locator('.lp-workshop-stat__copy');
    expect(await descriptions.count()).toBeGreaterThan(0);
    for (const description of await descriptions.all()) {
        await expect(description).toBeVisible();
        expect
            .soft(
                await contrast(description),
                (await description.textContent()) ?? '',
            )
            .toBeGreaterThanOrEqual(4.5);
    }
});
