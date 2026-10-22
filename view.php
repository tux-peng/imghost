<?php
// view.php - single image view with share links
declare(strict_types=1);
require_once __DIR__ . '/functions.php';

$slug = $_GET['s'] ?? '';
if (!preg_match('/^[A-Za-z0-9]{4,16}$/', $slug)) {
    http_response_code(404);
    die('Not found.');
}

$stmt = db()->prepare('SELECT i.*, u.username FROM images i LEFT JOIN users u ON u.id = i.user_id WHERE i.slug = ?');
$stmt->execute([$slug]);
$img = $stmt->fetch();
if (!$img) {
    http_response_code(404);
    die('Not found.');
}

$user = current_user();
$can_delete = $user && ($user['is_admin'] || (int) $user['id'] === (int) $img['user_id']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $can_delete && csrf_check() && ($_POST['action'] ?? '') === 'delete') {
    $config = require __DIR__ . '/config.php';
    @unlink($config['upload_path'] . '/' . $img['filename']);
    $del = db()->prepare('DELETE FROM images WHERE id = ?');
    $del->execute([$img['id']]);
    header('Location: index.php');
    exit;
}

$page_title = 'Image';
include __DIR__ . '/header.php';

$direct = image_direct_url($img);
$page   = base_url() . '/view.php?s=' . urlencode($img['slug']);
$embed  = image_embed_tag($direct, (string) $img['original_name']);
?>
<section class="imgview">
    <img src="<?= h($direct) ?>" alt="<?= h($img['original_name']) ?>">
    <p class="muted">
        <?= h(strtoupper($img['format'])) ?> &middot;
        <?= h(human_size((int) $img['size'])) ?> &middot;
        Uploaded <?= h(date('Y-m-d H:i', (int) $img['uploaded_at'])) ?>
        <?php if ($img['username']): ?> by @<?= h($img['username']) ?><?php endif; ?>
    </p>
    <?php if ($user): ?>
        <div class="links share-links">
            <label>Direct URL <span class="muted">(wget / embed src)</span>
                <input type="text" readonly value="<?= h($direct) ?>" onclick="this.select()">
            </label>
            <label>HTML embed
                <input type="text" readonly value="<?= h($embed) ?>" onclick="this.select()">
            </label>
            <label>Page
                <input type="text" readonly value="<?= h($page) ?>" onclick="this.select()">
            </label>
        </div>
    <?php else: ?>
        <div class="links">
            <input type="text" readonly value="<?= h($direct) ?>" onclick="this.select()">
            <input type="text" readonly value="<?= h($page) ?>" onclick="this.select()">
        </div>
    <?php endif; ?>
    <p style="margin-top:1rem">
        <a class="button" href="<?= h($direct) ?>?d=1">Download</a>
        <?php if ($can_delete): ?>
            <form method="post" style="display:inline" onsubmit="return confirm('Delete this image?');">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="delete">
                <button class="danger" type="submit">Delete</button>
            </form>
        <?php endif; ?>
    </p>
</section>
<?php include __DIR__ . '/footer.php';
