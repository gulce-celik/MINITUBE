<?php
/*
 * Gülce Celik - CSE348 MINITUBE
 *
 * Reads the txt files in data/ and writes seed.sql with INSERT statements.
 * I run this from install.php before loading data into MySQL. Counts follow
 * the minimums in the PDF (100 users, 50 channels, 200 videos, etc.).
 */

function sqlStr($s)
{
    return "'" . addslashes($s) . "'";
}

function loadLines($path)
{
    if (!file_exists($path)) {
        return array();
    }
    return file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
}

function pick($arr, $index)
{
    if (count($arr) === 0) {
        return '';
    }
    return $arr[$index % count($arr)];
}

function userImageUrl($username, $userId)
{
    $picId = 10 + (($userId - 1) % 50);
    return 'https://picsum.photos/id/' . $picId . '/200/200';
}

function channelImageUrl($channelId, $channelName)
{
    $picId = 10 + (($channelId + 15) % 50);
    return 'https://picsum.photos/id/' . $picId . '/300/300';
}

function spreadVideoId($index, $videoCount)
{
    return ((($index - 1) * 17 + 3) % $videoCount) + 1;
}

function loadYouTubeCatalog($path)
{
    $byCategory = array();
    $lines = loadLines($path);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || strpos($line, '#') === 0) {
            continue;
        }
        $parts = explode('|', $line, 5);
        if (count($parts) < 5) {
            continue;
        }
        $cat = trim($parts[0]);
        $ytId = trim($parts[1]);
        $entry = array(
            'id' => $ytId,
            'title' => trim($parts[2]),
            'duration' => (int) trim($parts[3]),
            'description' => trim($parts[4]),
        );
        if ($entry['duration'] <= 0) {
            $entry['duration'] = 180;
        }
        if (!isset($byCategory[$cat])) {
            $byCategory[$cat] = array();
        }
        foreach ($byCategory[$cat] as $existing) {
            if ($existing['id'] === $ytId) {
                continue 2;
            }
        }
        $byCategory[$cat][] = $entry;
    }
    return $byCategory;
}

function flattenPoolUnique($pool)
{
    $flat = array();
    $seen = array();
    foreach ($pool as $cat => $entries) {
        foreach ($entries as $entry) {
            if (isset($seen[$entry['id']])) {
                continue;
            }
            $seen[$entry['id']] = true;
            $flat[] = $entry;
        }
    }
    return $flat;
}

function assignVideosGlobally($pool, $channelCount, $videosPerChannel)
{
    $flat = flattenPoolUnique($pool);
    $totalVideos = $channelCount * $videosPerChannel;
    if (count($flat) < $totalVideos) {
        die('Not enough unique embeddable YouTube videos in data/youtube_pool.txt (need '
            . $totalVideos . ', have ' . count($flat) . ').');
    }

    $assignments = array();
    $idx = 0;
    for ($chId = 1; $chId <= $channelCount; $chId++) {
        for ($slot = 0; $slot < $videosPerChannel; $slot++) {
            $assignments[] = array('channel_id' => $chId, 'entry' => $flat[$idx++]);
        }
    }
    return $assignments;
}

function generateSeedFile($outputPath = 'seed.sql')
{
    $dataDir = __DIR__ . '/data/';
    $firstNames = loadLines($dataDir . 'first_names.txt');
    $lastNames = loadLines($dataDir . 'last_names.txt');
    $countries = loadLines($dataDir . 'countries.txt');
    $categories = loadLines($dataDir . 'categories.txt');
    $channelNames = loadLines($dataDir . 'channel_names.txt');
    $bios = loadLines($dataDir . 'bios.txt');
    $commentsTop = loadLines($dataDir . 'comments_top.txt');
    $commentsReply = loadLines($dataDir . 'comments_reply.txt');
    $ytPool = loadYouTubeCatalog($dataDir . 'youtube_pool.txt');

    $userCount = 100;
    $channelCount = 50;
    $videoCount = 200;
    $subscriptionCount = 120;
    $commentCount = 280;
    $replyCount = 50;

    if (!$firstNames || !$lastNames) {
        die('Input text files missing in data/ folder.');
    }

    if (count($ytPool) === 0) {
        die('youtube_pool.txt missing or empty in data/ folder.');
    }
    if (count($channelNames) < $channelCount) {
        die('channel_names.txt needs at least ' . $channelCount . ' lines.');
    }

    $lines = array();
    $lines[] = '-- Gülce Celik - seed data for gulce_celik';
    $lines[] = 'SET FOREIGN_KEY_CHECKS=0;';

    mt_srand(348);

    // insert 100 users — names come from first_names.txt and last_names.txt
    for ($i = 1; $i <= $userCount; $i++) {
        $fn = pick($firstNames, $i - 1);
        $ln = pick($lastNames, $i * 3);
        $username = strtolower(preg_replace('/[^a-z]/i', '', $fn)) . $i;
        $password = 'pass' . ($i % 10);
        $fullName = $fn . ' ' . $ln;
        $email = $username . '@mail.com';
        $country = pick($countries, $i);
        $year = 2018 + ($i % 7);
        $month = ($i % 12) + 1;
        $day = ($i % 28) + 1;
        $joined = sprintf('%04d-%02d-%02d', $year, $month, $day);
        $bio = pick($bios, $i - 1);
        if ($bio === '') {
            $bio = 'Hi, I am ' . $fullName . ' from ' . $country . '.';
        }
        $img = userImageUrl($username, $i);
        $lines[] = 'INSERT INTO users VALUES (' . $i . ', ' . sqlStr($username) . ', ' . sqlStr($password)
            . ', ' . sqlStr($img) . ', ' . sqlStr($fullName) . ', ' . sqlStr($email) . ', '
            . sqlStr($country) . ', ' . sqlStr($joined) . ', ' . sqlStr($bio) . ');';
    }

    // 50 channels — first 50 users each own one channel (owner_id is unique)
    for ($c = 1; $c <= $channelCount; $c++) {
        $ownerId = $c;
        $cat = pick($categories, $c);
        $cname = pick($channelNames, $c - 1);
        if ($c % 7 === 0) {
            $descSql = 'NULL';
        } else {
            $descSql = sqlStr('Welcome to ' . $cname . '! New videos every week. Thanks for subscribing.');
        }
        $created = sprintf('2020-%02d-%02d', ($c % 12) + 1, ($c % 28) + 1);
        $cimg = channelImageUrl($c, $cname);
        $lines[] = 'INSERT INTO channels VALUES (' . $c . ', ' . $ownerId . ', ' . sqlStr($cimg) . ', '
            . sqlStr($cname) . ', ' . $descSql . ', ' . sqlStr($created) . ', ' . sqlStr($cat) . ');';
    }

    // 200 videos — real YouTube titles/urls from youtube_pool.txt, no duplicate ids
    $videosPerChannel = (int) ($videoCount / $channelCount);
    $videoAssignments = assignVideosGlobally($ytPool, $channelCount, $videosPerChannel);
    $videoId = 0;

    foreach ($videoAssignments as $assignment) {
        $videoId++;
        $chId = $assignment['channel_id'];
        $ytEntry = $assignment['entry'];

            $title = $ytEntry['title'];
            $desc = $ytEntry['description'];
            $url = 'https://www.youtube.com/watch?v=' . $ytEntry['id'];
            $dur = $ytEntry['duration'];
            $uploadDay = ($videoId % 28) + 1;
            $uploadMonth = ($videoId % 12) + 1;
            $uploadYear = 2023 + ($videoId % 3);
            $uploaded = sprintf('%04d-%02d-%02d %02d:%02d:00', $uploadYear, $uploadMonth, $uploadDay, ($videoId % 24), ($videoId * 3) % 60);
            $views = 20 + ($videoId * 13) % 5000;
            $likes = ($videoId * 7) % 800;
            $lines[] = 'INSERT INTO videos VALUES (' . $videoId . ', ' . $chId . ', ' . sqlStr($title) . ', '
                . sqlStr($desc) . ', ' . sqlStr($url) . ', ' . $dur . ', ' . sqlStr($uploaded) . ', '
                . $views . ', ' . $likes . ');';
    }

    // at least 120 subscription rows; some channels get more subs for the Top 5 list
    $channelSubTargets = array(
        38, 31, 24, 19, 15, 3, 3, 2, 2, 2, 2, 2, 2, 1, 1, 1, 1, 1, 1, 1,
        1, 1, 1, 1, 1, 1, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0,
        0, 0, 0, 0, 0, 0, 0, 0, 0, 0
    );
    $extraIdx = 5;
    while (array_sum($channelSubTargets) < $subscriptionCount) {
        $channelSubTargets[$extraIdx]++;
        $extraIdx++;
        if ($extraIdx >= $channelCount) {
            $extraIdx = 5;
        }
    }

    $subId = 1;
    $usedPairs = array();
    for ($cid = 1; $cid <= $channelCount; $cid++) {
        $need = $channelSubTargets[$cid - 1];
        for ($k = 0; $k < $need; $k++) {
            $uid = (($cid + $k - 1) % $userCount) + 1;
            // skip if user would subscribe to a channel they own
            if ($uid === $cid) {
                $uid = ($cid % $userCount) + 1;
                if ($uid === $cid) {
                    $uid = (($cid + 1) % $userCount) + 1;
                }
            }
            $key = $uid . '_' . $cid;
            if (isset($usedPairs[$key])) {
                continue;
            }
            $usedPairs[$key] = true;
            $subDate = sprintf(
                '2024-%02d-%02d %02d:00:00',
                ($subId % 12) + 1,
                ($subId % 28) + 1,
                ($subId % 24)
            );
            $lines[] = 'INSERT INTO subscriptions VALUES (' . $subId . ', ' . $uid . ', ' . $cid . ', '
                . sqlStr($subDate) . ');';
            $subId++;
        }
    }

    // ahmet1 (user 1) needs extra subs or the demo feed looks empty after login
    $demoChannels = array(2, 3, 4, 5, 6, 7, 8, 9, 10);
    foreach ($demoChannels as $dc) {
        $key = '1_' . $dc;
        if (isset($usedPairs[$key])) {
            continue;
        }
        $usedPairs[$key] = true;
        $subDate = sprintf('2024-%02d-%02d %02d:00:00', ($subId % 12) + 1, ($subId % 28) + 1, ($subId % 24));
        $lines[] = 'INSERT INTO subscriptions VALUES (' . $subId . ', 1, ' . $dc . ', ' . sqlStr($subDate) . ');';
        $subId++;
    }

    // 280 comments total; 50 have parent_comment_id set so they show as replies
    $topLevel = $commentCount - $replyCount;
    $commentVideo = array();
    for ($cm = 1; $cm <= $topLevel; $cm++) {
        if ($cm <= $videoCount) {
            $vid = $cm;
        } else {
            $vid = spreadVideoId($cm, $videoCount);
        }
        $commentVideo[$cm] = $vid;
        $uid = (($cm * 5) % $userCount) + 1;
        $body = pick($commentsTop, $cm - 1);
        if ($body === '') {
            $body = 'Great video, really enjoyed it!';
        }
        $posted = sprintf('2025-%02d-%02d %02d:%02d:00', ($cm % 12) + 1, ($cm % 28) + 1, ($cm % 24), ($cm * 2) % 60);
        $lines[] = 'INSERT INTO comments VALUES (' . $cm . ', ' . $vid . ', ' . $uid . ', NULL, '
            . sqlStr($body) . ', ' . sqlStr($posted) . ');';
    }

    for ($r = 1; $r <= $replyCount; $r++) {
        $cmId = $topLevel + $r;
        // parent_comment_id points to the top-level comment this reply belongs to
        $parentId = ($r * 4) - 1;
        if ($parentId > $topLevel) {
            continue;
        }
        $vid = $commentVideo[$parentId];
        $uid = (($cmId * 7) % $userCount) + 1;
        $body = pick($commentsReply, $r - 1);
        if ($body === '') {
            $body = 'Thanks, that helps!';
        }
        $posted = sprintf('2025-%02d-%02d %02d:%02d:00', (($cmId + 3) % 12) + 1, (($cmId + 5) % 28) + 1, ($cmId % 24), ($cmId * 4) % 60);
        $lines[] = 'INSERT INTO comments VALUES (' . $cmId . ', ' . $vid . ', ' . $uid . ', ' . $parentId . ', '
            . sqlStr($body) . ', ' . sqlStr($posted) . ');';
    }

    // sample playlists (user 1 and 2) — many-to-many with videos via playlist_videos
    $lines[] = 'INSERT INTO playlists VALUES (1, 1, ' . sqlStr('Favorites') . ', ' . sqlStr('2024-06-01') . ');';
    $lines[] = 'INSERT INTO playlists VALUES (2, 1, ' . sqlStr('Watch later') . ', ' . sqlStr('2024-07-15') . ');';
    $lines[] = 'INSERT INTO playlists VALUES (3, 2, ' . sqlStr('Music picks') . ', ' . sqlStr('2024-08-01') . ');';
    $lines[] = 'INSERT INTO playlist_videos VALUES (1, 1, 1, ' . sqlStr('2024-06-02 10:00:00') . ');';
    $lines[] = 'INSERT INTO playlist_videos VALUES (2, 1, 2, ' . sqlStr('2024-06-02 10:05:00') . ');';
    $lines[] = 'INSERT INTO playlist_videos VALUES (3, 1, 5, ' . sqlStr('2024-06-03 11:00:00') . ');';
    $lines[] = 'INSERT INTO playlist_videos VALUES (4, 2, 3, ' . sqlStr('2024-07-16 09:00:00') . ');';
    $lines[] = 'INSERT INTO playlist_videos VALUES (5, 2, 7, ' . sqlStr('2024-07-16 09:10:00') . ');';
    $lines[] = 'INSERT INTO playlist_videos VALUES (6, 3, 8, ' . sqlStr('2024-08-02 14:00:00') . ');';

    $lines[] = 'SET FOREIGN_KEY_CHECKS=1;';

    file_put_contents($outputPath, implode("\n", $lines) . "\n");
    return $outputPath;
}

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'])) {
    $path = generateSeedFile(__DIR__ . '/seed.sql');
    echo "Created $path\n";
    echo "Users: 100, Channels: 50, Videos: 200, Subscriptions: 120, Comments: 280 (50 replies)\n";
}
