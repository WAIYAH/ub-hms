<?php
/**
 * The public website shell: the header and footer shared by the welcome, services, about, contact,
 * request-access and legal pages, plus the small building blocks those pages use.
 *
 * Nothing here reads patient data. The pages work without JavaScript; assets/js/site.js only adds polish.
 */
declare(strict_types=1);

/** Contact details for the public pages. They come from config.php and are placeholders until the hospital sets its own. */
function site_contact(): array
{
    $c = [
        'address'       => (string) config('site.address', ''),
        'phone'         => (string) config('site.phone', ''),
        'email'         => (string) config('site.email', ''),
        'privacy_email' => (string) config('site.privacy_email', ''),
        'hours'         => site_hours(),
    ];
    $c['tel'] = preg_replace('/[^+\d]/', '', $c['phone']) ?? '';
    $c['placeholder'] = (bool) preg_match('/\.example$/i', $c['email']);
    return $c;
}

/** Opening hours as a sentence, from the clinic settings (for example "Monday to Saturday, 8:00 am to 5:00 pm"). */
function site_hours(): string
{
    $names = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'];
    $days = array_values(array_filter((array) config('clinic.days', [1, 2, 3, 4, 5, 6]), static fn ($d) => isset($names[$d])));
    sort($days);
    $label = !$days ? '' : (count($days) === 1 ? $names[$days[0]] : $names[$days[0]] . ' to ' . $names[$days[count($days) - 1]]);
    $fmt = static function (string $t): string {
        $d = DateTimeImmutable::createFromFormat('H:i', $t);
        return $d ? strtolower($d->format('g:i a')) : $t;
    };
    return trim($label . ', ' . $fmt((string) config('clinic.open', '08:00')) . ' to ' . $fmt((string) config('clinic.close', '17:00')), ', ');
}

/** The six modules: slug => [icon, title, short line]. Used by the menu, the footer and the pages. */
function site_modules(): array
{
    return [
        'registration' => ['user-plus', 'Registration and appointments', 'Register once and book without clashes'],
        'consultation' => ['stethoscope', 'Clinical consultation', 'Triage, diagnosis, tests and prescriptions'],
        'laboratory'   => ['flask', 'Laboratory', 'Requests go out and results come back'],
        'pharmacy'     => ['pill', 'Pharmacy and stock', 'Batch stock and expiry alerts'],
        'billing'      => ['receipt', 'Billing and reports', 'Invoices, payments and management reports'],
        'security'     => ['shield', 'Users and security', 'Eight roles, lockout and an audit trail'],
    ];
}

/** The legal pages: slug => [file, title, icon, one line]. */
function site_legal_pages(): array
{
    return [
        'privacy'       => ['privacy.php', 'Privacy policy', 'lock', 'What information is kept, why, and your rights'],
        'terms'         => ['terms.php', 'Terms of use', 'file-text', 'The rules for using UB-HMS'],
        'cookies'       => ['cookies.php', 'Cookie notice', 'cookie', 'The one cookie the system sets'],
        'accessibility' => ['accessibility.php', 'Accessibility statement', 'accessibility', 'Our commitment and what is still to do'],
    ];
}

/** Photographs used on the public pages: file => [photographer, Pexels photo number]. All are free to use under the Pexels licence. */
function photo_credits(): array
{
    return [
        'Hero background (hands on a tablet)' => ['Tima Miroshnichenko', 6011598],
        'Nurse with a stethoscope'            => ['Tessy Agbonome', 19963130],
        'Nurse with a tablet'                 => ['Laura James', 6098051],
        'Smiling nurse'                       => ['Carmel Nsenga', 18844981],
        'Doctor in a white coat'              => ['Martins John', 19596247],
        'Laboratory work'                     => ['RF._.studio', 3825434],
        'Scientist at a microscope'           => ['cottonbro studio', 6208934],
        'Pharmacy shelves'                    => ['Ninthgrid', 30678215],
        'Reception desk'                      => ['Cedric Fauntleroy', 4269203],
        'Nairobi skyline'            => ['Justin Brian', 9833512],
        'Aerial view of Nairobi'              => ['Ken Mwaura', 28977077],
    ];
}

/** A link in the main menu, marked as the current page when it is. */
function site_nav_link(string $href, string $label, string $key, string $active, string $class = 'hd-link'): string
{
    $current = $key === $active;
    return '<a class="' . e($class) . ($current ? ' is-current' : '') . '" href="' . e(url($href)) . '"' . ($current ? ' aria-current="page"' : '') . '>' . e($label) . '</a>';
}

/**
 * Start a public page: document head, skip link, header and the opening of <main>.
 * $active is the menu item to mark ("home", "services", "about", "contact", "legal", "register").
 */
function site_start(string $title, string $active = '', array $o = []): void
{
    if (!headers_sent()) {
        header('Content-Type: text/html; charset=utf-8');
    }
    $short = (string) config('short_name');
    $desc = $o['description'] ?? config('app_name') . ' (' . $short . ') keeps patient, clinical, laboratory, pharmacy and billing information in one secure record for ' . config('hospital') . ', ' . config('location') . '.';
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<meta name="theme-color" content="#07423d">';
    echo '<meta name="description" content="' . e((string) $desc) . '">';
    echo '<title>' . e($title) . ' | ' . e($short) . '</title>';
    echo '<link rel="icon" href="' . e(asset('img/favicon.svg')) . '" type="image/svg+xml">';
    echo '<link rel="stylesheet" href="' . e(asset('css/app.css')) . '">';
    echo '<link rel="stylesheet" href="' . e(asset('css/site.css')) . '">';
    echo '<script src="' . e(asset('js/app.js')) . '" defer></script>';
    echo '<script src="' . e(asset('js/site.js')) . '" defer></script>';
    echo '</head><body class="site' . (isset($o['body_class']) ? ' ' . e($o['body_class']) : '') . '" id="top">';
    echo '<a class="skip" href="#main">Skip to content</a>';
    echo site_header($active);
    echo '<main id="main" tabindex="-1">';
}

function site_end(): void
{
    echo '</main>' . site_footer() . '</body></html>';
}

/** Utility bar, then the main header with the services menu, the legal menu and the sign-in buttons. */
function site_header(string $active): string
{
    $c = site_contact();
    $home = url('welcome.php');
    $h = '<div class="hd-util" role="region" aria-label="Contact details and emergency advice"><div class="wrap hd-util-in">';
    $h .= '<ul class="hd-contact">';
    $h .= '<li>' . icon('phone') . '<a href="tel:' . e($c['tel']) . '">' . e($c['phone']) . '</a></li>';
    $h .= '<li>' . icon('mail') . '<a href="mailto:' . e($c['email']) . '">' . e($c['email']) . '</a></li>';
    $h .= '<li class="hd-hours">' . icon('clock') . '<span>' . e($c['hours']) . '</span></li>';
    $h .= '</ul>';
    $h .= '<p class="hd-alert">' . icon('alert') . '<span>Emergency? Go to the nearest emergency department or call <strong>999</strong> or <strong>112</strong>.</span></p>';
    $h .= '</div></div>';

    $h .= '<header class="hd"><div class="wrap hd-bar">';
    $h .= '<a class="hd-brand" href="' . e($home) . '"><span class="brand-mark" aria-hidden="true">+</span>'
        . '<span class="hd-name"><strong>' . e(config('short_name')) . '</strong><small>' . e(config('hospital')) . '</small></span></a>';
    $h .= '<input type="checkbox" id="nav-toggle" class="hd-check" aria-label="Open the menu">';
    $h .= '<label for="nav-toggle" class="hd-burger" aria-hidden="true"><i></i><i></i><i></i></label>';
    $h .= '<nav class="hd-nav" aria-label="Main"><ul class="hd-list">';
    $h .= '<li>' . site_nav_link('welcome.php', 'Home', 'home', $active) . '</li>';

    $h .= '<li class="hd-has-menu">' . site_nav_link('services.php', 'Services', 'services', $active) . '<span class="hd-caret">' . icon('chevron-down') . '</span>';
    $h .= '<div class="hd-menu hd-menu-wide"><ul class="hd-menu-grid">';
    foreach (site_modules() as $slug => [$ic, $name, $line]) {
        $h .= '<li><a href="' . e(url('services.php') . '#' . $slug) . '"><span class="hd-ic">' . icon($ic) . '</span><span><strong>' . e($name) . '</strong><small>' . e($line) . '</small></span></a></li>';
    }
    $h .= '</ul><a class="hd-menu-foot" href="' . e(url('services.php')) . '">See all services' . icon('arrow-right') . '</a></div></li>';

    $h .= '<li>' . site_nav_link('about.php', 'About', 'about', $active) . '</li>';
    $h .= '<li>' . site_nav_link('contact.php', 'Contact', 'contact', $active) . '</li>';

    $h .= '<li class="hd-has-menu">' . site_nav_link('privacy.php', 'Legal', 'legal', $active) . '<span class="hd-caret">' . icon('chevron-down') . '</span>';
    $h .= '<div class="hd-menu"><ul class="hd-menu-list">';
    foreach (site_legal_pages() as [$file, $name, $ic, $line]) {
        $h .= '<li><a href="' . e(url($file)) . '"><span class="hd-ic">' . icon($ic) . '</span><span><strong>' . e($name) . '</strong><small>' . e($line) . '</small></span></a></li>';
    }
    $h .= '</ul></div></li>';
    $h .= '</ul>';

    $h .= '<div class="hd-actions">';
    $h .= '<a class="btn btn-ghost btn-sm" href="' . e(url('login.php')) . '">Sign in</a>';
    $h .= '<a class="btn btn-accent btn-sm' . ($active === 'register' ? ' is-current' : '') . '" href="' . e(url('register.php')) . '"' . ($active === 'register' ? ' aria-current="page"' : '') . '>Request access' . icon('arrow-right') . '</a>';
    $h .= '</div></nav></div></header>';
    return $h;
}

/** The comprehensive footer. */
function site_footer(): string
{
    $c = site_contact();
    $year = app_now()->format('Y');
    $f = '<footer class="ft"><div class="ft-aurora" aria-hidden="true"></div>';

    $f .= '<div class="wrap ft-cta"><div class="ft-cta-card glass"><div><h2>Ready to start your shift?</h2><p>Sign in with your own account, or ask the administrator for one.</p></div>'
        . '<div class="ft-cta-actions"><a class="btn btn-accent" href="' . e(url('login.php')) . '">Staff sign in' . icon('arrow-right') . '</a>'
        . '<a class="btn btn-ghost" href="' . e(url('register.php')) . '">Request access</a></div></div></div>';

    $f .= '<div class="wrap ft-main">';
    $f .= '<div class="ft-brand"><a class="hd-brand" href="' . e(url('welcome.php')) . '"><span class="brand-mark" aria-hidden="true">+</span>'
        . '<span class="hd-name"><strong>' . e(config('short_name')) . '</strong><small>' . e(config('hospital')) . '</small></span></a>'
        . '<p>One secure record for reception, clinic, laboratory, pharmacy and billing, built for ' . e(config('hospital')) . ' in ' . e(config('location')) . '.</p>'
        . '<ul class="ft-contact">'
        . '<li>' . icon('map-pin') . '<span>' . e($c['address']) . '</span></li>'
        . '<li>' . icon('phone') . '<a href="tel:' . e($c['tel']) . '">' . e($c['phone']) . '</a></li>'
        . '<li>' . icon('mail') . '<a href="mailto:' . e($c['email']) . '">' . e($c['email']) . '</a></li>'
        . '<li>' . icon('clock') . '<span>' . e($c['hours']) . '</span></li></ul></div>';

    $f .= '<nav class="ft-col" aria-label="Explore"><h3>Explore</h3><ul>'
        . '<li><a href="' . e(url('welcome.php')) . '">Home</a></li><li><a href="' . e(url('services.php')) . '">Services</a></li>'
        . '<li><a href="' . e(url('about.php')) . '">About UB-HMS</a></li><li><a href="' . e(url('contact.php')) . '">Contact us</a></li>'
        . '<li><a href="' . e(url('about.php')) . '#faq">Frequently asked questions</a></li></ul></nav>';

    $f .= '<nav class="ft-col" aria-label="Services"><h3>Services</h3><ul>';
    foreach (site_modules() as $slug => [, $name]) {
        $f .= '<li><a href="' . e(url('services.php') . '#' . $slug) . '">' . e($name) . '</a></li>';
    }
    $f .= '</ul></nav>';

    $f .= '<nav class="ft-col" aria-label="For staff"><h3>For staff</h3><ul>'
        . '<li><a href="' . e(url('login.php')) . '">Staff sign in</a></li><li><a href="' . e(url('register.php')) . '">Request access</a></li>'
        . '<li><a href="' . e(url('contact.php', ['topic' => 'Account or password help']) . '#form') . '">Forgot your password?</a></li>'
        . '<li><a href="' . e(url('contact.php', ['topic' => 'Technical support']) . '#form') . '">Report a problem</a></li></ul></nav>';

    $f .= '<nav class="ft-col" aria-label="Legal"><h3>Legal</h3><ul>';
    foreach (site_legal_pages() as [$file, $name]) {
        $f .= '<li><a href="' . e(url($file)) . '">' . e($name) . '</a></li>';
    }
    $f .= '<li><a href="' . e(url('contact.php', ['topic' => 'Data protection request']) . '#form') . '">Data protection requests</a></li></ul></nav>';
    $f .= '</div>';

    $f .= '<div class="ft-base"><div class="wrap ft-base-in">'
        . '<p>&copy; ' . e($year) . ' ' . e(config('hospital')) . '. All rights reserved. ' . e(config('short_name')) . ' is a KCA University diploma project.</p>'
        . '<ul class="ft-badges"><li>' . icon('badge-check') . 'Designed with the Data Protection Act, 2019 in mind</li><li>' . icon('accessibility') . 'WCAG 2.1 AA target</li>'
        . '<li><a href="' . e(url('about.php')) . '#credits">Image credits</a></li></ul>'
        . '</div></div>';
    $f .= '<a class="to-top" href="#top" aria-label="Back to the top">' . icon('arrow-up') . '</a>';
    return $f . '</footer>';
}

/**
 * The page banner under the header. $variant picks the background in the stylesheet
 * (about, services, contact, register, legal). $crumbs is a list of [label, href|null].
 */
function site_banner(string $variant, string $kicker, string $title, string $lead, array $crumbs = []): string
{
    $b = '<section class="bn bn-' . e($variant) . '"><span class="bn-orb bn-orb-1" aria-hidden="true"></span><span class="bn-orb bn-orb-2" aria-hidden="true"></span><div class="wrap bn-in">';
    if ($crumbs) {
        $b .= '<nav class="crumbs" aria-label="Breadcrumb"><ol>';
        $last = count($crumbs) - 1;
        foreach ($crumbs as $i => [$label, $href]) {
            $b .= '<li>' . ($i === $last || $href === null ? '<span aria-current="page">' . e($label) . '</span>' : '<a href="' . e(url($href)) . '">' . e($label) . '</a>') . '</li>';
        }
        $b .= '</ol></nav>';
    }
    return $b . '<p class="eyebrow">' . e($kicker) . '</p><h1>' . e($title) . '</h1><p class="lead">' . e($lead) . '</p></div></section>';
}

/** A call-to-action band. $primary and $secondary are [label, href] (secondary may be null). */
function site_cta(string $heading, string $text, array $primary, ?array $secondary = null): string
{
    $c = '<section class="cta"><div class="wrap"><div class="cta-card"><div><h2>' . e($heading) . '</h2><p>' . e($text) . '</p></div><div class="cta-actions">'
        . '<a class="btn btn-accent btn-xl" href="' . e(url($primary[1])) . '">' . e($primary[0]) . icon('arrow-right') . '</a>';
    if ($secondary) {
        $c .= '<a class="btn btn-ghost btn-xl" href="' . e(url($secondary[1])) . '">' . e($secondary[0]) . '</a>';
    }
    return $c . '</div></div></div></section>';
}

/**
 * Start a legal page: banner, then a sticky contents list beside the text.
 * $toc is a list of [id, heading] for the sections the page will print with legal_section().
 */
function legal_start(string $slug, string $lead, array $toc): void
{
    $pages = site_legal_pages();
    $title = $pages[$slug][1];
    site_start($title, 'legal');
    echo site_banner('legal', 'Legal', $title, $lead, [['Home', 'welcome.php'], ['Legal', null], [$title, null]]);
    echo '<section class="sec"><div class="wrap legal-grid"><aside class="legal-side"><nav class="toc glass-light" aria-label="On this page"><h2>On this page</h2><ol>';
    foreach ($toc as [$id, $heading]) {
        echo '<li><a href="#' . e($id) . '">' . e($heading) . '</a></li>';
    }
    echo '</ol><hr><h2>Other legal pages</h2><ul>';
    foreach ($pages as $s => [$file, $name]) {
        if ($s !== $slug) {
            echo '<li><a href="' . e(url($file)) . '">' . e($name) . '</a></li>';
        }
    }
    echo '</ul></nav></aside><article class="prose">';
    echo '<p class="legal-meta">Last updated ' . e((string) config('site.legal_updated', '')) . '</p>';
}

function legal_end(): void
{
    echo '</article></div></section>';
    echo site_cta('Questions about this page?', 'Write to us and the administrator will reply.', ['Contact us', 'contact.php'], null);
    site_end();
}

/* ---------- Public forms ---------- */

/**
 * One labelled field of a public form, with an icon, hint, character counter and error message.
 * $f = ['values' => [], 'errors' => []]. $o: type (text|email|tel|select|textarea), icon, required, hint, maxlength,
 * placeholder, autocomplete, options (value => label, for select), empty (first option text), rows, count (max, for a live counter).
 */
function fx_field(array $f, string $name, string $label, array $o = []): string
{
    $type = $o['type'] ?? 'text';
    $id = 'f_' . $name;
    $value = (string) ($f['values'][$name] ?? ($o['default'] ?? ''));
    $err = $f['errors'][$name] ?? '';
    $desc = [];
    if (!empty($o['hint'])) {
        $desc[] = $id . '_hint';
    }
    if ($err) {
        $desc[] = $id . '_err';
    }
    $attrs = ' id="' . e($id) . '" name="' . e($name) . '"'
        . (!empty($o['required']) ? ' required aria-required="true"' : '')
        . ($err ? ' aria-invalid="true"' : '')
        . ($desc ? ' aria-describedby="' . e(implode(' ', $desc)) . '"' : '')
        . (isset($o['maxlength']) ? ' maxlength="' . (int) $o['maxlength'] . '"' : '')
        . (isset($o['placeholder']) ? ' placeholder="' . e($o['placeholder']) . '"' : '')
        . (isset($o['autocomplete']) ? ' autocomplete="' . e($o['autocomplete']) . '"' : '')
        . (isset($o['inputmode']) ? ' inputmode="' . e($o['inputmode']) . '"' : '');

    $h = '<div class="fx-field' . ($err ? ' has-error' : '') . '">';
    $h .= '<label for="' . e($id) . '">' . e($label) . (!empty($o['required']) ? ' <span class="req" aria-hidden="true">*</span>' : ' <span class="fx-opt">(optional)</span>') . '</label>';
    $h .= '<div class="fx-control' . ($type === 'textarea' ? ' is-area' : '') . ($type === 'select' ? ' is-select' : '') . '">';
    if (!empty($o['icon'])) {
        $h .= icon($o['icon']);
    }
    if ($type === 'select') {
        $h .= '<select' . $attrs . '><option value="">' . e($o['empty'] ?? 'Choose') . '</option>';
        foreach ((array) ($o['options'] ?? []) as $v => $text) {
            $h .= '<option value="' . e((string) $v) . '"' . ((string) $v === $value ? ' selected' : '') . '>' . e((string) $text) . '</option>';
        }
        $h .= '</select>';
    } elseif ($type === 'textarea') {
        $h .= '<textarea' . $attrs . ' rows="' . (int) ($o['rows'] ?? 5) . '">' . e($value) . '</textarea>';
    } else {
        $h .= '<input type="' . e($type) . '"' . $attrs . ' value="' . e($value) . '">';
    }
    $h .= '</div>';
    if (!empty($o['count'])) {
        $h .= '<p class="fx-count" data-for="' . e($id) . '" data-max="' . (int) $o['count'] . '" hidden></p>';
    }
    if (!empty($o['hint'])) {
        $h .= '<p class="fx-hint" id="' . e($id) . '_hint">' . e($o['hint']) . '</p>';
    }
    if ($err) {
        $h .= '<p class="fx-error" id="' . e($id) . '_err">' . e($err) . '</p>';
    }
    return $h . '</div>';
}

/** A checkbox with its label (the label text may contain trusted links) and error message. */
function fx_check(array $f, string $name, string $labelHtml): string
{
    $id = 'f_' . $name;
    $err = $f['errors'][$name] ?? '';
    $checked = ($f['values'][$name] ?? '') === '1';
    return '<div class="fx-field' . ($err ? ' has-error' : '') . '"><div class="fx-check"><input type="checkbox" id="' . e($id) . '" name="' . e($name) . '" value="1"'
        . ($checked ? ' checked' : '') . ($err ? ' aria-invalid="true" aria-describedby="' . e($id) . '_err"' : '') . '><label for="' . e($id) . '">' . $labelHtml . '</label></div>'
        . ($err ? '<p class="fx-error" id="' . e($id) . '_err">' . e($err) . '</p>' : '') . '</div>';
}

/** The summary shown above a form that has errors. */
function fx_error_summary(array $errors): string
{
    if (!$errors) {
        return '';
    }
    $h = '<div class="fx-alert fx-alert-error" role="alert">' . icon('alert') . '<div><strong>Please check the form</strong><ul>';
    foreach ($errors as $name => $msg) {
        $h .= '<li><a href="#f_' . e($name) . '">' . e($msg) . '</a></li>';
    }
    return $h . '</ul></div></div>';
}

/** The hidden "trap" field. People never see it; many robots fill it in. */
function fx_trap(): string
{
    return '<div class="fx-trap" aria-hidden="true"><label for="f_website">Leave this field empty</label><input type="text" id="f_website" name="website" value="" tabindex="-1" autocomplete="off"></div>';
}

/** Remember a successful submission across the redirect so the page can show a confirmation once. */
function remember_public_done(string $kind, string $reference): void
{
    $_SESSION['public_done'] = ['kind' => $kind, 'reference' => $reference];
}

function take_public_done(string $kind): ?array
{
    $d = $_SESSION['public_done'] ?? null;
    if (is_array($d) && ($d['kind'] ?? '') === $kind) {
        unset($_SESSION['public_done']);
        return $d;
    }
    return null;
}
