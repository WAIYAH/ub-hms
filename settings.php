<?php
/** Settings: your contact number, your password and how this browser shows the system. */
require __DIR__ . '/includes/bootstrap.php';
$me = require_login();

if (is_post()) {
    require_post();
    if (post_str('action', 20) === 'phone') {
        $raw = post_str('phone', 20);
        $r = update_own_phone((int) $me['id'], $raw);
        if (!$r['ok']) {
            remember_form(['phone' => $raw], ['phone' => (string) $r['error']]);
            redirect('settings.php');
        }
        flash('success', $raw === '' ? 'Your phone number was removed.' : 'Your phone number was saved.');
    }
    redirect('settings.php');
}

$u = get_user((int) $me['id']);
$form = take_form();
$f = ['values' => $form['values'] ?: ['phone' => (string) $u['phone']], 'errors' => $form['errors']];
$idle = (int) round((int) config('session.idle_timeout') / 60);

page_start('Settings', '', ['no_title' => true]);
?>
<section class="page-banner">
    <div><h1>Settings</h1><p>Your contact number, your password and how this screen looks on this computer.</p></div>
    <a class="btn btn-secondary btn-icon" href="<?= e(url('profile.php')) ?>"><?= icon('user-circle') ?><span>View profile</span></a>
</section>

<div class="dash-grid">
    <section class="panel w-6" aria-labelledby="st-contact">
        <header class="panel-head"><div><h2 id="st-contact">Contact number</h2><p>Used by the administrator to reach you. It is never shown to patients.</p></div><span class="panel-ic panel-ic-blue"><?= icon('phone') ?></span></header>
        <div class="panel-body">
            <?= error_summary($f['errors']) ?>
            <?= form_open() ?>
                <input type="hidden" name="action" value="phone">
                <?= field($f, 'phone', 'Mobile phone', ['maxlength' => 20, 'inputmode' => 'tel', 'autocomplete' => 'tel', 'placeholder' => '0712 345 678', 'hint' => 'Leave blank to remove your number.']) ?>
                <button class="btn btn-success btn-icon" type="submit"><?= icon('check') ?><span>Save number</span></button>
            </form>
        </div>
    </section>

    <section class="panel w-6" aria-labelledby="st-security">
        <header class="panel-head"><div><h2 id="st-security">Password and sign-in</h2><p>Keep your account private. It is recorded under your name.</p></div><span class="panel-ic panel-ic-amber"><?= icon('shield') ?></span></header>
        <div class="panel-body">
            <ul class="facts-list">
                <li><?= icon('clock') ?><span>You are signed out automatically after <strong><?= (int) $idle ?> minutes</strong> without activity.</span></li>
                <li><?= icon('lock') ?><span>Five wrong passwords in a row lock your account for <?= (int) config('security.lockout_minutes') ?> minutes.</span></li>
                <li><?= icon('key') ?><span>Choose a long password you use nowhere else. The system never shows or emails it.</span></li>
            </ul>
            <p class="mt"><a class="btn btn-warning btn-icon" href="<?= e(url('password.php')) ?>"><?= icon('key') ?><span>Change my password</span></a></p>
        </div>
    </section>

    <section class="panel w-12" id="display" aria-labelledby="st-display" hidden data-prefs>
        <header class="panel-head"><div><h2 id="st-display">Display on this computer</h2><p>Saved in this browser only. They do not change anything for other people.</p></div><span class="panel-ic panel-ic-violet"><?= icon('sliders') ?></span></header>
        <div class="panel-body">
            <div class="pref-list">
                <label class="pref"><input type="checkbox" data-pref="sb-collapsed"><span><strong>Start with the menu shrunk to icons</strong><small>Gives tables and charts more room on small screens.</small></span></label>
                <label class="pref"><input type="checkbox" data-pref="dense"><span><strong>Compact tables and cards</strong><small>Smaller spacing, so more fits on the screen.</small></span></label>
            </div>
        </div>
    </section>
</div>
<?php page_end();
