<?php
/*
 * Gülce Celik - CSE348 MINITUBE
 *
 * Lets the logged-in user type any SELECT / INSERT / UPDATE / DELETE query.
 * I show the exact query that ran above the result (PDF requirement).
 * SELECT is limited to 10 rows; other statements print affected row count.
 */
require_once 'db.php';
require_once 'layout.php';

$userId = requireUserId();
$ql = userLinks($userId);

$queryText = '';
$executedQuery = '';
$resultHtml = '';
$message = '';
$queryOk = null;

function sqlErrorMessage($conn, $exception = null)
{
    if ($exception instanceof mysqli_sql_exception) {
        return 'Error: ' . htmlspecialchars($exception->getMessage());
    }
    if ($conn->error !== '') {
        return 'Error: ' . htmlspecialchars($conn->error);
    }
    return 'Error: Invalid SQL query.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['sql_query'])) {
    $queryText = trim($_POST['sql_query']);
    if ($queryText !== '') {
        $first = strtoupper(ltrim($queryText));
        try {
            if (strpos($first, 'SELECT') === 0) {
                $runQuery = $queryText;
                // PDF says max 10 rows for SELECT — I add LIMIT if user forgot
                if (stripos($runQuery, 'LIMIT') === false) {
                    $runQuery = rtrim($runQuery, ';') . ' LIMIT 10';
                }
                $executedQuery = $runQuery;
                $res = $conn->query($runQuery);
                if ($res === false) {
                    $message = sqlErrorMessage($conn);
                    $queryOk = false;
                } elseif ($res->num_rows > 0) {
                    $queryOk = true;
                    $resultHtml .= '<table class="result"><tr>';
                    foreach ($res->fetch_fields() as $f) {
                        $resultHtml .= '<th>' . htmlspecialchars($f->name) . '</th>';
                    }
                    $resultHtml .= '</tr>';
                    while ($row = $res->fetch_assoc()) {
                        $resultHtml .= '<tr>';
                        foreach ($row as $val) {
                            $resultHtml .= '<td>' . htmlspecialchars((string) $val) . '</td>';
                        }
                        $resultHtml .= '</tr>';
                    }
                    $resultHtml .= '</table>';
                } else {
                    $message = 'Query returned 0 rows.';
                    $queryOk = true;
                }
            } elseif (strpos($first, 'INSERT') === 0 || strpos($first, 'UPDATE') === 0 || strpos($first, 'DELETE') === 0) {
                $executedQuery = $queryText;
                if ($conn->query($queryText) === false) {
                    $message = sqlErrorMessage($conn);
                    $queryOk = false;
                } else {
                    $message = 'Affected rows: ' . $conn->affected_rows;
                    $queryOk = true;
                }
            } else {
                $executedQuery = $queryText;
                $message = 'Error: Only SELECT, INSERT, UPDATE, DELETE are allowed.';
                $queryOk = false;
            }
        } catch (mysqli_sql_exception $e) {
            $executedQuery = ($executedQuery !== '') ? $executedQuery : $queryText;
            $message = sqlErrorMessage($conn, $e);
            $queryOk = false;
        }
    }
}

$nav = navLink('feed.php?' . $ql, 'Home');

renderPageStart('SQL - MINITUBE');
renderSiteHeader('SQL Console', $ql, $nav);
?>
<div class="wrap">
    <div class="card">
        <h2>Run a query</h2>
        <form method="post" action="sql.php?<?php echo $ql; ?>">
            <div class="form-row">
                <textarea id="sql_query" name="sql_query" placeholder="SELECT * FROM users LIMIT 5"><?php echo htmlspecialchars($queryText); ?></textarea>
            </div>
            <button type="submit" class="btn">Execute</button>
        </form>
    </div>

    <?php
    $queryEcho = ($executedQuery !== '') ? $executedQuery : $queryText;
    if ($queryEcho !== '' && $queryOk !== null):
        $echoClass = $queryOk ? 'query-echo-ok' : 'query-echo-err';
    ?>
        <div class="query-echo <?php echo $echoClass; ?>"><?php echo htmlspecialchars($queryEcho); ?></div>
    <?php endif; ?>

    <?php if ($message !== ''): ?>
        <div class="card msg-box"><?php echo $message; ?></div>
    <?php endif; ?>

    <?php if ($resultHtml !== ''): ?>
        <div class="card"><?php echo $resultHtml; ?></div>
    <?php endif; ?>
</div>
<?php renderPageEnd(); ?>
