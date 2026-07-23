<?php
/*
 * Gülce Celik - CSE348 MINITUBE
 *
 * Extra feature: user playlists on one page. Click name to expand videos below.
 * Uses playlists + playlist_videos tables (many-to-many with videos).
 */
require_once 'db.php';
require_once 'layout.php';

$userId = requireUserId();
$ql = userLinks($userId);

if (isset($_GET['action'], $_GET['playlist_id']) && is_numeric($_GET['playlist_id'])) {
    $plId = (int) $_GET['playlist_id'];
    $own = $conn->query("SELECT playlist_id FROM playlists WHERE playlist_id = $plId AND user_id = $userId LIMIT 1");
    if ($own && $own->num_rows === 1) {
        if ($_GET['action'] === 'delete') {
            $conn->query("DELETE FROM playlist_videos WHERE playlist_id = $plId");
            $conn->query("DELETE FROM playlists WHERE playlist_id = $plId AND user_id = $userId");
            header('Location: playlists.php?' . $ql);
            exit;
        }
        if ($_GET['action'] === 'remove' && isset($_GET['item_id']) && is_numeric($_GET['item_id'])) {
            $itemId = (int) $_GET['item_id'];
            $conn->query("DELETE FROM playlist_videos WHERE item_id = $itemId AND playlist_id = $plId");
            header('Location: playlists.php?' . $ql);
            exit;
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['playlist_title'])) {
    $title = trim($_POST['playlist_title']);
    if ($title !== '') {
        $t = esc($conn, $title);
        $d = date('Y-m-d');
        $conn->query("INSERT INTO playlists (user_id, title, created_on) VALUES ($userId, '$t', '$d')");
    }
    header('Location: playlists.php?' . $ql);
    exit;
}

$listSql = "
SELECT p.playlist_id, p.title, p.created_on,
       pv.item_id, v.video_id, v.title AS video_title, v.url
FROM playlists p
LEFT JOIN playlist_videos pv ON p.playlist_id = pv.playlist_id
LEFT JOIN videos v ON pv.video_id = v.video_id
WHERE p.user_id = $userId
ORDER BY p.created_on DESC, pv.added_at ASC
";
$listRes = $conn->query($listSql);

$playlists = array();
if ($listRes) {
    while ($row = $listRes->fetch_assoc()) {
        $pid = (int) $row['playlist_id'];
        if (!isset($playlists[$pid])) {
            $playlists[$pid] = array(
                'title' => $row['title'],
                'created_on' => $row['created_on'],
                'videos' => array()
            );
        }
        if ($row['item_id'] !== null) {
            $playlists[$pid]['videos'][] = array(
                'item_id' => (int) $row['item_id'],
                'video_id' => (int) $row['video_id'],
                'title' => $row['video_title'],
                'url' => $row['url']
            );
        }
    }
}

$nav = navLink('feed.php?' . $ql, 'Home') . navLink('playlists.php?' . $ql, 'Playlists') . navLink('sql.php?' . $ql, 'SQL');

renderPageStart('My playlists - MINITUBE');
renderSiteHeader('My playlists', $ql, $nav);
?>
<div class="wrap">
    <div class="card">
        <h2>Create a playlist</h2>
        <form method="post" action="playlists.php?<?php echo $ql; ?>" class="toolbar-form">
            <label for="playlist_title">Title</label>
            <input type="text" id="playlist_title" name="playlist_title" required maxlength="100" placeholder="e.g. Favorites">
            <button type="submit" class="btn btn-small">Create</button>
        </form>
    </div>
    <div class="card">
        <h2>Your playlists</h2>
        <?php if (count($playlists) > 0): ?>
            <ul class="profile-sub-list playlist-list">
                <?php foreach ($playlists as $pid => $pl): ?>
                    <?php $count = count($pl['videos']); ?>
                    <li class="playlist-item">
                        <div class="playlist-row">
                            <details class="playlist-details">
                                <summary>
                                    <?php echo htmlspecialchars($pl['title']); ?>
                                    <span class="meta"><?php echo $count; ?> video<?php echo $count === 1 ? '' : 's'; ?></span>
                                </summary>
                                <?php if ($count > 0): ?>
                                    <ul class="playlist-videos">
                                        <?php foreach ($pl['videos'] as $v): ?>
                                            <li>
                                                <a href="watch.php?<?php echo $ql; ?>&amp;video_id=<?php echo $v['video_id']; ?>">
                                                    <?php echo htmlspecialchars($v['title']); ?>
                                                </a>
                                                <a class="profile-remove" href="playlists.php?<?php echo $ql; ?>&amp;playlist_id=<?php echo $pid; ?>&amp;action=remove&amp;item_id=<?php echo $v['item_id']; ?>">Remove</a>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                <?php else: ?>
                                    <p class="empty-msg playlist-empty">No videos yet. Add some from a watch page.</p>
                                <?php endif; ?>
                            </details>
                            <a class="profile-remove" href="playlists.php?<?php echo $ql; ?>&amp;playlist_id=<?php echo $pid; ?>&amp;action=delete" onclick="return confirm('Delete this playlist?');">Delete playlist</a>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php else: ?>
            <p class="empty-msg">You have no playlists yet.</p>
        <?php endif; ?>
    </div>
</div>
<?php renderPageEnd(); ?>
