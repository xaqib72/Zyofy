<?php
require_once __DIR__ . '/inc/site.php';
require_once __DIR__ . '/db.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') dd_json(['success'=>false,'message'=>'Method not allowed'],405);
if (!dd_csrf_validate($_POST['csrf_token'] ?? '')) dd_json(['success'=>false,'message'=>'Your session expired. Reload the page and try again.'],403);
$email = strtolower(trim((string) ($_POST['email'] ?? '')));
$password = (string) ($_POST['password'] ?? '');
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $password === '') dd_json(['success'=>false,'message'=>'Enter a valid email and password.'],422);
$stmt=$pdo->prepare('SELECT id,name,email,password FROM users WHERE email=? LIMIT 1');$stmt->execute([$email]);$user=$stmt->fetch();
if (!$user || !password_verify($password,$user['password'])) { usleep(250000); dd_json(['success'=>false,'message'=>'Invalid email or password.'],401); }
dd_login_user($user);
dd_json(['success'=>true,'message'=>'Login successful','redirect'=>dd_url('/link-generator/')]);
