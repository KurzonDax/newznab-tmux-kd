import assert from 'node:assert/strict';
import test from 'node:test';
import { adminInvitations } from '../../resources/js/alpine/components/admin/invitations-component.js';

/** The invitations list: the head box and one box per row, found by the selectors the view uses. */
function mountList(rowCount = 3) {
    const head = { checked: false, indeterminate: false };
    const rows = Array.from({ length: rowCount }, () => ({ checked: false }));
    const root = {
        querySelector: selector => (selector === '#select_all' ? head : null),
        querySelectorAll: selector => (selector === '.invitation-checkbox' ? rows : []),
    };

    const component = adminInvitations();
    component.$el = root;
    component.init();

    return {
        component,
        head,
        rows,
        /** Tick or untick a box the way a click does, then fire its change handler. */
        clickHead(checked) { head.checked = checked; head.indeterminate = false; component.toggleAll(); },
        clickRow(index, checked) { rows[index].checked = checked; component.syncHead(); },
    };
}

test('checking the head box checks every row and unchecking it clears them', () => {
    const list = mountList();

    list.clickHead(true);
    assert.deepEqual(list.rows.map(row => row.checked), [true, true, true]);

    list.clickHead(false);
    assert.deepEqual(list.rows.map(row => row.checked), [false, false, false]);
});

test('with only some rows checked the head box is indeterminate and unchecked', () => {
    const list = mountList();

    list.clickRow(1, true);

    assert.equal(list.head.checked, false);
    assert.equal(list.head.indeterminate, true);
});

test('with every row checked the head box is checked and not indeterminate', () => {
    const list = mountList();

    list.clickRow(0, true);
    list.clickRow(1, true);
    list.clickRow(2, true);

    assert.equal(list.head.checked, true);
    assert.equal(list.head.indeterminate, false);
});

test('clearing the last checked row leaves the head box unchecked and not indeterminate', () => {
    const list = mountList(2);
    list.clickHead(true);

    list.clickRow(0, false);
    assert.equal(list.head.checked, false);
    assert.equal(list.head.indeterminate, true);

    list.clickRow(1, false);
    assert.equal(list.head.checked, false);
    assert.equal(list.head.indeterminate, false);
});

test('init assigns only properties the component declares', () => {
    const declared = Object.keys(adminInvitations());
    const list = mountList();

    assert.deepEqual(Object.keys(list.component).filter(key => key !== '$el' && ! declared.includes(key)), []);
});
