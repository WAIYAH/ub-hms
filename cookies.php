<?php
/** Cookie notice. Public. Describes exactly the one cookie the application sets. */
require __DIR__ . '/includes/bootstrap.php';

$name = (string) config('session.name');
$toc = [['what', 'What a cookie is'], ['ours', 'The one cookie we set'], ['none', 'What we do not use'], ['control', 'Your choices']];
legal_start('cookies', 'UB-HMS sets one cookie, and it is needed for the site to work.', $toc);
?>
<h2 id="what">1. What a cookie is</h2>
<p>A cookie is a small piece of text that a website asks your browser to keep. It lets the website recognise your browser from one page to the next.</p>

<h2 id="ours">2. The one cookie we set</h2>
<table>
    <thead><tr><th>Name</th><th>What it is for</th><th>How long</th><th>Protection</th></tr></thead>
    <tbody>
        <tr>
            <td data-label="Name"><code><?= e($name) ?></code></td>
            <td data-label="What it is for">Keeps you signed in, and holds the secret token that protects forms (including the contact and request-access forms) against forgery. It contains a random number only, never your name or any health information.</td>
            <td data-label="How long">Until you close the browser. The server also ends the session after 30 idle minutes, or 8 hours after sign-in, and when you sign out.</td>
            <td data-label="Protection">HttpOnly (scripts cannot read it), SameSite=Strict (not sent from other websites), and Secure when the site is used over HTTPS.</td>
        </tr>
    </tbody>
</table>
<p>This cookie is <strong>strictly necessary</strong>: without it, you could not sign in and the forms could not be protected. For that reason it does not need a consent banner, and we do not show one.</p>

<h2 id="none">3. What we do not use</h2>
<ul>
    <li>No analytics cookies, advertising cookies or tracking pixels.</li>
    <li>No cookies from other websites. UB-HMS loads no fonts, scripts, images or maps from outside the hospital's own server.</li>
    <li>No browser storage (local storage or similar) to follow you around.</li>
</ul>

<h2 id="control">4. Your choices</h2>
<p>You can delete the cookie at any time in your browser's settings, or block cookies altogether, but then you will not be able to sign in or send the forms. Questions? <a href="<?= e(url('contact.php')) ?>">Contact us</a>. More about how we use information is in the <a href="<?= e(url('privacy.php')) ?>">privacy policy</a>.</p>
<?php legal_end();
