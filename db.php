<?php
/*
 * Gülce Celik - CSE348 MINITUBE
 * Shared db connection and helper functions used on most pages.
 * user_id in the url is required by the pdf so i put that in requireUserId().
 */
require_once __DIR__ . '/config.php';
// open connection to gulce_celik database
$conn = new mysqli($db_host, $db_user, $db_pass, $db_name, $db_port);

if ($conn->connect_error) {
    die('Database connection failed: ' . $conn->connect_error);

}
// escape user input before putting it in sql strings
function esc($conn, $str)
{
    return $conn->real_escape_string($str);
}

// read user_id from url, die if it is missing (needed after login)
function requireUserId()
{
    if (!isset($_GET['user_id']) || !is_numeric($_GET['user_id'])) {
        die('user_id is required in the URL.');
    }
    return (int) $_GET['user_id'];
}
// returns "user_id=5" so i can append it to every link and form
function userLinks($userId)
{
    return 'user_id=' . (int) $userId;
}
// convert duration_seconds to something like 3:42 for the video page
function formatDuration($seconds)
{
    $m = floor($seconds / 60);
    $s = $seconds % 60;
    return $m . ':' . sprintf('%02d', $s);
}
// pull youtube video id out of watch?v=... url
function youtubeVideoId($url)
{
    if (preg_match('/(?:v=|youtu\.be\/)([a-zA-Z0-9_-]+)/', $url, $m)) {
        return $m[1];
    }
    return null;
}
// build thumbnail url from youtube video id
function youtubeThumbUrl($url)
{
    $id = youtubeVideoId($url);
    if ($id) {
        return 'https://img.youtube.com/vi/' . $id . '/hqdefault.jpg';
    }
    return null;
}
// skip broken or old avatar urls stored in db
function isBadImageUrl($url)
{
    if ($url === null || trim($url) === '') {
        return true;
    }
    $u = strtolower($url);
    if (strpos($u, 'i.pravatar.cc') !== false) {
        return true;
    }
    if (strpos($u, 'picsum.photos/id/') !== false) {
        return false;
    }
    if (strpos($u, 'ui-avatars.com') !== false) {
        return true;
    }
    return false;
}
// use db url if ok, otherwise pick a fallback avatar image
function resolveStoredImage($url, $label, $channelId = 0)
{
    if (!isBadImageUrl($url)) {
        return $url;
    }
    if ($channelId > 0) {
        return channelAvatarUrl($label, $channelId);
    }
    return userAvatarUrl($label);
}
// thumbnail for feed rows, youtube first then local svg
function videoThumbForFeed($videoUrl, $channelName, $channelId)
{
    $yt = youtubeThumbUrl($videoUrl);
    if ($yt) {
        return $yt;
    }
    return 'assets/placeholder-thumb.svg';
}
// img tag for video thumbnails with extra youtube fallbacks on error
function videoImgTag($videoUrl, $alt, $class = 'thumb-img')
{
    $id = youtubeVideoId($videoUrl);
    $src = videoThumbForFeed($videoUrl, $alt, 0);
    $altEsc = htmlspecialchars($alt);
    $classEsc = htmlspecialchars($class);
    $fb = htmlspecialchars(localPlaceholder('thumb'));
    $ytAttr = $id ? ' data-ytid="' . htmlspecialchars($id) . '"' : '';
    $onerr = "this.onerror=null;var id=this.dataset.ytid;"
        . "if(id&&this.dataset.fallback!=='mq'){this.dataset.fallback='mq';"
        . "this.src='https://img.youtube.com/vi/'+id+'/mqdefault.jpg';return;}"
        . "if(id&&this.dataset.fallback!=='sd'){this.dataset.fallback='sd';"
        . "this.src='https://img.youtube.com/vi/'+id+'/sddefault.jpg';return;}"
        . "this.src='" . $fb . "';";
    return '<img class="' . $classEsc . '" src="' . htmlspecialchars($src) . '" alt="' . $altEsc
        . '" loading="lazy"' . $ytAttr . ' onerror="' . $onerr . '">';
}
// local svg when remote image fails to load
function localPlaceholder($kind)
{
    if ($kind === 'thumb') {
        return 'assets/placeholder-thumb.svg';
    }
    return 'assets/placeholder-avatar.svg';
}
// backup avatar from initials when picsum does not work
function avatarUrl($label, $size = 200, $bg = '2d4a5e')
{
    $name = urlencode($label);
    $size = (int) $size;
    $bg = preg_replace('/[^0-9a-fA-F]/', '', $bg);
    return 'https://ui-avatars.com/api/?name=' . $name . '&size=' . $size
        . '&background=' . $bg . '&color=ffffff&bold=true&format=png';
}
// picsum url for user profile images in seed data
function userImageUrl($userId)
{
    $picId = 10 + (($userId - 1) % 50);
    return 'https://picsum.photos/id/' . $picId . '/200/200';

}
// picsum url for channel banner or avatar
function channelImageUrl($channelId)
{
    $picId = 10 + (($channelId + 15) % 50);
    return 'https://picsum.photos/id/' . $picId . '/300/300';
}
// user avatar helper, prefers picsum when user id is known
function userAvatarUrl($fullName, $userId = 0)
{
    if ($userId > 0) {
        return userImageUrl($userId);
    }
    return avatarUrl($fullName, 200, 'c4302b');
}
// channel avatar helper
function channelAvatarUrl($channelName, $channelId)
{
    return channelImageUrl($channelId);
}
// img tag for profile and channel pictures with onerror fallback
function imgTag($src, $alt, $class, $fallbackLabel = '', $channelId = 0)
{
    $label = $fallbackLabel !== '' ? $fallbackLabel : $alt;
    $src = resolveStoredImage($src, $label, $channelId);
    $kind = ($class === 'thumb-img' || $class === 'table-thumb') ? 'thumb' : 'avatar';
    $fb = htmlspecialchars(localPlaceholder($kind));
    $srcEsc = htmlspecialchars($src);
    $altEsc = htmlspecialchars($alt);
    $classEsc = htmlspecialchars($class);
    return '<img class="' . $classEsc . '" src="' . $srcEsc . '" alt="' . $altEsc
        . '" loading="lazy" onerror="this.onerror=null;this.src=\'' . $fb . '\';">';
}

