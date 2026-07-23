<?php
/*
 * Gülce Celik - CSE348 MINITUBE
 *
 * Same header and footer on feed, channel, watch and sql pages so I do not
 * copy the same HTML into every file. renderPageStart prints <head> and
 * renderSiteHeader prints the purple bar with MINITUBE logo and menu links.
 */
function renderPageStart($title)
{
    echo '<!DOCTYPE html><html lang="en"><head>';
    echo '<meta charset="UTF-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>' . htmlspecialchars($title) . '</title>';
    echo '<link rel="stylesheet" href="style.css?v=14">';
    echo '</head><body>';
}

function renderSiteHeader($title, $ql, $extraNavHtml)
{
    echo '<header class="site-header">';
    echo '<div class="header-inner">';
    echo '<a class="logo" href="feed.php?' . htmlspecialchars($ql) . '">MINITUBE</a>';
    echo '<h1 class="page-title">' . $title . '</h1>';
    echo '<nav class="site-nav">' . $extraNavHtml . '</nav>';
    echo '</div></header>';
}

function navLink($href, $label)
{
    return '<a href="' . htmlspecialchars($href) . '">' . htmlspecialchars($label) . '</a>';
}

function renderPageEnd()
{
    echo '<footer class="site-footer"><p>MINITUBE &mdash; Gülce Çelik &mdash; CSE348</p></footer>';
    echo '</body></html>';
}
