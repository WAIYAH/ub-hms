/* UB-HMS: small, optional enhancements. Every page works without JavaScript. */
(function () {
    'use strict';

    // Open and close the menu on small screens
    var toggle = document.querySelector('[data-toggle-menu]');
    var sidebar = document.getElementById('sidebar');
    if (toggle && sidebar) {
        toggle.addEventListener('click', function () {
            var open = sidebar.classList.toggle('open');
            toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        });
    }

    // Ask before a risky action: <button data-confirm="Are you sure?">
    document.addEventListener('click', function (event) {
        var el = event.target.closest('[data-confirm]');
        if (el && !window.confirm(el.getAttribute('data-confirm'))) {
            event.preventDefault();
        }
    });

    // Print button: <button data-print>
    document.addEventListener('click', function (event) {
        if (event.target.closest('[data-print]')) {
            window.print();
        }
    });

    // Disable a submit button after the first click so a form is not sent twice
    document.addEventListener('submit', function (event) {
        var form = event.target;
        if (!(form instanceof HTMLFormElement) || form.hasAttribute('data-allow-resubmit')) {
            return;
        }
        var buttons = form.querySelectorAll('button[type=submit]:not([data-keep])');
        setTimeout(function () {
            buttons.forEach(function (b) { b.disabled = true; });
        }, 0);
    });

    // Add another row to a repeating group (prescription lines):
    // <button type="button" data-add-row="#rows">  with rows marked  <div class="row-item hidden">
    document.addEventListener('click', function (event) {
        var btn = event.target.closest('[data-add-row]');
        if (!btn) { return; }
        var container = document.querySelector(btn.getAttribute('data-add-row'));
        if (!container) { return; }
        var next = container.querySelector('.row-item.hidden');
        if (next) {
            next.classList.remove('hidden');
            if (!container.querySelector('.row-item.hidden')) { btn.disabled = true; }
        }
    });

    // Show or hide the payment reference box depending on the method
    var method = document.getElementById('f_method');
    var refBox = document.getElementById('reference-box');
    if (method && refBox) {
        var sync = function () { refBox.classList.toggle('hidden', method.value === 'Cash'); };
        method.addEventListener('change', sync);
        sync();
    }
})();
