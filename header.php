<?php
// header.php - shared layout chrome
require_once __DIR__ . '/functions.php';

$user = current_user();
$default_theme = get_default_theme();
$page_title = $page_title ?? get_site_name();
?>
<!doctype html>
<html lang="en" data-theme="<?= h($default_theme) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= h($page_title) ?> &middot; <?= h(get_site_name()) ?></title>
<link rel="stylesheet" href="<?= h(base_url()) ?>/assets/style.css">
<script>
(function () {
    var saved = localStorage.getItem('theme');
    var def = document.documentElement.getAttribute('data-theme') || 'auto';
    var theme = saved || def;
    if (theme === 'auto') {
        theme = window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
    }
    document.documentElement.setAttribute('data-theme', theme);
})();
</script>
</head>
<body>
<header class="topbar">
    <a class="brand" href="<?= h(base_url()) ?>/index.php"><?= h(get_site_name()) ?></a>
    <nav>
        <?php if ($user): ?>
            <a href="<?= h(base_url()) ?>/index.php">Upload</a>
            <a href="<?= h(base_url()) ?>/index.php?mine=1">My images</a>
            <a href="<?= h(base_url()) ?>/galleries.php">Galleries</a>
            <?php if ($user['is_admin']): ?>
                <a href="<?= h(base_url()) ?>/admin.php">Admin</a>
            <?php endif; ?>
            <span class="user">@<?= h($user['username']) ?></span>
            <a href="<?= h(base_url()) ?>/account.php">Account</a>
            <a href="<?= h(base_url()) ?>/logout.php">Log out</a>
        <?php else: ?>
            <a href="<?= h(base_url()) ?>/login.php">Log in</a>
            <?php if (get_registration_mode() === 'open'): ?>
                <a href="<?= h(base_url()) ?>/register.php">Sign up</a>
            <?php endif; ?>
        <?php endif; ?>
        <button id="theme-toggle" type="button" aria-label="Toggle theme">&#9681;</button>
    </nav>
</header>
<main class="container">
