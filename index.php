<?php
// index.php - upload + browse
declare(strict_types=1);
require_once __DIR__ . '/functions.php';

$user = current_user();
$mine = isset($_GET['mine']) && $user;
$page_title = $mine ? 'My images' : 'Upload';
$bulk_error = '';

if ($user && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    if (!csrf_check()) {
        $bulk_error = 'Bad request.';
    } elseif ($action === 'delete_selected') {
        $image_ids = $_POST['image_ids'] ?? [];
        if (!is_array($image_ids)) {
            $image_ids = [];
        }
        $config = require __DIR__ . '/config.php';
        $result = delete_user_images(db(), (int) $user['id'], $image_ids, (string) $config['upload_path']);
        if ($result['ok']) {
            header('Location: ' . ($mine ? 'index.php?mine=1' : 'index.php'));
            exit;
        }
        $bulk_error = $result['error'];
    } elseif ($action === 'add_to_gallery') {
        $image_ids = $_POST['image_ids'] ?? [];
        if (!is_array($image_ids)) {
            $image_ids = [];
        }
        $has_ids = false;
        foreach ($image_ids as $id) {
            if ((is_int($id) || (is_string($id) && ctype_digit($id))) && (int) $id > 0) {
                $has_ids = true;
                break;
            }
        }
        $gallery_sel = trim((string) ($_POST['gallery_id'] ?? '0'));
        $new_name    = trim((string) ($_POST['new_gallery_name'] ?? ''));
        $pdo         = db();
        $gallery_id  = null;
        $gallery_slug = null;

        if (!$has_ids) {
            $bulk_error = 'Select at least one image.';
        } elseif ($gallery_sel === 'new' || $new_name !== '') {
            $created = create_user_gallery($pdo, (int) $user['id'], $new_name);
            if (!$created['ok']) {
                $bulk_error = $created['error'];
            } else {
                $gallery_id   = $created['id'];
                $gallery_slug = $created['slug'];
            }
        } elseif (ctype_digit($gallery_sel) && (int) $gallery_sel > 0) {
            $stmt = $pdo->prepare('SELECT id, slug FROM galleries WHERE id = ? AND user_id = ?');
            $stmt->execute([(int) $gallery_sel, $user['id']]);
            $g = $stmt->fetch();
            if (!$g) {
                $bulk_error = 'Gallery not found.';
            } else {
                $gallery_id   = (int) $g['id'];
                $gallery_slug = $g['slug'];
            }
        } else {
            $bulk_error = 'Choose a gallery.';
        }

        if ($bulk_error === '' && $gallery_id !== null) {
            $result = add_user_images_to_gallery($pdo, (int) $user['id'], $gallery_id, $image_ids);
            if ($result['ok']) {
                header('Location: gallery.php?s=' . urlencode((string) $gallery_slug));
                exit;
            }
            $bulk_error = $result['error'];
        }
    }
}

$user_galleries = $user ? get_user_galleries((int) $user['id']) : [];

include __DIR__ . '/header.php';

$allowed = get_allowed_formats();
$accept_attr = '';
$fmt_labels  = [];
foreach ($allowed as $slug) {
    $f = all_formats()[$slug];
    $fmt_labels[] = $f['label'];
    foreach ($f['ext'] as $e) $accept_attr .= '.' . $e . ',';
    foreach ($f['mimes'] as $m) $accept_attr .= $m . ',';
}
$accept_attr = rtrim($accept_attr, ',');
?>
<?php if (!$user): ?>
    <section class="card wide">
        <h1>Welcome to <?= h(get_site_name()) ?></h1>
        <p>This is a private image host.
            <?php if (get_registration_mode() === 'open'): ?>
                <a href="register.php">Sign up</a> or <a href="login.php">log in</a> to upload.
            <?php else: ?>
                <a href="login.php">Log in</a> to upload.
            <?php endif; ?>
        </p>
    </section>
<?php else: ?>
    <?php if (!$mine): ?>
    <section class="card wide">
        <h1>Upload images</h1>
        <p class="muted">
            Allowed: <?= h(implode(', ', $fmt_labels)) ?>.
            Max <?= h((string) get_max_size_mb()) ?> MB each.
        </p>
        <form id="uploadForm" method="post" action="upload.php" enctype="multipart/form-data">
            <?= csrf_field() ?>
            <label class="dropzone" id="dz">
                <p><strong>Drop images here</strong> or click to choose files.</p>
                <p class="muted" id="dzfile">No files selected.</p>
                <input id="file" type="file" name="image[]" accept="<?= h($accept_attr) ?>" required multiple>
            </label>
            <label>Add to gallery <span class="muted">(optional)</span>
                <select name="gallery_id" id="gallery_select">
                    <option value="0">— No gallery —</option>
                    <?php foreach ($user_galleries as $g): ?>
                        <option value="<?= h((string) $g['id']) ?>"><?= h($g['name']) ?></option>
                    <?php endforeach; ?>
                    <option value="new">New gallery…</option>
                </select>
            </label>
            <div id="new_gallery_row" style="display:none">
                <label>New gallery name
                    <input type="text" name="new_gallery_name" id="new_gallery_name" maxlength="100" placeholder="Gallery name">
                </label>
            </div>
            <button class="primary" type="submit">Upload</button>
        </form>
    </section>
    <script>
    (function () {
        var dz    = document.getElementById('dz');
        var input = document.getElementById('file');
        var label = document.getElementById('dzfile');
        var sel   = document.getElementById('gallery_select');
        var newRow = document.getElementById('new_gallery_row');

        if (sel && newRow) {
            sel.addEventListener('change', function () {
                newRow.style.display = this.value === 'new' ? '' : 'none';
                if (this.value !== 'new') {
                    document.getElementById('new_gallery_name').value = '';
                }
            });
        }

        if (!dz || !input) return;
        ['dragenter','dragover'].forEach(function (ev) {
            dz.addEventListener(ev, function (e) { e.preventDefault(); dz.classList.add('hover'); });
        });
        ['dragleave','drop'].forEach(function (ev) {
            dz.addEventListener(ev, function (e) { e.preventDefault(); dz.classList.remove('hover'); });
        });
        dz.addEventListener('drop', function (e) {
            if (e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files.length) {
                input.files = e.dataTransfer.files;
                updateLabel(e.dataTransfer.files);
            }
        });
        input.addEventListener('change', function () {
            updateLabel(input.files);
        });
        function updateLabel(files) {
            if (!files || files.length === 0) {
                label.textContent = 'No files selected.';
            } else if (files.length === 1) {
                label.textContent = files[0].name;
            } else {
                label.textContent = files.length + ' files selected.';
            }
        }
    })();
    </script>
    <?php endif; ?>

    <?php
    if ($mine) {
        $stmt = db()->prepare('SELECT * FROM images WHERE user_id = ? ORDER BY uploaded_at DESC LIMIT 200');
        $stmt->execute([$user['id']]);
    } else {
        $stmt = db()->prepare('SELECT * FROM images WHERE user_id = ? ORDER BY uploaded_at DESC LIMIT 24');
        $stmt->execute([$user['id']]);
    }
    $images = $stmt->fetchAll();
    ?>

    <h2 style="margin-top:2rem"><?= $mine ? 'All your uploads' : 'Recent uploads' ?></h2>
    <?php if ($bulk_error): ?><p class="err"><?= h($bulk_error) ?></p><?php endif; ?>
    <?php if (!$images): ?>
        <p class="muted">Nothing yet. Upload something above.</p>
    <?php else: ?>
        <form method="post" action="" id="bulkGalleryForm">
            <?= csrf_field() ?>
            <div class="bulkbar">
                <label class="bulk-select-all">
                    <input type="checkbox" id="select_all"> Select all
                </label>
                <label class="bulk-gallery">Add to gallery
                    <select name="gallery_id" id="bulk_gallery_select">
                        <option value="0">— Choose gallery —</option>
                        <?php foreach ($user_galleries as $g): ?>
                            <option value="<?= h((string) $g['id']) ?>"><?= h($g['name']) ?></option>
                        <?php endforeach; ?>
                        <option value="new">New gallery…</option>
                    </select>
                </label>
                <label class="bulk-new" id="bulk_new_gallery_row" style="display:none">New gallery name
                    <input type="text" name="new_gallery_name" id="bulk_new_gallery_name" maxlength="100" placeholder="Gallery name">
                </label>
                <button class="primary" type="submit" name="action" value="add_to_gallery">Add checked to gallery</button>
                <button class="danger" type="submit" name="action" value="delete_selected">Delete selected</button>
            </div>
            <div class="grid">
                <?php foreach ($images as $img): ?>
                    <div class="tile">
                        <input class="pick" type="checkbox" name="image_ids[]" value="<?= h((string) $img['id']) ?>" aria-label="Select image">
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
        </form>
        <script>
        (function () {
            var form = document.getElementById('bulkGalleryForm');
            if (!form) return;
            var all = document.getElementById('select_all');
            var picks = form.querySelectorAll('input.pick');
            var sel = document.getElementById('bulk_gallery_select');
            var newRow = document.getElementById('bulk_new_gallery_row');
            if (all) {
                all.addEventListener('change', function () {
                    picks.forEach(function (cb) { cb.checked = all.checked; });
                });
            }
            if (sel && newRow) {
                sel.addEventListener('change', function () {
                    newRow.style.display = this.value === 'new' ? '' : 'none';
                    if (this.value !== 'new') {
                        document.getElementById('bulk_new_gallery_name').value = '';
                    }
                });
            }
            form.addEventListener('submit', function (e) {
                var submitter = e.submitter;
                if (!submitter || submitter.value !== 'delete_selected') return;
                var n = form.querySelectorAll('input.pick:checked').length;
                var msg = n === 1
                    ? 'Delete this image permanently?'
                    : 'Delete ' + n + ' selected images permanently?';
                if (n === 0) {
                    msg = 'Delete selected images permanently?';
                }
                if (!confirm(msg)) {
                    e.preventDefault();
                }
            });
        })();
        </script>
    <?php endif; ?>
<?php endif; ?>

<?php include __DIR__ . '/footer.php';
