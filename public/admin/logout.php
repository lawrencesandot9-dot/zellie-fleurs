<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/database.php';
require_once ZF_ROOT . '/src/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    zf_redirect('index.php');
}

zf_require_csrf();
zf_auth_logout();
zf_redirect('login.php');
