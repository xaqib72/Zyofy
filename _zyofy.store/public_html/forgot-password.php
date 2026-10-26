<?php
require_once __DIR__ . '/inc/site.php';
require_once __DIR__ . '/db.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') dd_json(['success'=>false,'message'=>'Method not allowed'],405);
if (!dd_csrf_validate($_POST['csrf_token'] ?? '')) dd_json(['success'=>false,'message'=>'Your session expired. Reload and try again.'],403);
$email=strtolower(trim((string)($_POST['email']??'')));if(!filter_var($email,FILTER_VALIDATE_EMAIL)) dd_json(['success'=>false,'message'=>'Enter a valid email address.'],422);
$generic='If that email exists, a password reset link has been sent.';$stmt=$pdo->prepare('SELECT id FROM users WHERE email=? LIMIT 1');$stmt->execute([$email]);if(!$stmt->fetch()) dd_json(['success'=>true,'message'=>$generic]);
$token=bin2hex(random_bytes(32));$expires=date('Y-m-d H:i:s',time()+3600);$pdo->prepare('DELETE FROM password_resets WHERE email=?')->execute([$email]);$pdo->prepare('INSERT INTO password_resets(email,token,expires_at) VALUES(?,?,?)')->execute([$email,$token,$expires]);
$link=dd_url('/reset-password.php?token='.rawurlencode($token));$subject='Reset your Pickwell password';$message="Hello,\n\nUse this secure link to reset your Pickwell password:\n\n$link\n\nThis link expires in one hour. If you did not request it, you can ignore this email.";$headers='From: Pickwell <'.dd_config('app.support_email','mm4979351@gmail.com').'>';
@mail($email,$subject,$message,$headers);dd_json(['success'=>true,'message'=>$generic]);
