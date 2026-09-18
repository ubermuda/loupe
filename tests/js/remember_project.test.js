/** @vitest-environment jsdom */
import { Application } from '@hotwired/stimulus';
import { afterEach, beforeEach, expect, it } from 'vitest';
import RememberProjectController from '../../assets/controllers/remember_project_controller.js';

let application;
const settle = () => new Promise((resolve) => setTimeout(resolve, 0));

beforeEach(() => {
    document.cookie = 'loupe_project=; max-age=0; path=/';
    application = Application.start();
    application.register('remember-project', RememberProjectController);
});

afterEach(() => {
    application.stop();
    document.body.innerHTML = '';
});

it('records the project once the page renders', async () => {
    expect(document.cookie).not.toContain('loupe_project=');
    document.body.innerHTML =
        '<div hidden data-controller="remember-project" data-remember-project-id-value="project-b"></div>';
    await settle();
    expect(document.cookie).toContain('loupe_project=project-b');
});

it('records nothing on a page that shows no project', async () => {
    document.body.innerHTML = '<main></main>';
    await settle();
    expect(document.cookie).not.toContain('loupe_project=');
});
