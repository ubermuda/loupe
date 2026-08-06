import { Controller } from '@hotwired/stimulus';

/** Matches the users.full_name column. */
const MAX_LENGTH = 150;

/**
 * Derives a display name from an email address.
 *
 * These rules are duplicated in src/Module/Account/Service/DisplayNameDeriver.php,
 * which produces the same value for the paths that have no form to ask on.
 * Change one and you must change the other.
 */
function deriveDisplayName(email) {
    const localPart = email.split('@')[0];

    // A `+tag` suffix addresses a mailbox, it is not part of anyone's name.
    const withoutTag = localPart.split('+')[0];

    const spaced = withoutTag.replace(/[._]/g, ' ').replace(/\s+/g, ' ').trim();

    const derived =
        spaced !== '' ? capitalize(spaced.toLowerCase()) : localPart;

    // Array.from splits by code point, matching PHP's mb_substr — slice would
    // count UTF-16 units and could cut a surrogate pair in half.
    return Array.from(derived !== '' ? derived : email)
        .slice(0, MAX_LENGTH)
        .join('');
}

/** Capitalizes each space-separated word and each hyphen-separated part of it. */
function capitalize(value) {
    return value
        .split(' ')
        .map((word) => word.split('-').map(upperFirst).join('-'))
        .join(' ');
}

function upperFirst(value) {
    const characters = Array.from(value);

    return characters.length === 0
        ? value
        : characters[0].toUpperCase() + characters.slice(1).join('');
}

/**
 * Fills the display-name field from the email as it is typed, until the person
 * types a name of their own. Clearing that name re-arms the suggestion.
 *
 * Usage:
 *   <form data-controller="display-name-suggestion">
 *     <input data-display-name-suggestion-target="email"
 *            data-action="input->display-name-suggestion#suggest">
 *     <input data-display-name-suggestion-target="displayName"
 *            data-action="input->display-name-suggestion#recordEdit change->display-name-suggestion#recordEdit">
 *   </form>
 *
 * The `change` action is what catches a password manager that assigns .value
 * and dispatches no input event.
 */
export default class extends Controller {
    static targets = ['email', 'displayName'];

    connect() {
        // A re-rendered invalid submission arrives with the name already
        // filled; that value is the person's and must survive.
        this.recordEdit();
    }

    suggest() {
        if (this.editedByHand) {
            return;
        }

        this.displayNameTarget.value = deriveDisplayName(
            this.emailTarget.value,
        );
    }

    recordEdit() {
        // Stimulus target getters throw when the element is absent, and connect()
        // runs on any form that declares the controller.
        if (!this.hasDisplayNameTarget) {
            return;
        }

        this.editedByHand = this.displayNameTarget.value !== '';
    }
}
