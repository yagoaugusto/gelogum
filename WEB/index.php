<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

if (gelo_is_logged_in()) {
    gelo_redirect(GELO_BASE_URL . '/dashboard.php');
}

gelo_redirect(GELO_BASE_URL . '/login.php');

