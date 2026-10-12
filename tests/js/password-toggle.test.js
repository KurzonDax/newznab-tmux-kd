import assert from 'node:assert/strict';
import test from 'node:test';
import { passwordToggle } from '../../resources/js/alpine/components/password-toggle-component.js';

function mount() {
    const component = passwordToggle();
    component.$refs = { field: { type: 'password' } };
    return component;
}

test('the eye shows the password, then hides it again', () => {
    const eye = mount();

    eye.toggle();
    assert.equal(eye.$refs.field.type, 'text');

    eye.toggle();
    assert.equal(eye.$refs.field.type, 'password');
});

test('the icon is a struck-through eye only while the password is shown', () => {
    const eye = mount();
    assert.equal(eye.iconClass(), 'fa-eye');

    eye.toggle();
    assert.equal(eye.iconClass(), 'fa-eye-slash');

    eye.toggle();
    assert.equal(eye.iconClass(), 'fa-eye');
});
