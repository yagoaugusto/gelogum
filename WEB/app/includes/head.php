<?php
declare(strict_types=1);

/** @var string $pageTitle */
$pageTitle = $pageTitle ?? 'GELO';
?>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?></title>

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link href="<?= htmlspecialchars(GELO_BASE_URL . '/public/assets/app.css', ENT_QUOTES, 'UTF-8') ?>" rel="stylesheet" type="text/css" />
<script src="<?= htmlspecialchars(GELO_BASE_URL . '/public/assets/app.js', ENT_QUOTES, 'UTF-8') ?>" defer></script>
