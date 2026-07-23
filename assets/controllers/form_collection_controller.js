import { Controller } from '@hotwired/stimulus';

/*
 * The controller root must contain a server-rendered template for the
 * remove-row button, so the icon stays on UX Icons and the label stays
 * translated (never build icon SVG or user-facing strings in JS):
 *
 *   <template data-form-collection-target="removeButtonTemplate">
 *       <button type="button" class="..." aria-label="{{ 'app.form.collection.remove'|trans }}">
 *           <twig:UX:Icon name="lucide:x" class="w-4 h-4" />
 *       </button>
 *   </template>
 */
export default class extends Controller {
    static targets = ['removeButtonTemplate'];
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

        const removeButton =
            this.removeButtonTemplateTarget.content.firstElementChild.cloneNode(
                true,
            );
        removeButton.addEventListener('click', () => row.remove());

        row.appendChild(removeButton);
        holder.appendChild(row);
        holder.dataset.index++;
        row.getElementsByTagName('input')[0]?.focus();
    }

    removeRow(event) {
        event.preventDefault();
        event.target.closest('[data-form-collection-row]')?.remove();
    }
}
