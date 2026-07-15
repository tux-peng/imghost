<?php
// index.php - upload + browse
declare(strict_types=1);
require_once __DIR__ . '/functions.php';

$user = current_user();
$mine = isset($_GET['mine']) && $user;
$page_title = $mine ? 'My images' : 'Upload';

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
            <?php $galleries = get_user_galleries((int) $user['id']); ?>
            <label>Add to gallery <span class="muted">(optional)</span>
                <select name="gallery_id" id="gallery_select">
                    <option value="0">— No gallery —</option>
                    <?php foreach ($galleries as $g): ?>
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
    <?php if (!$images): ?>
        <p class="muted">Nothing yet. Upload something above.</p>
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
    <?php endif; ?>
<?php endif; ?>

<?php include __DIR__ . '/footer.php';
