<?php
// gallery_actions.php - bulk image helpers (gallery assign, delete selected)
declare(strict_types=1);

/**
 * @param list<mixed> $image_ids
 * @return array{ok:true,count:int}|array{ok:false,error:string}
 */
function add_user_images_to_gallery(PDO $pdo, int $user_id, int $gallery_id, array $image_ids): array {
    $ids = [];
    foreach ($image_ids as $id) {
        if (is_int($id) || (is_string($id) && ctype_digit($id))) {
            $n = (int) $id;
            if ($n > 0) {
                $ids[$n] = $n;
            }
        }
    }
    $ids = array_values($ids);
    if (!$ids) {
        return ['ok' => false, 'error' => 'Select at least one image.'];
    }

    $g = $pdo->prepare('SELECT id FROM galleries WHERE id = ? AND user_id = ?');
    $g->execute([$gallery_id, $user_id]);
    if (!$g->fetch()) {
        return ['ok' => false, 'error' => 'Gallery not found.'];
    }

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $sel = $pdo->prepare("SELECT id FROM images WHERE user_id = ? AND id IN ($placeholders)");
    $sel->execute(array_merge([$user_id], $ids));
    $owned = array_map('intval', array_column($sel->fetchAll(), 'id'));
    if (!$owned) {
        return ['ok' => false, 'error' => 'No matching images to add.'];
    }

    $ph2 = implode(',', array_fill(0, count($owned), '?'));
    $upd = $pdo->prepare("UPDATE images SET gallery_id = ? WHERE id IN ($ph2)");
    $upd->execute(array_merge([$gallery_id], $owned));

    return ['ok' => true, 'count' => count($owned)];
}

/**
 * @param list<mixed> $image_ids
 * @return array{ok:true,count:int}|array{ok:false,error:string}
 */
function delete_user_images(PDO $pdo, int $user_id, array $image_ids, string $upload_path): array {
    $ids = [];
    foreach ($image_ids as $id) {
        if (is_int($id) || (is_string($id) && ctype_digit($id))) {
            $n = (int) $id;
            if ($n > 0) {
                $ids[$n] = $n;
            }
        }
    }
    $ids = array_values($ids);
    if (!$ids) {
        return ['ok' => false, 'error' => 'Select at least one image.'];
    }

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $sel = $pdo->prepare("SELECT id, filename FROM images WHERE user_id = ? AND id IN ($placeholders)");
    $sel->execute(array_merge([$user_id], $ids));
    $owned = $sel->fetchAll();
    if (!$owned) {
        return ['ok' => false, 'error' => 'No matching images to delete.'];
    }

    $upload_path = rtrim($upload_path, '/\\');
    foreach ($owned as $row) {
        $base = basename((string) $row['filename']);
        if ($base !== '' && $base !== '.' && $base !== '..') {
            @unlink($upload_path . '/' . $base);
        }
    }

    $owned_ids = array_map('intval', array_column($owned, 'id'));
    $ph2 = implode(',', array_fill(0, count($owned_ids), '?'));
    $del = $pdo->prepare("DELETE FROM images WHERE id IN ($ph2)");
    $del->execute($owned_ids);

    return ['ok' => true, 'count' => count($owned_ids)];
}

/**
 * @return array{ok:true,id:int,slug:string,name:string}|array{ok:false,error:string}
 */
function create_user_gallery(PDO $pdo, int $user_id, string $name, string $description = ''): array {
    $name = trim($name);
    if ($name === '') {
        return ['ok' => false, 'error' => 'Gallery name is required.'];
    }
    $len = function_exists('mb_strlen') ? mb_strlen($name) : strlen($name);
    if ($len > 100) {
        return ['ok' => false, 'error' => 'Name must be 100 characters or fewer.'];
    }

    $alphabet = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    $slug = '';
    while (true) {
        $slug = '';
        for ($i = 0; $i < 8; $i++) {
            $slug .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        $stmt = $pdo->prepare('SELECT 1 FROM galleries WHERE slug = ?');
        $stmt->execute([$slug]);
        if (!$stmt->fetch()) {
            break;
        }
    }

    $desc = substr($description, 0, 500);
    $pdo->prepare(
        'INSERT INTO galleries (user_id, slug, name, description, created_at) VALUES (?, ?, ?, ?, ?)'
    )->execute([$user_id, $slug, $name, $desc, time()]);

    return [
        'ok'   => true,
        'id'   => (int) $pdo->lastInsertId(),
        'slug' => $slug,
        'name' => $name,
    ];
}

/**
 * @return array{ok:true,count:int}|array{ok:false,error:string}
 */
function delete_user_gallery(
    PDO $pdo,
    int $user_id,
    int $gallery_id,
    string $upload_path,
    bool $as_admin = false
): array {
    if ($gallery_id <= 0) {
        return ['ok' => false, 'error' => 'Gallery not found.'];
    }

    if ($as_admin) {
        $stmt = $pdo->prepare('SELECT id FROM galleries WHERE id = ?');
        $stmt->execute([$gallery_id]);
    } else {
        $stmt = $pdo->prepare('SELECT id FROM galleries WHERE id = ? AND user_id = ?');
        $stmt->execute([$gallery_id, $user_id]);
    }
    if (!$stmt->fetch()) {
        return ['ok' => false, 'error' => 'Gallery not found.'];
    }

    $sel = $pdo->prepare('SELECT filename FROM images WHERE gallery_id = ?');
    $sel->execute([$gallery_id]);
    $files = $sel->fetchAll();

    $upload_path = rtrim($upload_path, '/\\');
    foreach ($files as $row) {
        $base = basename((string) $row['filename']);
        if ($base !== '' && $base !== '.' && $base !== '..') {
            @unlink($upload_path . '/' . $base);
        }
    }

    $pdo->prepare('DELETE FROM images WHERE gallery_id = ?')->execute([$gallery_id]);
    $pdo->prepare('DELETE FROM galleries WHERE id = ?')->execute([$gallery_id]);

    return ['ok' => true, 'count' => count($files)];
}
