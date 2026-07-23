<?php
/*
 * Gülce Celik - CSE348 MINITUBE
 *
 * Main home page after login. Left side shows videos from channels I subscribe to
 * (JOIN subscriptions + videos). Right side has Top 5 channels by sub count and
 * my profile. I also added search by title and browse channels by category here.
 */
require_once 'db.php';
require_once 'layout.php';

$userId = requireUserId(); // requireUserId() is a function that reads user_id from the URL and returns it as an integer
$ql = userLinks($userId); // userLinks() is a function that returns a string with the user_id in the URL

$sort = isset($_GET['sort']) ? $_GET['sort'] : 'latest';
$sortOrders = array(
    'latest' => 'v.uploaded_at DESC',
    'views' => 'v.view_count DESC, v.uploaded_at DESC',
    'likes' => 'v.like_count DESC, v.uploaded_at DESC',
    'short' => 'v.duration_seconds ASC, v.uploaded_at DESC',
    'long' => 'v.duration_seconds DESC, v.uploaded_at DESC',
);
if (!isset($sortOrders[$sort])) {
    $sort = 'latest';
}

$searchQ = isset($_GET['q']) ? trim($_GET['q']) : '';
$selectedCategory = isset($_GET['category']) ? trim($_GET['category']) : '';

$feedExtraQ = '';
if ($sort !== 'latest') {
    $feedExtraQ .= '&sort=' . urlencode($sort);
}
if ($searchQ !== '') {
    $feedExtraQ .= '&q=' . urlencode($searchQ);
}
if ($selectedCategory !== '') {
    $feedExtraQ .= '&category=' . urlencode($selectedCategory);
}

if (isset($_GET['action'], $_GET['channel_id']) && is_numeric($_GET['channel_id'])) {
    $actionCid = (int) $_GET['channel_id'];
    if ($_GET['action'] === 'unsub') {
        $conn->query("DELETE FROM subscriptions WHERE subscriber_id = $userId AND channel_id = $actionCid");
    }
    header('Location: feed.php?' . $ql . $feedExtraQ);
    exit;
}

// remove from profile list if someone somehow subscribed to their own channel
$conn->query("
    DELETE s FROM subscriptions s
    INNER JOIN channels c ON s.channel_id = c.channel_id
    WHERE s.subscriber_id = $userId AND c.owner_id = $userId
");

$userRes = $conn->query("SELECT full_name, country, joined_on, user_image, bio FROM users WHERE user_id = $userId");
$user = $userRes->fetch_assoc();

$pageTitle = 'Hello, ' . htmlspecialchars($user['full_name']) . '!'; // PDF wants this greeting on the feed

$orderBy = $sortOrders[$sort];

$myChRes = $conn->query("SELECT channel_id, name FROM channels WHERE owner_id = $userId LIMIT 1");
$myChannel = ($myChRes && $myChRes->num_rows > 0) ? $myChRes->fetch_assoc() : null;

$subListSql = "
SELECT c.channel_id, c.name
FROM subscriptions s
JOIN channels c ON s.channel_id = c.channel_id
WHERE s.subscriber_id = $userId
  AND c.owner_id <> $userId
ORDER BY c.name ASC
";
$subListRes = $conn->query($subListSql);

$feedSql = "
SELECT v.video_id, v.title, v.url, v.duration_seconds, v.uploaded_at,
       c.channel_id, c.name AS channel_name, c.channel_image,
       owner.country AS uploader_country,
       DATEDIFF(CURDATE(), DATE(v.uploaded_at)) AS days_ago
FROM videos v
JOIN channels c ON v.channel_id = c.channel_id
JOIN users owner ON c.owner_id = owner.user_id
JOIN subscriptions s ON s.channel_id = c.channel_id
WHERE s.subscriber_id = $userId
  AND c.owner_id <> $userId
ORDER BY $orderBy
";
$feedRes = $conn->query($feedSql);

$feedRows = array();
if ($feedRes) {
    while ($row = $feedRes->fetch_assoc()) {
        $feedRows[] = $row;
    }
}
$feedCount = count($feedRows);

$catListRes = $conn->query('SELECT DISTINCT category FROM channels ORDER BY category');
$categories = array();
if ($catListRes) {
    while ($catRow = $catListRes->fetch_assoc()) {
        $categories[] = $catRow['category'];
    }
}

$searchRows = array();
if ($searchQ !== '') {
    $eq = esc($conn, $searchQ);
    // LIKE search — extra feature, not required by PDF but uses simple SQL
    $searchSql = "
    SELECT v.video_id, v.title, v.url, v.duration_seconds, v.uploaded_at,
           v.view_count, v.like_count,
           c.channel_id, c.name AS channel_name, c.channel_image
    FROM videos v
    JOIN channels c ON v.channel_id = c.channel_id
    WHERE v.title LIKE '%$eq%'
    ORDER BY v.uploaded_at DESC
    LIMIT 20
    ";
    $searchRes = $conn->query($searchSql);
    if ($searchRes) {
        while ($row = $searchRes->fetch_assoc()) {
            $searchRows[] = $row;
        }
    }
}
$searchCount = count($searchRows);

$channelBrowseRows = array();
if ($selectedCategory !== '' && in_array($selectedCategory, $categories, true)) {
    $ec = esc($conn, $selectedCategory);
    // WHERE category = ... — browse channels by category from the dropdown
    $browseSql = "
    SELECT c.channel_id, c.name, c.category, c.channel_image,
           (SELECT COUNT(*) FROM subscriptions s WHERE s.channel_id = c.channel_id) AS sub_count
    FROM channels c
    WHERE c.category = '$ec'
    ORDER BY c.name ASC
    ";
    $browseRes = $conn->query($browseSql);
    if ($browseRes) {
        while ($row = $browseRes->fetch_assoc()) {
            $channelBrowseRows[] = $row;
        }
    }
}
$channelBrowseCount = count($channelBrowseRows);

$sortOnlyQ = ($sort !== 'latest') ? '&sort=' . urlencode($sort) : '';
$feedBase = 'feed.php?' . $ql . $sortOnlyQ;

// PDF: five channels with the most subscribers (GROUP BY + COUNT)
$topSql = "
SELECT c.channel_id, c.name, c.channel_image, COUNT(s.subscription_id) AS sub_count
FROM channels c
LEFT JOIN subscriptions s ON c.channel_id = s.channel_id
GROUP BY c.channel_id, c.name, c.channel_image
ORDER BY sub_count DESC
LIMIT 5
";
$topRes = $conn->query($topSql);

$nav = navLink('feed.php?' . $ql, 'Home')
     . navLink('playlists.php?' . $ql, 'Playlists')
     . navLink('sql.php?' . $ql, 'SQL')
     . navLink('login.html', 'Logout');

renderPageStart($pageTitle);
renderSiteHeader($pageTitle, $ql, $nav);
?>
<div class="wrap grid-feed">
    <div class="col-main">
        <div class="card">
            <div class="feed-toolbar">
                <form class="toolbar-form" method="get" action="feed.php">
                    <input type="hidden" name="user_id" value="<?php echo (int) $userId; ?>">
                    <?php if ($sort !== 'latest'): ?>
                        <input type="hidden" name="sort" value="<?php echo htmlspecialchars($sort); ?>">
                    <?php endif; ?>
                    <label for="feed-search">Find a video</label>
                    <input id="feed-search" type="search" name="q" value="<?php echo htmlspecialchars($searchQ); ?>" placeholder="Search titles...">
                    <button type="submit" class="btn btn-small">Search</button>
                    <?php if ($searchQ !== ''): ?>
                        <a class="feed-clear" href="<?php echo htmlspecialchars($feedBase); ?>">Clear</a>
                    <?php endif; ?>
                </form>
                <form class="toolbar-form" method="get" action="feed.php">
                    <input type="hidden" name="user_id" value="<?php echo (int) $userId; ?>">
                    <?php if ($sort !== 'latest'): ?>
                        <input type="hidden" name="sort" value="<?php echo htmlspecialchars($sort); ?>">
                    <?php endif; ?>
                    <label for="feed-category">Find a channel</label>
                    <select id="feed-category" name="category" onchange="this.form.submit()">
                        <option value="">Choose a category...</option>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?php echo htmlspecialchars($cat); ?>"<?php echo $selectedCategory === $cat ? ' selected' : ''; ?>>
                                <?php echo htmlspecialchars($cat); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if ($selectedCategory !== ''): ?>
                        <a class="feed-clear" href="<?php echo htmlspecialchars($feedBase); ?>">Clear</a>
                    <?php endif; ?>
                </form>
            </div>

            <?php if ($searchQ !== ''): ?>
                <div class="feed-results">
                    <div class="feed-results-head">
                        <h3>Video search<?php if ($searchCount > 0): ?> &mdash; <?php echo $searchCount; ?> result<?php echo $searchCount === 1 ? '' : 's'; ?><?php endif; ?></h3>
                        <a class="feed-clear" href="<?php echo htmlspecialchars($feedBase); ?>">Close</a>
                    </div>
                    <?php if ($searchCount > 0): ?>
                        <table class="video-table">
                            <tr>
                                <th></th>
                                <th>Title</th>
                                <th>Channel</th>
                                <th>Views</th>
                            </tr>
                            <?php foreach ($searchRows as $row): ?>
                                <tr>
                                    <td class="cell-thumb">
                                        <a href="watch.php?<?php echo $ql; ?>&video_id=<?php echo (int) $row['video_id']; ?>">
                                            <?php echo videoImgTag($row['url'], $row['title'], 'table-thumb'); ?>
                                        </a>
                                    </td>
                                    <td>
                                        <a href="watch.php?<?php echo $ql; ?>&video_id=<?php echo (int) $row['video_id']; ?>">
                                            <?php echo htmlspecialchars($row['title']); ?>
                                        </a>
                                    </td>
                                    <td>
                                        <a href="channel.php?<?php echo $ql; ?>&channel_id=<?php echo (int) $row['channel_id']; ?>">
                                            <?php echo htmlspecialchars($row['channel_name']); ?>
                                        </a>
                                    </td>
                                    <td><?php echo (int) $row['view_count']; ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </table>
                    <?php else: ?>
                        <p class="empty-msg">No videos found for &ldquo;<?php echo htmlspecialchars($searchQ); ?>&rdquo;.</p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <?php if ($selectedCategory !== ''): ?>
                <div class="feed-results">
                    <div class="feed-results-head">
                        <h3><?php echo htmlspecialchars($selectedCategory); ?> channels<?php if ($channelBrowseCount > 0): ?> &mdash; <?php echo $channelBrowseCount; ?><?php endif; ?></h3>
                        <a class="feed-clear" href="<?php echo htmlspecialchars($feedBase); ?>">Close</a>
                    </div>
                    <?php if ($channelBrowseCount > 0): ?>
                        <ul class="rank-list channel-browse-list">
                            <?php foreach ($channelBrowseRows as $ch): ?>
                                <li>
                                    <a href="channel.php?<?php echo $ql; ?>&channel_id=<?php echo (int) $ch['channel_id']; ?>">
                                        <?php echo imgTag($ch['channel_image'], $ch['name'], 'rank-thumb', $ch['name'], (int) $ch['channel_id']); ?>
                                    </a>
                                    <div>
                                        <a href="channel.php?<?php echo $ql; ?>&channel_id=<?php echo (int) $ch['channel_id']; ?>">
                                            <?php echo htmlspecialchars($ch['name']); ?>
                                        </a>
                                        <div class="meta"><?php echo (int) $ch['sub_count']; ?> subscribers</div>
                                    </div>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php else: ?>
                        <p class="empty-msg">No channels in this category.</p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <div class="feed-head">
                <h2>Latest from your subscriptions<?php if ($feedCount > 0): ?><span class="feed-count"> &mdash; <?php echo $feedCount; ?> video<?php echo $feedCount === 1 ? '' : 's'; ?></span><?php endif; ?></h2>
                <form class="feed-sort" method="get" action="feed.php">
                    <input type="hidden" name="user_id" value="<?php echo (int) $userId; ?>">
                    <?php if ($searchQ !== ''): ?>
                        <input type="hidden" name="q" value="<?php echo htmlspecialchars($searchQ); ?>">
                    <?php endif; ?>
                    <?php if ($selectedCategory !== ''): ?>
                        <input type="hidden" name="category" value="<?php echo htmlspecialchars($selectedCategory); ?>">
                    <?php endif; ?>
                    <label for="feed-sort">Sort</label>
                    <select id="feed-sort" name="sort" onchange="this.form.submit()">
                        <option value="latest"<?php echo $sort === 'latest' ? ' selected' : ''; ?>>Latest</option>
                        <option value="views"<?php echo $sort === 'views' ? ' selected' : ''; ?>>Most views</option>
                        <option value="likes"<?php echo $sort === 'likes' ? ' selected' : ''; ?>>Most likes</option>
                        <option value="short"<?php echo $sort === 'short' ? ' selected' : ''; ?>>Shortest</option>
                        <option value="long"<?php echo $sort === 'long' ? ' selected' : ''; ?>>Longest</option>
                    </select>
                </form>
            </div>
                <?php if ($feedCount > 0): ?>
                    <div class="feed-grid">
                    <?php
                    $feedNum = 1;
                    foreach ($feedRows as $row):
                    ?>
                        <div class="feed-item">
                        <span class="feed-num"><?php echo $feedNum++; ?></span>
                        <a class="thumb-link" href="watch.php?<?php echo $ql; ?>&video_id=<?php echo $row['video_id']; ?>">
                            <span class="thumb-wrap">
                                <?php echo videoImgTag($row['url'], $row['title'], 'thumb-img'); ?>
                                <span class="thumb-play" aria-hidden="true">&#9654;</span>
                                <span class="thumb-dur"><?php echo formatDuration($row['duration_seconds']); ?></span>
                            </span>
                        </a>
                        <div class="feed-body">
                            <a class="feed-title" href="watch.php?<?php echo $ql; ?>&video_id=<?php echo $row['video_id']; ?>">
                                <?php echo htmlspecialchars($row['title']); ?>
                            </a>
                            <div class="channel-line">
                                <a href="channel.php?<?php echo $ql; ?>&channel_id=<?php echo $row['channel_id']; ?>">
                                    <?php echo imgTag($row['channel_image'], $row['channel_name'], 'channel-mini', $row['channel_name'], $row['channel_id']); ?>
                                    <?php echo htmlspecialchars($row['channel_name']); ?>
                                </a>
                            </div>
                            <div class="meta">
                                <?php echo htmlspecialchars($row['uploader_country']); ?>
                                &middot; <?php echo (int) $row['days_ago']; ?> days ago
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    </div>
                <?php else: ?>
                <p class="empty-msg">No subscribed videos yet. Visit a channel and click Subscribe.</p>
            <?php endif; ?>
        </div>
    </div>
    <div class="col-side">
        <div class="card">
            <h2>Top 5 channels</h2>
            <ul class="rank-list">
                <?php $rank = 1; while ($t = $topRes->fetch_assoc()): ?>
                    <li>
                        <span class="rank-num"><?php echo $rank++; ?></span>
                        <a href="channel.php?<?php echo $ql; ?>&channel_id=<?php echo $t['channel_id']; ?>">
                            <?php echo imgTag($t['channel_image'], $t['name'], 'rank-thumb', $t['name'], $t['channel_id']); ?>
                        </a>
                        <div>
                            <a href="channel.php?<?php echo $ql; ?>&channel_id=<?php echo $t['channel_id']; ?>">
                                <?php echo htmlspecialchars($t['name']); ?>
                            </a>
                            <div class="meta"><?php echo (int) $t['sub_count']; ?> subscribers</div>
                        </div>
                    </li>
                <?php endwhile; ?>
            </ul>
        </div>
        <div class="card profile-card">
            <h2 class="profile-heading">Your profile</h2>
            <div class="profile-stack">
                <?php echo imgTag($user['user_image'], $user['full_name'], 'profile-img', $user['full_name']); ?>
                <div class="profile-details">
                    <p class="profile-name"><?php echo htmlspecialchars($user['full_name']); ?></p>
                    <p class="meta"><?php echo htmlspecialchars($user['country']); ?></p>
                    <p class="meta">Joined <?php echo htmlspecialchars($user['joined_on']); ?></p>
                    <?php if ($user['bio']): ?>
                        <p class="profile-bio"><?php echo htmlspecialchars($user['bio']); ?></p>
                    <?php endif; ?>
                    <?php if ($myChannel): ?>
                        <p class="profile-extra">
                            <strong>My channel:</strong>
                            <a href="channel.php?<?php echo $ql; ?>&channel_id=<?php echo (int) $myChannel['channel_id']; ?>">
                                <?php echo htmlspecialchars($myChannel['name']); ?>
                            </a>
                        </p>
                    <?php endif; ?>
                    <p class="profile-extra">
                        <a href="playlists.php?<?php echo $ql; ?>">My playlists</a>
                    </p>
                </div>
            </div>
            <h3 class="profile-sub-heading">Your subscriptions</h3>
            <?php if ($subListRes && $subListRes->num_rows > 0): ?>
                <ul class="profile-sub-list">
                    <?php while ($subRow = $subListRes->fetch_assoc()): ?>
                        <li>
                            <a href="channel.php?<?php echo $ql; ?>&channel_id=<?php echo (int) $subRow['channel_id']; ?>">
                                <?php echo htmlspecialchars($subRow['name']); ?>
                            </a>
                            <a class="profile-remove" href="feed.php?<?php echo $ql . $feedExtraQ; ?>&amp;action=unsub&amp;channel_id=<?php echo (int) $subRow['channel_id']; ?>">Remove</a>
                        </li>
                    <?php endwhile; ?>
                </ul>
            <?php else: ?>
                <p class="meta profile-sub-empty">No subscriptions yet.</p>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php renderPageEnd(); ?>
