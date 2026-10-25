/* UB-HMS application shell: small optional enhancements. Every page works without JavaScript.
   (The menu button itself is handled in app.js; this file adds the rest.) */
(function () {
    'use strict';

    var body = document.body;
    var sidebar = document.getElementById('sidebar');
    var store = {
        get: function (k) { try { return window.localStorage.getItem('ubhms.' + k); } catch (e) { return null; } },
        set: function (k, v) { try { window.localStorage.setItem('ubhms.' + k, v); } catch (e) { /* private window: the choice just is not remembered */ } }
    };
    var wide = window.matchMedia ? window.matchMedia('(min-width: 861px)') : { matches: true };

    // ---- Saved display choices: a shrunk menu and compact tables ----
    function applyPref(name, on) {
        if (name === 'sb-collapsed') { body.classList.toggle('sb-collapsed', on); syncCollapseButton(); }
        if (name === 'dense') { body.classList.toggle('dense', on); }
    }
    var collapseBtn = document.querySelector('[data-collapse]');
    function syncCollapseButton() {
        if (!collapseBtn) { return; }
        var on = body.classList.contains('sb-collapsed');
        collapseBtn.setAttribute('aria-pressed', on ? 'true' : 'false');
        collapseBtn.setAttribute('aria-label', on ? 'Widen the menu' : 'Shrink the menu to icons');
    }
    ['sb-collapsed', 'dense'].forEach(function (n) { applyPref(n, store.get(n) === '1'); });

    if (collapseBtn) {
        collapseBtn.hidden = false;
        collapseBtn.addEventListener('click', function () {
            var on = !body.classList.contains('sb-collapsed');
            applyPref('sb-collapsed', on);
            store.set('sb-collapsed', on ? '1' : '0');
        });
    }

    // The Settings page shows the choices as checkboxes
    var prefs = document.querySelector('[data-prefs]');
    if (prefs) {
        prefs.hidden = false;
        Array.prototype.forEach.call(prefs.querySelectorAll('[data-pref]'), function (box) {
            var name = box.getAttribute('data-pref');
            box.checked = store.get(name) === '1';
            box.addEventListener('change', function () {
                applyPref(name, box.checked);
                store.set(name, box.checked ? '1' : '0');
            });
        });
    }

    // ---- Dropdowns: only one open at a time; close on an outside click or Escape ----
    var menus = Array.prototype.slice.call(document.querySelectorAll('.app-top details.dd'));
    menus.forEach(function (d) {
        d.addEventListener('toggle', function () {
            if (d.open) { menus.forEach(function (o) { if (o !== d) { o.open = false; } }); }
        });
    });
    document.addEventListener('click', function (e) {
        menus.forEach(function (d) { if (d.open && !d.contains(e.target)) { d.open = false; } });
    });
    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape') { return; }
        menus.forEach(function (d) {
            if (d.open) {
                d.open = false;
                var s = d.querySelector('summary');
                if (s && d.contains(document.activeElement)) { s.focus(); }
            }
        });
        closeDrawer();
    });

    // ---- Phone menu: the overlay and links close the drawer, so the page behind is usable again ----
    function closeDrawer() {
        if (sidebar && sidebar.classList.contains('open')) {
            sidebar.classList.remove('open');
            var t = document.querySelector('[data-toggle-menu]');
            if (t) { t.setAttribute('aria-expanded', 'false'); }
        }
    }
    var overlay = document.querySelector('.sb-overlay');
    if (overlay) { overlay.addEventListener('click', closeDrawer); }
    if (sidebar) {
        sidebar.addEventListener('click', function (e) {
            if (e.target.closest('a') && !wide.matches) { closeDrawer(); }
        });
    }
    if (wide.addEventListener) { wide.addEventListener('change', closeDrawer); }

    // ---- Press "/" to jump to the patient search (not while typing in a field) ----
    var search = document.querySelector('.top-search input');
    document.addEventListener('keydown', function (e) {
        if (e.key === '/' && search && !/^(INPUT|TEXTAREA|SELECT)$/.test(document.activeElement.tagName) && !document.activeElement.isContentEditable) {
            e.preventDefault();
            search.focus();
        }
    });
})();
