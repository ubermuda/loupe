import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static values = {
        collectionHolderId: String,
    };

    addRow(event) {
        event.preventDefault();
        const holder = document.getElementById(this.collectionHolderIdValue);
        const row = document.createElement('div');
        row.dataset.formCollectionRow = '';
        row.classList.add('flex', 'gap-2', 'items-center', 'self-stretch');

        const inputWrapper = document.createElement('div');
        inputWrapper.classList.add('grow');
        inputWrapper.innerHTML = holder.dataset.prototype.replace(
            /__name__/g,
            holder.dataset.index,
        );
        row.appendChild(inputWrapper);

        const removeBtn = document.createElement('button');
        removeBtn.type = 'button';
        removeBtn.className =
            'w-7 h-7 flex items-center justify-center rounded-lg text-slate-300 hover:text-slate-500 hover:bg-slate-100 transition-colors shrink-0 cursor-pointer';
        removeBtn.setAttribute('aria-label', 'Remove');
        removeBtn.innerHTML =
            '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>';
        removeBtn.addEventListener('click', () => row.remove());

        row.appendChild(removeBtn);
        holder.appendChild(row);
        holder.dataset.index++;
        row.getElementsByTagName('input')[0]?.focus();
    }

    removeRow(event) {
        event.preventDefault();
        event.target.closest('[data-form-collection-row]')?.remove();
    }
}
