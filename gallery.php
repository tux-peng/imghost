<?php
// gallery.php - view a gallery and its images
declare(strict_types=1);
require_once __DIR__ . '/functions.php';

$raw_slug = $_GET['s'] ?? '';
if (!preg_match('/^[a-zA-Z0-9]{4,16}$/', $raw_slug)) {
    http_response_code(404);
    die('Gallery not found.');
}

$gallery = get_gallery_by_slug($raw_slug);
if (!$gallery) {
    http_response_code(404);
    die('Gallery not found.');
}

$user       = current_user();
$is_owner   = $user && (int) $gallery['user_id'] === (int) $user['id'];
$can_manage = $is_owner || ($user && $user['is_admin']);
$err        = null;

// Handle delete gallery action.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $can_manage && csrf_check()) {
    $action = $_POST['action'] ?? '';
    if ($action === 'delete_gallery') {
        $config = require __DIR__ . '/config.php';
        $result = delete_user_gallery(
            db(),
            (int) $user['id'],
            (int) $gallery['id'],
            (string) $config['upload_path'],
            (int) $user['is_admin'] !== 0
        );
        if (!empty($result['ok'])) {
            header('Location: galleries.php');
            exit;
        }
        $err = $result['error'] ?? 'Could not delete gallery.';
    }
}

$owner_stmt = db()->prepare('SELECT username FROM users WHERE id = ?');
$owner_stmt->execute([$gallery['user_id']]);
$owner = $owner_stmt->fetch();

$imgs_stmt = db()->prepare('SELECT * FROM images WHERE gallery_id = ? ORDER BY uploaded_at DESC');
$imgs_stmt->execute([(int) $gallery['id']]);
$images = $imgs_stmt->fetchAll();

$page_title = $gallery['name'];
include __DIR__ . '/header.php';
?>

<div style="margin-bottom:1.5rem">
    <h1 style="margin:0 0 0.3rem"><?= h($gallery['name']) ?></h1>
    <?php if ($owner): ?>
        <span class="muted">by @<?= h($owner['username']) ?></span>
    <?php endif; ?>
    <?php if ($gallery['description'] !== ''): ?>
        <p class="muted" style="margin:0.4rem 0 0"><?= h($gallery['description']) ?></p>
    <?php endif; ?>
    <p class="muted" style="margin:0.3rem 0 0;font-size:0.85rem"><?= count($images) ?> image<?= count($images) !== 1 ? 's' : '' ?></p>
</div>

<?php if ($err): ?><p class="err"><?= h($err) ?></p><?php endif; ?>
<?php if (!$images): ?>
    <p class="muted">No images in this gallery yet.</p>
    <?php if ($can_manage): ?>
        <form method="post" onsubmit="return confirm('Delete this empty gallery permanently?')">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="delete_gallery">
            <button class="danger" type="submit">Delete gallery &amp; images</button>
        </form>
    <?php endif; ?>
<?php else: ?>
    <div class="grid">
        <?php foreach ($images as $img): ?>
            <div class="tile">
                <a class="thumb" href="view.php?s=<?= h($img['slug']) ?>">
                    <img loading="lazy" src="i.php?s=<?= h($img['slug']) ?>" alt="">
                </a>
                <div class="meta">
                    <span><?= h(strtoupper($img['format'])) ?></span>
                    <span><?= h(human_size((int) $img['size'])) ?></span>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
    <?php if ($can_manage): ?>
        <hr>
        <form method="post" onsubmit="return confirm('Delete this gallery and all its images permanently?')">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="delete_gallery">
            <button class="danger" type="submit">Delete gallery &amp; images</button>
        </form>
    <?php endif; ?>
<?php endif; ?>

<?php include __DIR__ . '/footer.php';
