<?php
require_once __DIR__ . '/config.php';

if (is_post()) {
    verify_csrf();
    if ($admin = current_admin()) {
        record_activity($admin['id'], 'logout', 'Admin logged out');
    }
    session_unset();
    session_destroy();
}
redirect('login.php');
