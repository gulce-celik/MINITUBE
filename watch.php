<?php
/*
 * Gülce Celik - CSE348 MINITUBE
 *
 * Single video page: YouTube embed, view count goes up on each page load,
 * badge label comes from SQL CASE (not calculated in PHP). Comments are loaded
 * with one query that uses a self-join so replies stay under the right parent.
 */
require_once 'db.php';
require_once 'layout.php';

$userId = requireUserId();
$ql = userLinks($userId);

if (!isset($_GET['video_id']) || !is_numeric($_GET['video_id'])) {
    die('video_id is required.');
}
$videoId = (int) $_GET['video_id'];

if (isset($_GET['action'])) {
    // extra like/unlike buttons — simple UPDATE on like_count
    if ($_GET['action'] === 'like') {
        $conn->query("UPDATE videos SET like_count = like_count + 1 WHERE video_id = $videoId");
        header('Location: watch.php?' . $ql . '&video_id=' . $videoId);
        exit;
    }
    if ($_GET['action'] === 'unlike') {
        $conn->query("UPDATE videos SET like_count = GREATEST(like_count - 1, 0) WHERE video_id = $videoId");
        header('Location: watch.php?' . $ql . '&video_id=' . $videoId);
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_to_playlist'])) {
    $pid = (int) $_POST['playlist_id'];
    $check = $conn->query("SELECT playlist_id FROM playlists WHERE playlist_id = $pid AND user_id = $userId LIMIT 1");
    if ($check && $check->num_rows === 1) {
        $now = date('Y-m-d H:i:s');
        $conn->query("INSERT IGNORE INTO playlist_videos (playlist_id, video_id, added_at) VALUES ($pid, $videoId, '$now')");
    }
    header('Location: watch.php?' . $ql . '&video_id=' . $videoId);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['new_playlist_title'])) {
    $title = trim($_POST['new_playlist_title']);
    if ($title !== '') {
        $t = esc($conn, $title);
        $d = date('Y-m-d');
        $conn->query("INSERT INTO playlists (user_id, title, created_on) VALUES ($userId, '$t', '$d')");
        $pid = (int) $conn->insert_id;
        if ($pid > 0) {
            $now = date('Y-m-d H:i:s');
            $conn->query("INSERT INTO playlist_videos (playlist_id, video_id, added_at) VALUES ($pid, $videoId, '$now')");
        }
    }
    header('Location: watch.php?' . $ql . '&video_id=' . $videoId);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['comment_body'])) {
    $body = trim($_POST['comment_body']);
    if ($body !== '') {
        $b = esc($conn, $body);
        $now = date('Y-m-d H:i:s');
        $parentSql = 'NULL';
        if (isset($_POST['parent_comment_id']) && is_numeric($_POST['parent_comment_id'])) {
            $parentId = (int) $_POST['parent_comment_id'];
            $check = $conn->query(
                "SELECT comment_id FROM comments
                 WHERE comment_id = $parentId AND video_id = $videoId
                 LIMIT 1"
            );
            if ($check && $check->num_rows === 1) {
                $parentSql = (string) $parentId;
            }
        }
        $conn->query("INSERT INTO comments (video_id, user_id, parent_comment_id, body, posted_at)
                      VALUES ($videoId, $userId, $parentSql, '$b', '$now')");
    }
    header('Location: watch.php?' . $ql . '&video_id=' . $videoId);
    exit;
}

// PDF: increase view_count every time the watch page is opened
$conn->query("UPDATE videos SET view_count = view_count + 1 WHERE video_id = $videoId");

// badge text and CSS class both come from SQL CASE — I do not set them in PHP below
$vidSql = "
SELECT v.video_id, v.title, v.url, v.duration_seconds, v.uploaded_at, v.view_count, v.like_count,
       c.channel_id, c.name AS channel_name,
       owner.country AS uploader_country,
       CASE
           WHEN v.view_count >= 1000 THEN 'Popular'
           WHEN v.view_count >= 100 THEN 'Trending'
           ELSE 'New'
       END AS badge,
       CASE
           WHEN v.view_count >= 1000 THEN 'badge-popular'
           WHEN v.view_count >= 100 THEN 'badge-trending'
           ELSE 'badge-new'
       END AS badge_class
FROM videos v
JOIN channels c ON v.channel_id = c.channel_id
JOIN users owner ON c.owner_id = owner.user_id
WHERE v.video_id = $videoId
";
$vidRes = $conn->query($vidSql);
if (!$vidRes || $vidRes->num_rows === 0) {
    die('Video not found.');
}
$video = $vidRes->fetch_assoc();

$badgeLabel = (string) $video['badge'];
$badgeClass = (string) $video['badge_class'];

// one SELECT with self-join: top comments first, then replies grouped under them
$comSql = "
SELECT c.comment_id, c.parent_comment_id, c.body, c.posted_at,
       u.full_name,
       parent.comment_id AS parent_ref
FROM comments c
JOIN users u ON c.user_id = u.user_id
LEFT JOIN comments parent ON c.parent_comment_id = parent.comment_id
LEFT JOIN comments topc ON topc.comment_id = IF(
    c.parent_comment_id IS NULL,
    c.comment_id,
    IF(parent.parent_comment_id IS NULL, parent.comment_id, parent.parent_comment_id)
)
WHERE c.video_id = $videoId
ORDER BY topc.posted_at DESC,
         IF(c.parent_comment_id IS NULL, 0, 1) ASC,
         c.posted_at ASC
";
$comRes = $conn->query($comSql);

$comments = array();
if ($comRes) {
    while ($row = $comRes->fetch_assoc()) {
        $comments[] = $row;
    }
}
$commentTotal = count($comments);

function commentDepth($commentId, $parentById)
{
    $depth = 0;
    $id = $commentId;
    while (isset($parentById[$id]) && $parentById[$id] !== null && $parentById[$id] !== '' && $parentById[$id] !== '0') {
        $id = (int) $parentById[$id];
        $depth++;
        if ($depth > 20) {
            break;
        }
    }
    return $depth;
}

$parentById = array();
foreach ($comments as $row) {
    $parentById[(int) $row['comment_id']] = $row['parent_comment_id'];
}

$plRes = $conn->query("SELECT playlist_id, title FROM playlists WHERE user_id = $userId ORDER BY title ASC");

function youtubeEmbed($url)
{
    if (preg_match('/(?:v=|youtu\.be\/|embed\/)([a-zA-Z0-9_-]{11})/', $url, $m)) {
        return 'https://www.youtube.com/embed/' . $m[1] . '?rel=0';
    }
    return $url;
}

$nav = navLink('feed.php?' . $ql, 'Home')
     . navLink('playlists.php?' . $ql, 'Playlists')
     . navLink('channel.php?' . $ql . '&channel_id=' . $video['channel_id'], 'Channel')
     . navLink('sql.php?' . $ql, 'SQL');

renderPageStart(htmlspecialchars($video['title']) . ' - MINITUBE');
renderSiteHeader('Watch', $ql, $nav);
?>

<div class="wrap">
    <div class="card">
        <div class="player-wrap">
            <iframe src="<?php echo htmlspecialchars(youtubeEmbed($video['url'])); ?>" allowfullscreen referrerpolicy="strict-origin-when-cross-origin" title="<?php echo htmlspecialchars($video['title']); ?>"></iframe>
        </div>
        <h2 class="watch-title"><?php echo htmlspecialchars($video['title']); ?></h2>
        <p>
            Channel:
            <a href="channel.php?<?php echo $ql; ?>&channel_id=<?php echo $video['channel_id']; ?>">
                <?php echo htmlspecialchars($video['channel_name']); ?>
            </a>
            &middot; <?php echo htmlspecialchars($video['uploader_country']); ?>
        </p>
        <div class="watch-stats">
            <span>Duration <?php echo formatDuration($video['duration_seconds']); ?></span>
            <span>Uploaded <?php echo htmlspecialchars($video['uploaded_at']); ?></span>
            <span class="watch-metric"><strong><?php echo (int) $video['view_count']; ?></strong> views</span>
            <span class="watch-metric watch-metric-likes"><strong><?php echo (int) $video['like_count']; ?></strong> likes</span>
            <span class="watch-like-actions">
                <a class="btn btn-small" href="watch.php?<?php echo $ql; ?>&amp;video_id=<?php echo $videoId; ?>&amp;action=like">Like</a>
                <?php if ((int) $video['like_count'] > 0): ?>
                    <a class="btn btn-small btn-secondary" href="watch.php?<?php echo $ql; ?>&amp;video_id=<?php echo $videoId; ?>&amp;action=unlike">Unlike</a>
                <?php endif; ?>
            </span>
            <span class="badge <?php echo $badgeClass; ?>"><?php echo htmlspecialchars($badgeLabel); ?></span>
        </div>
        <div class="watch-playlist">
            <strong>Add to playlist</strong>
            <?php if ($plRes && $plRes->num_rows > 0): ?>
                <form method="post" action="watch.php?<?php echo $ql; ?>&amp;video_id=<?php echo $videoId; ?>" class="toolbar-form">
                    <select name="playlist_id" required>
                        <?php while ($pl = $plRes->fetch_assoc()): ?>
                            <option value="<?php echo (int) $pl['playlist_id']; ?>"><?php echo htmlspecialchars($pl['title']); ?></option>
                        <?php endwhile; ?>
                    </select>
                    <button type="submit" name="add_to_playlist" value="1" class="btn btn-small">Add</button>
                </form>
            <?php endif; ?>
            <form method="post" action="watch.php?<?php echo $ql; ?>&amp;video_id=<?php echo $videoId; ?>" class="toolbar-form">
                <input type="text" name="new_playlist_title" maxlength="100" placeholder="New playlist name" required>
                <button type="submit" class="btn btn-small btn-secondary">Create &amp; add</button>
            </form>
            <p class="meta"><a href="playlists.php?<?php echo $ql; ?>">My playlists</a></p>
        </div>
    </div>

    <div class="card">
        <h2>Comments (<?php echo $commentTotal; ?>)</h2>
        <?php if ($commentTotal === 0): ?>
            <p class="empty-msg">No comments yet. Be the first!</p>
        <?php else: ?>
            <?php foreach ($comments as $c): ?>
                <?php
                $cid = (int) $c['comment_id'];
                $depth = commentDepth($cid, $parentById);
                $isReply = ($depth > 0);
                ?>
                <div class="comment<?php echo $isReply ? ' reply' : ''; ?>" id="comment-<?php echo $cid; ?>" style="margin-left:<?php echo (int) ($depth * 28); ?>px">
                    <span class="comment-author"><?php echo htmlspecialchars($c['full_name']); ?></span>
                    <span class="meta"><?php echo htmlspecialchars($c['posted_at']); ?></span>
                    <p><?php echo htmlspecialchars($c['body']); ?></p>
                    <form method="post" class="comment-reply-form" action="watch.php?<?php echo $ql; ?>&video_id=<?php echo $videoId; ?>">
                        <input type="hidden" name="parent_comment_id" value="<?php echo $cid; ?>">
                        <textarea name="comment_body" rows="2" required placeholder="Write a reply..."></textarea>
                        <button type="submit" class="btn btn-small">Reply</button>
                    </form>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <div class="card">
        <h2>Write a comment</h2>
        <form method="post" action="watch.php?<?php echo $ql; ?>&video_id=<?php echo $videoId; ?>">
            <div class="form-row">
                <textarea name="comment_body" required placeholder="Say something..."></textarea>
            </div>
            <button type="submit" class="btn">Post</button>
        </form>
    </div>
</div>

<?php renderPageEnd(); ?>
