<?php
/** Accessibility statement. Public. States the target, what has been done, what has been checked, and the known gaps. */
require __DIR__ . '/includes/bootstrap.php';

$toc = [['commitment', 'Our commitment'], ['done', 'What we have done'], ['checked', 'How we checked'], ['gaps', 'Known limits'], ['feedback', 'Tell us about a barrier']];
legal_start('accessibility', 'UB-HMS should work for everyone who needs it, including people who use a keyboard, a screen reader or a phone.', $toc);
?>
<h2 id="commitment">1. Our commitment</h2>
<p>We aim to meet the Web Content Accessibility Guidelines (WCAG) 2.1, level AA (World Wide Web Consortium, 2018) on this website and in UB-HMS. Hospital staff work under pressure and some have visual, hearing, movement or learning needs, so clear and forgiving screens matter.</p>

<h2 id="done">2. What we have done</h2>
<ul>
    <li><strong>Keyboard:</strong> every link, button and form can be reached and used with the keyboard. A visible outline shows where you are, and a &ldquo;Skip to content&rdquo; link is the first thing on every page.</li>
    <li><strong>Structure:</strong> pages have one main heading and a sensible order of sub-headings, landmarks for the header, menu, main content and footer, and a breadcrumb on inner pages.</li>
    <li><strong>Forms:</strong> every field has a visible label, errors are listed at the top and next to the field, and the fields are linked to their messages for screen readers. Optional fields say &ldquo;(optional)&rdquo; in words, so required fields are not marked by a star alone.</li>
    <li><strong>Colour and text:</strong> text colours were measured against the backgrounds and are above the 4.5 to 1 ratio. Statuses use words as well as colour. Text can be enlarged without losing content.</li>
    <li><strong>Pictures and icons:</strong> photographs have a text description; icons that only decorate are hidden from screen readers.</li>
    <li><strong>Movement:</strong> the animations (scrolling effects, floating cards, the heartbeat line) stop completely if your device is set to reduce motion. Nothing flashes.</li>
    <li><strong>Small screens:</strong> the layout adapts to phones and tablets, and the menu folds into a button.</li>
</ul>

<h2 id="checked">3. How we checked</h2>
<p>The public pages were checked with an automated accessibility tool (axe-core) and by keyboard, and text contrast was measured on every screen size. Automated tools find only part of the problems, so the checks below are still to be done.</p>

<h2 id="gaps">4. Known limits</h2>
<ul>
    <li>UB-HMS has <strong>not yet been tested with screen readers</strong> or by people who use assistive technology. This is recorded as a known issue, and testing against the WCAG 2.1 checklist is planned.</li>
    <li>Some staff screens (for example the audit trail and stock reports) are wide tables that need sideways scrolling on a phone.</li>
    <li>The system is in English only. Kiswahili screens are a planned improvement.</li>
</ul>

<h2 id="feedback">5. Tell us about a barrier</h2>
<p>If something on this website or in UB-HMS is hard for you to use, please tell us what happened and what you were using, through the <a href="<?= e(url('contact.php', ['topic' => 'Feedback or complaint']) . '#form') ?>">contact form</a>. We will try to fix it, or to give you the information in another way.</p>
<?php legal_end();
