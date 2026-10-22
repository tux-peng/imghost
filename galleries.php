<?php
// galleries.php - list and create galleries
declare(strict_types=1);
require_once __DIR__ . '/functions.php';

$user = current_user();
if (!$user) {
    header('Location: login.php');
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_check()) {
    $action = $_POST['action'] ?? '';
    if ($action === 'delete_gallery') {
        $config = require __DIR__ . '/config.php';
        $gid = (int) ($_POST['gallery_id'] ?? 0);
        $result = delete_user_gallery(
            db(),
            (int) $user['id'],
            $gid,
            (string) $config['upload_path'],
            false
        );
        if (!empty($result['ok'])) {
            header('Location: galleries.php');
            exit;
        }
        $error = $result['error'] ?? 'Could not delete gallery.';
    } else {
        $name = trim($_POST['name'] ?? '');
        $desc = trim($_POST['description'] ?? '');
        if ($name === '') {
            $error = 'Gallery name is required.';
        } elseif (mb_strlen($name) > 100) {
            $error = 'Name must be 100 characters or fewer.';
        } else {
            try {
                $slug = unique_gallery_slug();
                $stmt = db()->prepare(
                    'INSERT INTO galleries (user_id, slug, name, description, created_at) VALUES (?, ?, ?, ?, ?)'
                );
                $stmt->execute([$user['id'], $slug, $name, substr($desc, 0, 500), time()]);
                header('Location: gallery.php?s=' . urlencode($slug));
                exit;
            } catch (Throwable $e) {
                $error = 'Could not create gallery.';
            }
        }
    }
}

$galleries = get_user_galleries((int) $user['id']);

// Fetch cover image and count for each gallery.
$covers = [];
$counts = [];
foreach ($galleries as $g) {
    $c = db()->prepare('SELECT slug FROM images WHERE gallery_id = ? ORDER BY uploaded_at DESC LIMIT 1');
    $c->execute([$g['id']]);
    $covers[$g['id']] = $c->fetch() ?: null;

    $n = db()->prepare('SELECT COUNT(*) FROM images WHERE gallery_id = ?');
    $n->execute([$g['id']]);
    $counts[$g['id']] = (int) $n->fetchColumn();
}

$page_title = 'My galleries';
include __DIR__ . '/header.php';
?>

<section class="card wide">
    <h1>Create a gallery</h1>
    <?php if ($error): ?><p class="err"><?= h($error) ?></p><?php endif; ?>
    <form method="post">
        <?= csrf_field() ?>
        <label>Name
            <input type="text" name="name" maxlength="100" required value="<?= h($_POST['name'] ?? '') ?>">
        </label>
        <label>Description <span class="muted">(optional)</span>
            <input type="text" name="description" maxlength="500" value="<?= h($_POST['description'] ?? '') ?>">
        </label>
        <button class="primary" type="submit">Create gallery</button>
    </form>
</section>

<h2>Your galleries</h2>
<?php if (!$galleries): ?>
    <p class="muted">No galleries yet.</p>
<?php else: ?>
    <div class="grid">
        <?php foreach ($galleries as $g): ?>
            <div class="tile">
                <a class="thumb" href="gallery.php?s=<?= h($g['slug']) ?>">
                    <?php if ($covers[$g['id']]): ?>
                        <img loading="lazy" src="i.php?s=<?= h($covers[$g['id']]['slug']) ?>" alt="">
                    <?php else: ?>
                        <span class="empty-gallery">Empty</span>
                    <?php endif; ?>
                </a>
                <div class="meta">
                    <span title="<?= h($g['name']) ?>"><?= h($g['name']) ?></span>
                    <span><?= $counts[$g['id']] ?> img<?= $counts[$g['id']] !== 1 ? 's' : '' ?></span>
                </div>
                <?php
                $n = $counts[$g['id']];
                $confirm = $n === 0
                    ? 'Delete this empty gallery?'
                    : 'Delete this gallery and ' . $n . ' image' . ($n !== 1 ? 's' : '') . ' permanently?';
                ?>
                <form class="tile-delete" method="post" onsubmit="return confirm(<?= json_encode($confirm, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>);">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="delete_gallery">
                    <input type="hidden" name="gallery_id" value="<?= (int) $g['id'] ?>">
                    <button class="danger" type="submit">Delete</button>
                </form>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php include __DIR__ . '/footer.php';
