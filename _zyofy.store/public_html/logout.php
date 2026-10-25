<?php
require_once __DIR__ . '/session.php';
dd_logout_user();
header('Location: ' . dd_url('/'));
exit;
