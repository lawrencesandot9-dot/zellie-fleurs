<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/database.php';
require_once ZF_ROOT . '/src/auth.php';
require_once ZF_ROOT . '/src/settings.php';

zf_require_admin();

$settings = zf_settings(true);
$settingsErrors = [];
$passwordErrors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    zf_require_csrf();
    $action = zf_safe_text($_POST['action'] ?? '');

    if ($action === 'save_settings') {
        if (zf_update_settings($db, $_POST, $settingsErrors)) {
            zf_flash('success', 'Shop settings saved.');
            zf_redirect('/admin/settings.php');
        }

        foreach (zf_settings_allowed_keys() as $key) {
            $settings[$key] = zf_safe_text($_POST[$key] ?? '');
        }
    }

    if ($action === 'change_password') {
        $admin = zf_current_admin();
        $result = zf_change_admin_password(
            $db,
            (int) ($admin['id'] ?? 0),
            (string) ($_POST['current_password'] ?? ''),
            (string) ($_POST['new_password'] ?? ''),
            (string) ($_POST['confirm_password'] ?? '')
        );

        if ($result['ok']) {
            zf_flash('success', 'Administrator password updated.');
            zf_redirect('/admin/settings.php');
        }
        $passwordErrors[] = $result['error'];
    }
}

$pageTitle = 'Settings';
$activeAdmin = 'settings';
require ZF_ROOT . '/src/views/admin_header.php';
?>

<?php if ($settingsErrors !== []): ?>
    <div class="flash flash--error" role="alert">
        <strong>Please check the shop details:</strong>
        <ul class="error-list">
            <?php foreach ($settingsErrors as $error): ?>
                <li><?= zf_e($error) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<div class="admin-settings-grid">
    <section class="panel">
        <div class="admin-section__head">
            <div>
                <span class="eyebrow">Storefront information</span>
                <h2>Shop settings</h2>
            </div>
        </div>
        <p class="muted">These details appear across the storefront, checkout and tracking pages. Replace the placeholders with your real shop information when ready.</p>

        <form method="post" action="/admin/settings.php" class="settings-form">
            <?= zf_csrf_field() ?>
            <input type="hidden" name="action" value="save_settings">

            <div class="field-grid">
                <div class="field">
                    <label for="shop_name">Shop name</label>
                    <input id="shop_name" type="text" name="shop_name" value="<?= zf_e($settings['shop_name']) ?>" required maxlength="80">
                </div>
                <div class="field">
                    <label for="tagline">Tagline</label>
                    <input id="tagline" type="text" name="tagline" value="<?= zf_e($settings['tagline']) ?>" maxlength="100">
                </div>
            </div>

            <div class="field">
                <label for="announcement">Announcement bar</label>
                <input id="announcement" type="text" name="announcement" value="<?= zf_e($settings['announcement']) ?>" maxlength="220">
                <small>Leave blank to hide the announcement bar.</small>
            </div>

            <div class="field-grid">
                <div class="field">
                    <label for="contact_number">Contact number</label>
                    <input id="contact_number" type="text" name="contact_number" value="<?= zf_e($settings['contact_number']) ?>" maxlength="40">
                </div>
                <div class="field">
                    <label for="operating_hours">Operating hours</label>
                    <input id="operating_hours" type="text" name="operating_hours" value="<?= zf_e($settings['operating_hours']) ?>" maxlength="160">
                </div>
            </div>

            <div class="field">
                <label for="store_address">Store address</label>
                <input id="store_address" type="text" name="store_address" value="<?= zf_e($settings['store_address']) ?>" maxlength="220">
            </div>

            <div class="field-grid">
                <div class="field">
                    <label for="gcash_number">GCash number</label>
                    <input id="gcash_number" type="text" name="gcash_number" value="<?= zf_e($settings['gcash_number']) ?>" maxlength="40">
                </div>
                <div class="field">
                    <label for="gcash_name">GCash account name</label>
                    <input id="gcash_name" type="text" name="gcash_name" value="<?= zf_e($settings['gcash_name']) ?>" maxlength="80">
                </div>
            </div>

            <div class="field">
                <label for="delivery_info">Delivery information</label>
                <textarea id="delivery_info" name="delivery_info" rows="3" maxlength="500"><?= zf_e($settings['delivery_info']) ?></textarea>
            </div>

            <button class="btn btn--primary" type="submit">Save shop settings</button>
        </form>
    </section>

    <aside class="admin-settings-side">
        <section class="panel">
            <span class="eyebrow">Administrator</span>
            <h2>Security</h2>
            <p class="muted">Signed in as <strong><?= zf_e(zf_current_admin()['username'] ?? 'Administrator') ?></strong>.</p>

            <?php if ($passwordErrors !== []): ?>
                <div class="flash flash--error" role="alert">
                    <?php foreach ($passwordErrors as $error): ?>
                        <p><?= zf_e($error) ?></p>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <form method="post" action="/admin/settings.php" class="settings-form">
                <?= zf_csrf_field() ?>
                <input type="hidden" name="action" value="change_password">
                <div class="field">
                    <label for="current_password">Current password</label>
                    <input id="current_password" type="password" name="current_password" autocomplete="current-password" required>
                </div>
                <div class="field">
                    <label for="new_password">New password</label>
                    <input id="new_password" type="password" name="new_password" autocomplete="new-password" minlength="8" required>
                </div>
                <div class="field">
                    <label for="confirm_password">Confirm new password</label>
                    <input id="confirm_password" type="password" name="confirm_password" autocomplete="new-password" minlength="8" required>
                </div>
                <button class="btn btn--soft btn--full" type="submit">Update password</button>
            </form>
        </section>

        <section class="panel panel--muted">
            <span class="eyebrow">Setup note</span>
            <h3>Initial credentials</h3>
            <p class="muted">If the shop was just installed, the generated administrator credentials are stored in <code>data/initial_admin_credentials.txt</code>, outside the public web root. Change the password here, then delete that file.</p>
        </section>
    </aside>
</div>

<?php require ZF_ROOT . '/src/views/admin_footer.php'; ?>
