<?php
session_start();

$DB = [
    'host' => 'localhost',
    'port' => '5432',
    'name' => 'equipment_db',
    'user' => 'postgres',
    'pass' => '1234'
];

set_time_limit(0);
const BACKUP_DIR = __DIR__ . '/backups';
if (!is_dir(BACKUP_DIR)) mkdir(BACKUP_DIR, 0777, true);

function db(): PDO {
    global $DB;
    static $p;
    if (!$p) $p = new PDO("pgsql:host={$DB['host']};port={$DB['port']};dbname={$DB['name']}", $DB['user'], $DB['pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => true]);
    return $p;
}
function e($s) { return htmlspecialchars((string)$s, ENT_QUOTES); }
function flash($t, $m) { $_SESSION['flash'][] = [$t, $m]; }
function go($page) { header('Location: index.php?page=' . $page); exit; }
function dberr(Throwable $x) { return preg_match('/ERROR:\s*([^\n]+)/', $x->getMessage(), $m) ? trim($m[1]) : $x->getMessage(); }
function pgtool($exe) {
    $d = glob('C:/Program Files/PostgreSQL/*/bin', GLOB_ONLYDIR) ?: [];
    usort($d, 'strnatcmp');
    return $d ? end($d) . '/' . $exe . '.exe' : $exe;
}
function conn_args() { global $DB; return ['-w', '-h', $DB['host'], '-p', $DB['port'], '-U', $DB['user']]; }
function runcmd(array $cmd) {
    global $DB;
    $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, array_merge(getenv(), ['PGPASSWORD' => $DB['pass']]));
    if (!is_resource($p)) return [1, '', 'Could not start ' . $cmd[0] . '. Check the PostgreSQL bin folder and that proc_open is enabled.'];
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    return [proc_close($p), $out, $err];
}
function bfile($n) {
    $n = basename((string)$n);
    $p = BACKUP_DIR . '/' . $n;
    return preg_match('/^[\w.\-]+\.sql$/', $n) && is_file($p) ? $p : null;
}
function verify($f) {
    $h = fopen($f, 'r');
    $r = ['size' => filesize($f), 'tables' => 0, 'copies' => 0, 'complete' => false];
    while (($l = fgets($h)) !== false) {
        if (strncmp($l, 'CREATE TABLE', 12) === 0) $r['tables']++;
        elseif (strncmp($l, 'COPY ', 5) === 0) $r['copies']++;
        elseif (strpos($l, 'PostgreSQL database dump complete') !== false) $r['complete'] = true;
    }
    fclose($h);
    return $r;
}
function size($b) { return $b >= 1048576 ? round($b / 1048576, 1) . ' MB' : round($b / 1024, 1) . ' KB'; }

$status = ['Active', 'Returned', 'Overdue'];
$E = [
    'borrowers' => [
        'title' => 'Borrowers', 'pk' => 'borrower_id', 'proc' => 'borrower', 'hint' => 'Search by borrower number or name',
        'fields' => [
            ['n' => 'borrower_no', 'l' => 'Borrower no.', 't' => 'text'],
            ['n' => 'full_name', 'l' => 'Full name', 't' => 'text'],
            ['n' => 'borrower_type', 'l' => 'Type', 't' => 'select', 'o' => ['Student', 'Faculty', 'Staff']],
        ],
        'cols' => ['borrower_id' => 'ID', 'borrower_no' => 'Borrower no.', 'full_name' => 'Full name', 'borrower_type' => 'Type'],
        'where' => 'borrower_no ILIKE :like OR full_name ILIKE :like',
        'sql' => 'SELECT * FROM borrowers {W} ORDER BY borrower_id DESC {L}',
    ],
    'equipment' => [
        'title' => 'Equipment', 'pk' => 'equipment_id', 'proc' => 'equipment', 'hint' => 'Search by property number or name',
        'fields' => [
            ['n' => 'property_no', 'l' => 'Property no.', 't' => 'text'],
            ['n' => 'equipment_name', 'l' => 'Equipment name', 't' => 'text'],
            ['n' => 'quantity_available', 'l' => 'Quantity available', 't' => 'number'],
        ],
        'cols' => ['equipment_id' => 'ID', 'property_no' => 'Property no.', 'equipment_name' => 'Name', 'quantity_available' => 'Available'],
        'where' => 'property_no ILIKE :like OR equipment_name ILIKE :like',
        'sql' => 'SELECT * FROM equipment {W} ORDER BY equipment_id DESC {L}',
    ],
    'transactions' => [
        'title' => 'Transactions', 'pk' => 'borrow_id', 'proc' => 'transaction', 'hint' => 'Search by transaction ID or status',
        'fields' => [
            ['n' => 'borrower_id', 'l' => 'Borrower', 't' => 'lookup', 'ins' => 1,
             'q' => 'SELECT borrower_id, full_name FROM borrowers ORDER BY full_name LIMIT 2000'],
            ['n' => 'equipment_id', 'l' => 'Equipment', 't' => 'lookup', 'ins' => 1,
             'q' => "SELECT equipment_id, equipment_name || ' (' || property_no || ')' FROM equipment ORDER BY equipment_name, property_no LIMIT 2000"],
            ['n' => 'quantity', 'l' => 'Quantity', 't' => 'number', 'ins' => 1],
            ['n' => 'borrow_date', 'l' => 'Borrow date', 't' => 'date', 'ins' => 1],
            ['n' => 'due_date', 'l' => 'Due date', 't' => 'date'],
            ['n' => 'status', 'l' => 'Status', 't' => 'select', 'o' => $status],
        ],
        'cols' => ['borrow_id' => 'ID', 'full_name' => 'Borrower', 'items' => 'Items', 'borrow_date' => 'Borrowed', 'due_date' => 'Due', 'status' => 'Status'],
        'where' => 'status ILIKE :like OR borrow_id::text = :q',
        'sql' => 'SELECT t.*, b.full_name,
                    (SELECT string_agg(e.equipment_name || \' x\' || i.quantity, \', \') FROM borrow_items i JOIN equipment e USING (equipment_id) WHERE i.borrow_id = t.borrow_id) AS items
                  FROM (SELECT * FROM borrow_transactions {W} ORDER BY borrow_id DESC {L}) t
                  JOIN borrowers b USING (borrower_id) ORDER BY t.borrow_id DESC',
    ],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['action'] ?? '';
    $page = $_POST['page'] ?? 'borrowers';

    if (in_array($act, ['save', 'delete']) && isset($E[$page])) {
        $ent = $E[$page];
        $pdo = null;
        try {
            $pdo = db();
            $id = (int)($_POST['id'] ?? 0);
            $pdo->beginTransaction();
            if ($act === 'delete') {
                $pdo->prepare("CALL sp_delete_{$ent['proc']}(?)")->execute([$id]);
                $msg = $ent['title'] . " #$id deleted.";
            } else {
                $vals = [];
                foreach ($ent['fields'] as $f) {
                    if ($id && !empty($f['ins'])) continue;
                    $v = trim($_POST[$f['n']] ?? '');
                    $vals[] = $v === '' ? null : $v;
                }
                if ($id) {
                    $pdo->prepare("CALL sp_update_{$ent['proc']}(" . implode(',', array_fill(0, count($vals) + 1, '?')) . ')')->execute(array_merge([$id], $vals));
                    $msg = rtrim($ent['title'], 's') . " #$id updated.";
                } else {
                    $pdo->prepare("CALL sp_insert_{$ent['proc']}(" . implode(',', array_fill(0, count($vals), '?')) . ')')->execute($vals);
                    $msg = rtrim($ent['title'], 's') . ' added.';
                }
            }
            $pdo->commit();
            flash('ok', $msg);
        } catch (Throwable $x) {
            if ($pdo && $pdo->inTransaction()) $pdo->rollBack();
            flash('err', dberr($x));
        }
        go($page);
    }

    if ($act === 'backup') {
        $f = BACKUP_DIR . '/' . $DB['name'] . '_' . date('Ymd_His') . '.sql';
        [$c, , $err] = runcmd(array_merge([pgtool('pg_dump')], conn_args(), ['-d', $DB['name'], '--clean', '--if-exists', '--no-owner', '--no-privileges', '-f', $f]));
        if ($c === 0 && is_file($f)) {
            $v = verify($f);
            $_SESSION['autodl'] = basename($f);
            flash($v['complete'] && $v['tables'] ? 'ok' : 'err', 'Backup saved as ' . basename($f) . ' (' . size($v['size']) . ', ' . $v['tables'] . ' tables, ' . $v['copies'] . ' data blocks). Verification: ' . ($v['complete'] ? 'complete.' : 'incomplete.'));
        } else {
            @unlink($f);
            flash('err', 'Backup failed: ' . trim($err ?: 'pg_dump returned code ' . $c));
        }
        go('backup');
    }

    if ($act === 'verify') {
        $f = bfile($_POST['file'] ?? '');
        if ($f) { $v = verify($f); flash($v['complete'] ? 'ok' : 'err', basename($f) . ': ' . size($v['size']) . ', ' . $v['tables'] . ' tables, ' . $v['copies'] . ' data blocks, ' . ($v['complete'] ? 'dump finished correctly.' : 'no completion marker found; the file may be cut off.')); }
        else flash('err', 'Backup file not found.');
        go('backup');
    }

    if ($act === 'delbackup') {
        $f = bfile($_POST['file'] ?? '');
        flash($f && unlink($f) ? 'ok' : 'err', $f ? 'Backup file deleted.' : 'Backup file not found.');
        go('backup');
    }

    if ($act === 'restore') {
        $src = null;
        $up = $_FILES['sqlfile'] ?? null;
        if ($up && $up['error'] === UPLOAD_ERR_OK) {
            if (strtolower(pathinfo($up['name'], PATHINFO_EXTENSION)) !== 'sql') flash('err', 'Choose a .sql file.');
            else $src = $up['tmp_name'];
        } elseif ($up && $up['error'] !== UPLOAD_ERR_NO_FILE) {
            flash('err', 'Upload failed (code ' . $up['error'] . '). The file is probably larger than upload_max_filesize or post_max_size in php.ini. Copy it into the backups folder and restore it from the list instead.');
        } elseif (!empty($_POST['saved'])) {
            $src = bfile($_POST['saved']);
            if (!$src) flash('err', 'Backup file not found.');
        } else flash('err', 'Choose a .sql file to restore.');

        if ($src) {
            if (strpos(file_get_contents($src, false, null, 0, 4096), 'PostgreSQL database dump') === false) flash('err', 'This does not look like a pg_dump file, so it was not restored.');
            else {
                [$c, , $err] = runcmd(array_merge([pgtool('psql'), '-q'], conn_args(), ['-d', $DB['name'], '-v', 'ON_ERROR_STOP=1', '--single-transaction', '-f', $src]));
                flash($c === 0 ? 'ok' : 'err', $c === 0 ? 'Restore finished. ' . $DB['name'] . ' now matches the selected backup.' : 'Restore failed and was rolled back, so the database is unchanged: ' . trim($err));
            }
        }
        go('backup');
    }
}

if (isset($_GET['dl']) && ($f = bfile($_GET['dl']))) {
    header('Content-Type: application/sql');
    header('Content-Disposition: attachment; filename="' . basename($f) . '"');
    header('Content-Length: ' . filesize($f));
    readfile($f);
    exit;
}

$nav = ['borrowers' => 'Borrowers', 'equipment' => 'Equipment', 'transactions' => 'Transactions', 'backup' => 'Backup and Restore'];
$page = isset($nav[$_GET['page'] ?? '']) ? $_GET['page'] : 'borrowers';
$flash = $_SESSION['flash'] ?? [];
unset($_SESSION['flash']);
$autodl = $_SESSION['autodl'] ?? null;
unset($_SESSION['autodl']);
$connErr = null;
try { $pdo = db(); } catch (Throwable $x) { $connErr = dberr($x); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Equipment</title>
<link rel="stylesheet" href="style.css?v=<?= filemtime(__DIR__ . '/style.css') ?>">
</head>
<body>
<aside class="side">
    <h2 class="brand">Equipment</h2>
    <nav>
        <?php foreach ($nav as $k => $v): ?><a href="?page=<?= $k ?>" class="<?= $k === $page ? 'on' : '' ?>"><?= e($v) ?></a><?php endforeach; ?>
    </nav>
</aside>
<main>
<?php foreach ($flash as [$t, $m]): ?><div class="msg <?= $t ?>"><?= e($m) ?></div><?php endforeach; ?>
<?php if ($connErr): ?>
    <div class="msg err">Cannot connect: <?= e($connErr) ?>. Check the credentials at the top of index.php and that extension=pdo_pgsql is enabled in php.ini.</div>
<?php elseif (isset($E[$page])):
    $ent = $E[$page];
    $q = trim($_GET['q'] ?? '');
    $pg = max(1, (int)($_GET['p'] ?? 1));
    $sql = str_replace(['{W}', '{L}'], [$q === '' ? '' : 'WHERE ' . $ent['where'], 'LIMIT 11 OFFSET ' . (($pg - 1) * 10)], $ent['sql']);
    $par = [];
    if ($q !== '') { $par[':like'] = "%$q%"; if (strpos($ent['where'], ':q') !== false) $par[':q'] = $q; }
    try { $st = $pdo->prepare($sql); $st->execute($par); $rows = $st->fetchAll(); }
    catch (Throwable $x) { $rows = []; echo '<div class="msg err">' . e(dberr($x)) . '</div>'; }
    $more = count($rows) > 10;
    $rows = array_slice($rows, 0, 10);
    $tbl = $page === 'transactions' ? 'borrow_transactions' : $page;
    $est = (int)$pdo->query('SELECT reltuples::bigint FROM pg_class WHERE relname = ' . $pdo->quote($tbl))->fetchColumn(); ?>
    <header class="pagehead"><h1><?= e($ent['title']) ?></h1><span class="count"><?= number_format($est) ?> records</span></header>
    <p class="lead">Add, edit and delete run through the stored procedures sp_insert_<?= $ent['proc'] ?>, sp_update_<?= $ent['proc'] ?> and sp_delete_<?= $ent['proc'] ?> inside a transaction.</p>
    <form method="post" id="entForm" class="panel" novalidate>
        <h2 class="ptitle" id="formTitle">Add <?= e(strtolower(rtrim($ent['title'], 's'))) ?></h2>
        <input type="hidden" name="action" value="save"><input type="hidden" name="page" value="<?= $page ?>"><input type="hidden" name="id" id="rowId" value="">
        <div class="grid">
        <?php foreach ($ent['fields'] as $f): ?>
            <label class="<?= !empty($f['ins']) ? 'ins' : '' ?>"><?= e($f['l']) ?>
                <?php if ($f['t'] === 'select' || $f['t'] === 'lookup'):
                    $opts = $f['t'] === 'lookup' ? $pdo->query($f['q'])->fetchAll(PDO::FETCH_KEY_PAIR) : array_combine($f['o'], $f['o']); ?>
                    <select name="<?= $f['n'] ?>" required><option value="">Choose <?= e(strtolower($f['l'])) ?>...</option><?php foreach ($opts as $v => $l): ?><option value="<?= e($v) ?>"><?= e($l) ?></option><?php endforeach; ?></select>
                <?php else: ?>
                    <input type="<?= $f['t'] ?>" name="<?= $f['n'] ?>" <?= $f['t'] === 'number' ? 'min="0" step="1"' : '' ?> required>
                <?php endif; ?>
            </label>
        <?php endforeach; ?>
        </div>
        <div class="actions"><button class="btn" id="saveBtn">Add <?= e(strtolower(rtrim($ent['title'], 's'))) ?></button><button type="button" class="btn ghost" id="cancelEdit" hidden>Cancel edit</button></div>
        <p class="formerr" id="formErr" hidden></p>
    </form>
    <form method="get" class="search"><input type="hidden" name="page" value="<?= $page ?>"><input type="search" name="q" value="<?= e($q) ?>" placeholder="<?= e($ent['hint']) ?>"><button class="btn ghost">Search</button><?php if ($q !== ''): ?><a class="clear" href="?page=<?= $page ?>">Clear</a><?php endif; ?></form>
    <div class="tablewrap"><table>
        <thead><tr><?php foreach ($ent['cols'] as $l): ?><th><?= e($l) ?></th><?php endforeach; ?><th></th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
            <tr>
                <?php foreach ($ent['cols'] as $k => $l): ?><td><?= $k === 'status' ? '<span class="tag ' . strtolower(e($r[$k])) . '">' . e($r[$k]) . '</span>' : e($r[$k]) ?></td><?php endforeach; ?>
                <td class="end">
                    <button type="button" class="link edit" data-row="<?= e(json_encode($r)) ?>" data-id="<?= $r[$ent['pk']] ?>">Edit</button>
                    <form method="post" class="inline" data-confirm="Delete <?= e(strtolower(rtrim($ent['title'], 's'))) ?> #<?= $r[$ent['pk']] ?>?"><input type="hidden" name="action" value="delete"><input type="hidden" name="page" value="<?= $page ?>"><input type="hidden" name="id" value="<?= $r[$ent['pk']] ?>"><button class="link danger">Delete</button></form>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="<?= count($ent['cols']) + 1 ?>" class="empty">Nothing found. Add a record above or clear the search.</td></tr><?php endif; ?>
        </tbody>
    </table></div>
    <div class="pager">
        <?php if ($pg > 1): ?><a class="btn ghost sm" href="?page=<?= $page ?>&q=<?= urlencode($q) ?>&p=<?= $pg - 1 ?>">Previous</a><?php endif; ?>
        <span>Page <?= $pg ?></span>
        <?php if ($more): ?><a class="btn ghost sm" href="?page=<?= $page ?>&q=<?= urlencode($q) ?>&p=<?= $pg + 1 ?>">Next</a><?php endif; ?>
    </div>

<?php else:
    $files = glob(BACKUP_DIR . '/*.sql') ?: [];
    rsort($files); ?>
    <header class="pagehead"><h1>Backup and Restore</h1><span class="count"><?= count($files) ?> saved</span></header>
    <p class="lead">Backups are plain SQL files made by pg_dump and saved in the backups folder next to this page. Restoring runs the chosen file on <?= e($DB['name']) ?> itself, replacing its tables, and rolls back completely if anything fails.</p>
    <div class="two">
        <form method="post" class="panel" data-busy="Running pg_dump. Large databases take a moment.">
            <h2>Create backup</h2><input type="hidden" name="action" value="backup">
            <p>Saves <?= e($DB['name']) ?> to a new .sql file, checks the file, then downloads it to your computer.</p>
            <button class="btn">Back up now</button>
        </form>
        <form method="post" enctype="multipart/form-data" class="panel" data-confirm="Restore into <?= e($DB['name']) ?>? Current tables will be replaced with the contents of the file." data-busy="Restoring the database.">
            <h2>Restore from a file</h2><input type="hidden" name="action" value="restore">
            <p>Choose a .sql backup from your computer.</p>
            <input type="file" name="sqlfile" accept=".sql" required>
            <button class="btn">Restore selected file</button>
        </form>
    </div>
    <h2>Saved backups</h2>
    <div class="tablewrap"><table>
        <thead><tr><th>File</th><th class="n">Size</th><th>Created</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($files as $f): $n = basename($f); ?>
            <tr><td><?= e($n) ?></td><td class="n"><?= size(filesize($f)) ?></td><td><?= date('M j, Y g:i A', filemtime($f)) ?></td>
                <td class="end">
                    <form method="post" class="inline"><input type="hidden" name="action" value="verify"><input type="hidden" name="file" value="<?= e($n) ?>"><button class="link">Verify</button></form>
                    <form method="post" class="inline" data-busy="Restoring the database." data-confirm="Restore <?= e($n) ?> into <?= e($DB['name']) ?>? Current tables will be replaced."><input type="hidden" name="action" value="restore"><input type="hidden" name="saved" value="<?= e($n) ?>"><button class="link">Restore</button></form>
                    <form method="post" class="inline" data-confirm="Delete <?= e($n) ?>?"><input type="hidden" name="action" value="delbackup"><input type="hidden" name="file" value="<?= e($n) ?>"><button class="link danger">Delete</button></form>
                </td></tr>
        <?php endforeach; ?>
        <?php if (!$files): ?><tr><td colspan="4" class="empty">No backups yet. Select "Back up now" to create the first one.</td></tr><?php endif; ?>
        </tbody>
    </table></div>
    <?php if ($autodl && bfile($autodl)): ?><iframe src="?dl=<?= urlencode($autodl) ?>" hidden></iframe><?php endif; ?>
<?php endif; ?>
</main>
<div class="busy" id="busy" hidden><div><i></i><p id="busyText">Working...</p></div></div>
<script src="script.js?v=<?= filemtime(__DIR__ . '/script.js') ?>"></script>
</body>
</html>