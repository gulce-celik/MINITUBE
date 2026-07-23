<?php
/*
 * Gülce Celik - CSE348 MINITUBE
 *
 * login.php receives the form from login.html, checks username and password
 * in the users table, and redirects to feed.php?user_id=... if they match.
 */
require_once 'db.php';

$user = isset($_POST['username']) ? trim($_POST['username']) : '';
$pass = isset($_POST['password']) ? $_POST['password'] : '';

if ($user === '' || $pass === '') {
    header('Location: login.html?error=1');
    exit;
}

$u = esc($conn, $user);
$p = esc($conn, $pass);

$sql = "SELECT user_id, full_name FROM users WHERE username = '$u' AND password = '$p' LIMIT 1";
$result = $conn->query($sql);

if ($result && $result->num_rows === 1) {
    $row = $result->fetch_assoc();
    $uid = $row['user_id'];
    header('Location: feed.php?user_id=' . $uid);
    exit;
}

header('Location: login.html?error=1');
exit;
