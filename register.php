<?php
/*
 * Gülce Celik - CSE348 MINITUBE
 *
 * Extra feature I added: register a new user with INSERT INTO users.
 * After sign-up the new user goes straight to the feed (same as login).
 */
require_once 'db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: register.html');
    exit;
}

$username = isset($_POST['username']) ? trim($_POST['username']) : '';
$password = isset($_POST['password']) ? $_POST['password'] : '';
$fullName = isset($_POST['full_name']) ? trim($_POST['full_name']) : '';
$email = isset($_POST['email']) ? trim($_POST['email']) : '';
$country = isset($_POST['country']) ? trim($_POST['country']) : '';
$bio = isset($_POST['bio']) ? trim($_POST['bio']) : '';

if ($username === '' || $password === '' || $fullName === '' || $email === '' || $country === '') {
    header('Location: register.html?error=empty');
    exit;
}

$u = esc($conn, $username);
$check = $conn->query("SELECT user_id FROM users WHERE username = '$u' LIMIT 1");
if ($check && $check->num_rows > 0) {
    header('Location: register.html?error=user');
    exit;
}
// first non null value coalesce .
$nextRes = $conn->query('SELECT COALESCE(MAX(user_id), 0) + 1 AS next_id FROM users');
$nextRow = $nextRes->fetch_assoc();
$nextId = (int) $nextRow['next_id'];
$img = userImageUrl($nextId);
$joined = date('Y-m-d');

$p = esc($conn, $password);
$fn = esc($conn, $fullName);
$em = esc($conn, $email);
$co = esc($conn, $country);
$bi = esc($conn, $bio);
$im = esc($conn, $img);

$sql = "INSERT INTO users (username, password, user_image, full_name, email, country, joined_on, bio)
        VALUES ('$u', '$p', '$im', '$fn', '$em', '$co', '$joined', '$bi')";

if ($conn->query($sql) === false) {
    header('Location: register.html?error=user');
    exit;
}

$newId = (int) $conn->insert_id;
header('Location: feed.php?user_id=' . $newId);
exit;
