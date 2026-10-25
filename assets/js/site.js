/* UB-HMS public website: small enhancements. Every page works without JavaScript. */
(function () {
    'use strict';

    var doc = document.documentElement;
    var body = document.body;
    var header = document.querySelector('.hd');
    var supports = function (rule) { return !!(window.CSS && CSS.supports && CSS.supports(rule)); };
    var calm = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    // Header gets darker and the back-to-top button appears once the page is scrolled.
    // Browsers with scroll-driven animation do this in the stylesheet; the rest use these two classes.
    if (!supports('animation-timeline: scroll()')) {
        doc.classList.add('js-top');
    }
    var ticking = false;
    function onScroll() {
        var y = window.pageYOffset || doc.scrollTop || 0;
        if (header) { header.classList.toggle('is-scrolled', y > 24); }
        doc.classList.toggle('is-away', y > 500);
        ticking = false;
    }
    window.addEventListener('scroll', function () {
        if (!ticking) { ticking = true; window.requestAnimationFrame(onScroll); }
    }, { passive: true });
    onScroll();

    // Mobile menu: lock the page behind it, close with Escape or after choosing a link
    var toggle = document.getElementById('nav-toggle');
    if (toggle) {
        var setOpen = function (open) {
            toggle.checked = open;
            body.classList.toggle('nav-open', open);
            toggle.setAttribute('aria-label', open ? 'Close the menu' : 'Open the menu');
        };
        toggle.addEventListener('change', function () { setOpen(toggle.checked); });
        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && toggle.checked) { setOpen(false); toggle.focus(); }
        });
        Array.prototype.forEach.call(document.querySelectorAll('.hd-nav a'), function (link) {
            link.addEventListener('click', function () { setOpen(false); });
        });
        window.addEventListener('resize', function () { if (window.innerWidth > 1080 && toggle.checked) { setOpen(false); } });
    }

    // Scroll-in effect for browsers that cannot do it in CSS (it is skipped for people who ask for less movement)
    if (!calm && !supports('animation-timeline: view()') && 'IntersectionObserver' in window) {
        doc.classList.add('js-reveal');
        var seen = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (entry.isIntersecting) { entry.target.classList.add('in'); seen.unobserve(entry.target); }
            });
        }, { threshold: 0.12, rootMargin: '0px 0px -40px 0px' });
        Array.prototype.forEach.call(document.querySelectorAll('.reveal'), function (el) { seen.observe(el); });
    }

    // Live character counter under a long text box: <p class="fx-count" data-for="f_message" data-max="2000" hidden>
    Array.prototype.forEach.call(document.querySelectorAll('.fx-count[data-for]'), function (note) {
        var box = document.getElementById(note.getAttribute('data-for'));
        var max = parseInt(note.getAttribute('data-max'), 10) || 0;
        if (!box || !max) { return; }
        var update = function () { note.textContent = box.value.length + ' of ' + max + ' characters'; };
        note.hidden = false;
        box.addEventListener('input', update);
        update();
    });
})();
