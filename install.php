<?php
/*
 * Gülce Celik - CSE348 MINITUBE
 *
 * Runs when the user clicks Initialize Database on index.html.
 * I regenerate seed.sql, drop/create database gulce_celik, create all seven
 * tables, load the seed file, then send the user to login.html.
 */

require_once __DIR__ . '/config.php';

set_time_limit(120);

$conn = new mysqli($db_host, $db_user, $db_pass, '', $db_port);
if ($conn->connect_error) {
    die('Connection failed: ' . $conn->connect_error);
}

require_once __DIR__ . '/generate_data.php';
$seedPath = __DIR__ . '/seed.sql';
generateSeedFile($seedPath);

// start fresh each time install runs
$sql = 'DROP DATABASE IF EXISTS ' . $db_name;
if ($conn->query($sql) === false) {
    die('Error dropping database: ' . $conn->error);
}

$sql = 'CREATE DATABASE ' . $db_name;
if ($conn->query($sql) === false) {
    die('Error creating database: ' . $conn->error);
}

mysqli_select_db($conn, $db_name);

// five core tables + playlists (extra feature for saving videos)
$sql = "
CREATE TABLE users (
    user_id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    user_image VARCHAR(500) NOT NULL,
    full_name VARCHAR(100) NOT NULL,
    email VARCHAR(100) NOT NULL,
    country VARCHAR(50) NOT NULL,
    joined_on DATE NOT NULL,
    bio TEXT
);

CREATE TABLE channels (
    channel_id INT AUTO_INCREMENT PRIMARY KEY,
    owner_id INT NOT NULL UNIQUE,
    channel_image VARCHAR(500) NOT NULL,
    name VARCHAR(100) NOT NULL,
    description VARCHAR(500),
    created_on DATE NOT NULL,
    category VARCHAR(50) NOT NULL,
    FOREIGN KEY (owner_id) REFERENCES users(user_id)
);

CREATE TABLE videos (
    video_id INT AUTO_INCREMENT PRIMARY KEY,
    channel_id INT NOT NULL,
    title VARCHAR(200) NOT NULL,
    description TEXT,
    url VARCHAR(500) NOT NULL,
    duration_seconds INT NOT NULL,
    uploaded_at DATETIME NOT NULL,
    view_count INT NOT NULL DEFAULT 0,
    like_count INT NOT NULL DEFAULT 0,
    FOREIGN KEY (channel_id) REFERENCES channels(channel_id)
);

CREATE TABLE subscriptions (
    subscription_id INT AUTO_INCREMENT PRIMARY KEY,
    subscriber_id INT NOT NULL,
    channel_id INT NOT NULL,
    subscribed_at DATETIME NOT NULL,
    FOREIGN KEY (subscriber_id) REFERENCES users(user_id),
    FOREIGN KEY (channel_id) REFERENCES channels(channel_id),
    UNIQUE KEY uniq_sub (subscriber_id, channel_id)
);

CREATE TABLE comments (
    comment_id INT AUTO_INCREMENT PRIMARY KEY,
    video_id INT NOT NULL,
    user_id INT NOT NULL,
    parent_comment_id INT NULL,
    body TEXT NOT NULL,
    posted_at DATETIME NOT NULL,
    FOREIGN KEY (video_id) REFERENCES videos(video_id),
    FOREIGN KEY (user_id) REFERENCES users(user_id),
    FOREIGN KEY (parent_comment_id) REFERENCES comments(comment_id)
);

CREATE TABLE playlists (
    playlist_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    title VARCHAR(100) NOT NULL,
    created_on DATE NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(user_id)
);

CREATE TABLE playlist_videos (
    item_id INT AUTO_INCREMENT PRIMARY KEY,
    playlist_id INT NOT NULL,
    video_id INT NOT NULL,
    added_at DATETIME NOT NULL,
    FOREIGN KEY (playlist_id) REFERENCES playlists(playlist_id),
    FOREIGN KEY (video_id) REFERENCES videos(video_id),
    UNIQUE KEY uniq_pl_vid (playlist_id, video_id)
);
";

if ($conn->multi_query($sql)) {
    while ($conn->more_results() && $conn->next_result()) {
    }
} else {
    die('Error creating tables: ' . $conn->error);
}

$seed = file_get_contents($seedPath);
$statements = preg_split('/;\s*\n/', $seed);
foreach ($statements as $statement) {
    $statement = trim($statement);
    if ($statement === '') {
        continue;
    }
    $lines = explode("\n", $statement);
    $clean = array();
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || strpos($line, '--') === 0) {
            continue;
        }
        $clean[] = $line;
    }
    $statement = implode(' ', $clean);
    if ($statement === '') {
        continue;
    }
    if ($conn->query($statement) === false) {
        die('Error running seed: ' . $conn->error . ' in: ' . substr($statement, 0, 120));
    }
}

header('Location: login.html');
exit;
