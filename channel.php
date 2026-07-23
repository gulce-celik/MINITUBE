<?php
/*
 * Gülce Celik - CSE348 MINITUBE
 *
 * Shows one channel: description, owner, subscriber count, and all its videos.
 * Subscribe adds a row to subscriptions; unsubscribe deletes it. Owner can
 * delete their channel or a single video (extra features I added for testing).
 */
require_once 'db.php';
require_once 'layout.php';

$userId = requireUserId();
$ql = userLinks($userId);

if (!isset($_GET['channel_id']) || !is_numeric($_GET['channel_id'])) {
    die('channel_id is required.');
}
$channelId = (int) $_GET['channel_id'];

if (isset($_GET['action'])) {
    $ownerCheck = $conn->query("SELECT channel_id FROM channels WHERE channel_id = $channelId AND owner_id = $userId LIMIT 1");
    $isOwnerAction = ($ownerCheck && $ownerCheck->num_rows === 1);

    if ($isOwnerAction && $_GET['action'] === 'delete_channel') {
        $vidRes = $conn->query("SELECT video_id FROM videos WHERE channel_id = $channelId");
        if ($vidRes) {
            while ($vRow = $vidRes->fetch_assoc()) {
                $vid = (int) $vRow['video_id'];
                $conn->query("DELETE FROM comments WHERE video_id = $vid");
            }
        }
        $conn->query("DELETE FROM videos WHERE channel_id = $channelId");
        $conn->query("DELETE FROM subscriptions WHERE channel_id = $channelId");
        $conn->query("DELETE FROM channels WHERE channel_id = $channelId AND owner_id = $userId");
        header('Location: feed.php?' . $ql);
        exit;
    }

    if ($isOwnerAction && $_GET['action'] === 'delete_video' && isset($_GET['video_id']) && is_numeric($_GET['video_id'])) {
        $delVid = (int) $_GET['video_id'];
        $vCheck = $conn->query("SELECT video_id FROM videos WHERE video_id = $delVid AND channel_id = $channelId LIMIT 1");
        if ($vCheck && $vCheck->num_rows === 1) {
            $conn->query("DELETE FROM comments WHERE video_id = $delVid");
            $conn->query("DELETE FROM videos WHERE video_id = $delVid AND channel_id = $channelId");
        }
        header('Location: channel.php?' . $ql . '&channel_id=' . $channelId);
        exit;
    }

    if (!$isOwnerAction && $_GET['action'] === 'subscribe') {
        $check = $conn->query("SELECT subscription_id FROM subscriptions WHERE subscriber_id = $userId AND channel_id = $channelId");
        if ($check->num_rows === 0) {
            $now = date('Y-m-d H:i:s');
            $conn->query("INSERT INTO subscriptions (subscriber_id, channel_id, subscribed_at) VALUES ($userId, $channelId, '$now')");
        }
    } elseif (!$isOwnerAction && $_GET['action'] === 'unsubscribe') {
        $conn->query("DELETE FROM subscriptions WHERE subscriber_id = $userId AND channel_id = $channelId");
    }
    header('Location: channel.php?' . $ql . '&channel_id=' . $channelId);
    exit;
}

$chSql = "
SELECT c.channel_id, c.owner_id, c.channel_image, c.name, c.description, c.created_on, c.category,
       owner.full_name AS owner_name, owner.country AS owner_country,
       (SELECT COUNT(*) FROM subscriptions s WHERE s.channel_id = c.channel_id) AS sub_count
FROM channels c
JOIN users owner ON c.owner_id = owner.user_id
WHERE c.channel_id = $channelId
";
$chRes = $conn->query($chSql);
if (!$chRes || $chRes->num_rows === 0) {
    die('Channel not found.');
}
$ch = $chRes->fetch_assoc();

$isOwner = ((int) $ch['owner_id'] === $userId);

$subCheck = $conn->query("SELECT subscription_id FROM subscriptions WHERE subscriber_id = $userId AND channel_id = $channelId");
$isSubscribed = ($subCheck && $subCheck->num_rows > 0);

$desc = $ch['description'];
if ($desc === null || trim($desc) === '') {
    $desc = '(no description)'; // PDF says show this text when description is empty
}
//IF(c.description IS NULL OR TRIM(c.description) = '', '(no description)', c.description) AS description
//SELECT name,
//  CASE
//    WHEN description IS NULL OR TRIM(description) = '' THEN '(no description)'
//    ELSE description
//  END AS description
//FROM channels

$vidSql = "
SELECT video_id, title, url, duration_seconds, uploaded_at, view_count
FROM videos WHERE channel_id = $channelId
ORDER BY uploaded_at DESC
";
$vidRes = $conn->query($vidSql);

$showSubs = false;
$subsRes = null;
if ($isOwner && isset($_GET['show_subscribers']) && $_GET['show_subscribers'] === '1') {
    $showSubs = true;
    $subsSql = "
    SELECT u.full_name, u.username, u.country, s.subscribed_at
    FROM subscriptions s
    JOIN users u ON s.subscriber_id = u.user_id
    WHERE s.channel_id = $channelId
      AND s.subscriber_id <> {$ch['owner_id']}
    ORDER BY s.subscribed_at DESC
    ";
    $subsRes = $conn->query($subsSql);
}

$chBase = 'channel.php?' . $ql . '&channel_id=' . $channelId;

$nav = navLink('feed.php?' . $ql, 'Home')
     . navLink('sql.php?' . $ql, 'SQL');
$title = htmlspecialchars($ch['name']);

renderPageStart($title . ' - MINITUBE');
renderSiteHeader($title, $ql, $nav);
?>
<div class="wrap">
    <div class="card">
        <div class="channel-actions">
            <?php if ($isOwner): ?>
                <button type="button" class="btn btn-secondary" disabled>Your channel</button>
            <?php elseif ($isSubscribed): ?>
                <a class="btn btn-secondary" href="channel.php?<?php echo $ql; ?>&channel_id=<?php echo $channelId; ?>&action=unsubscribe">Unsubscribe</a>
            <?php else: ?>
                <a class="btn" href="channel.php?<?php echo $ql; ?>&channel_id=<?php echo $channelId; ?>&action=subscribe">Subscribe</a>
            <?php endif; ?>
        </div>
        <div class="channel-hero">
            <?php echo imgTag($ch['channel_image'], $ch['name'], 'banner', $ch['name'], $channelId); ?>
            <div class="channel-info">
                <h2 class="channel-title-row">
                    <?php echo $title; ?>
                    <?php if ($isOwner): ?>
                        <a class="channel-delete-link" href="channel.php?<?php echo $ql; ?>&amp;channel_id=<?php echo $channelId; ?>&amp;action=delete_channel" onclick="return confirm('Delete this channel and all its videos?');">Delete channel</a>
                    <?php endif; ?>
                </h2>
                <p class="meta">Category: <?php echo htmlspecialchars($ch['category']); ?></p>
                <p><?php echo htmlspecialchars($desc); ?></p>
                <p class="meta">
                    Owner: <?php echo htmlspecialchars($ch['owner_name']); ?>
                    (<?php echo htmlspecialchars($ch['owner_country']); ?>)
                    &middot; Created <?php echo htmlspecialchars($ch['created_on']); ?>
                    &middot; <strong><?php echo (int) $ch['sub_count']; ?></strong> subscribers
                </p>
                <?php if ($isOwner): ?>
                    <p class="channel-owner-tools">
                        <?php if ($showSubs): ?>
                            <a class="btn btn-small btn-secondary" href="<?php echo htmlspecialchars($chBase); ?>">Hide subscribers</a>
                        <?php else: ?>
                            <a class="btn btn-small btn-secondary" href="<?php echo htmlspecialchars($chBase . '&show_subscribers=1'); ?>">Show subscribers</a>
                        <?php endif; ?>
                    </p>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <?php if ($showSubs): ?>
        <div class="card">
            <h2>Subscribers</h2>
            <?php if ($subsRes && $subsRes->num_rows > 0): ?>
                <table class="video-table sub-table">
                    <tr>
                        <th>Name</th>
                        <th>Username</th>
                        <th>Country</th>
                        <th>Subscribed</th>
                    </tr>
                    <?php while ($sub = $subsRes->fetch_assoc()): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($sub['full_name']); ?></td>
                            <td><?php echo htmlspecialchars($sub['username']); ?></td>
                            <td><?php echo htmlspecialchars($sub['country']); ?></td>
                            <td><?php echo htmlspecialchars($sub['subscribed_at']); ?></td>
                        </tr>
                    <?php endwhile; ?>
                </table>
            <?php else: ?>
                <p class="empty-msg">No subscribers yet.</p>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <div class="card">
        <h2>Videos</h2>
        <?php if ($vidRes && $vidRes->num_rows > 0): ?>
            <table class="video-table">
                <tr>
                    <th></th>
                    <th>Title</th>
                    <th>Duration</th>
                    <th>Uploaded</th>
                    <th>Views</th>
                    <?php if ($isOwner): ?><th></th><?php endif; ?>
                </tr>
                <?php while ($v = $vidRes->fetch_assoc()): ?>
                    <tr>
                        <td class="cell-thumb">
                            <a href="watch.php?<?php echo $ql; ?>&video_id=<?php echo $v['video_id']; ?>">
                                <?php echo videoImgTag($v['url'], $v['title'], 'table-thumb'); ?>
                            </a>
                        </td>
                        <td>
                            <a href="watch.php?<?php echo $ql; ?>&video_id=<?php echo $v['video_id']; ?>">
                                <?php echo htmlspecialchars($v['title']); ?>
                            </a>
                        </td>
                        <td><?php echo formatDuration($v['duration_seconds']); ?></td>
                        <td><?php echo htmlspecialchars($v['uploaded_at']); ?></td>
                        <td><?php echo (int) $v['view_count']; ?></td>
                        <?php if ($isOwner): ?>
                            <td>
                                <a class="channel-delete-link" href="channel.php?<?php echo $ql; ?>&amp;channel_id=<?php echo $channelId; ?>&amp;action=delete_video&amp;video_id=<?php echo (int) $v['video_id']; ?>" onclick="return confirm('Delete this video?');">Delete video</a>
                            </td>
                        <?php endif; ?>
                    </tr>
                <?php endwhile; ?>
            </table>
        <?php else: ?>
            <p class="empty-msg">No videos on this channel.</p>
        <?php endif; ?>
    </div>
</div>
<?php renderPageEnd(); ?>
