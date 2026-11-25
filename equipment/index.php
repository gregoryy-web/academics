<?php
session_start();

$DB = [
    'host' => 'localhost',
    'port' => '5432',
    'name' => 'equipment_db',
    'user' => 'postgres',
    'pass' => '1234'
];
$PG_BIN     = '';
$PER_PAGE   = 20;
$BACKUP_DIR = __DIR__ . DIRECTORY_SEPARATOR . 'backups';

$T = [
    'borrowers' => [
        'title' => 'Borrowers',
        'pk' => 'borrower_id',
        'show' => 'full_name',
        'proc' => ['create' => 'sp_insert_borrower', 'update' => 'sp_update_borrower', 'delete' => 'sp_delete_borrower'],
        'cols' => [
            'borrower_no'   => ['Borrower no.', 'text'],
            'full_name'     => ['Full name', 'text'],
            'borrower_type' => ['Type', 'text']
        ]
    ],
    'equipment' => [
        'title' => 'Equipment',
        'pk' => 'equipment_id',
        'show' => 'equipment_name',
        'proc' => ['create' => 'sp_insert_equipment', 'update' => 'sp_update_equipment', 'delete' => 'sp_delete_equipment'],
        'cols' => [
            'property_no'        => ['Property no.', 'text'],
            'equipment_name'     => ['Equipment name', 'text'],
            'quantity_available' => ['Qty available', 'number']
        ]
    ],
    'borrow_transactions' => [
        'title' => 'Transactions',
        'pk' => 'borrow_id',
        'show' => 'borrow_id',
        'proc' => ['create' => 'sp_insert_borrow', 'update' => 'sp_update_borrow', 'delete' => 'sp_delete_borrow'],
        'cols' => [
            'borrower_id'  => ['Borrower', 'fk', 'borrowers'],
            'equipment_id' => ['Equipment', 'fk', 'equipment'],
            'borrow_date'  => ['Borrow date', 'date'],
            'due_date'     => ['Due date', 'date'],
            'status'       => ['Status', 'select', ['Active', 'Returned', 'Overdue']]
        ]
    ]
];

function h($s)
{
    return htmlspecialchars((string)$s, ENT_QUOTES);
}

function clean_error($e)
{
    $msg = $e->getMessage();
    $state = ($e instanceof PDOException && isset($e->errorInfo[0])) ? $e->errorInfo[0] : '';
    if ($state === '42883' && stripos($msg, 'procedure') !== false) {
        return 'Stored procedure not found. Run sql/03_stored_procedures.sql in pgAdmin first.';
    }
    if ($state === '23503') return 'This record is still used by another table, so it cannot be deleted or changed.';
    if (preg_match('/ERROR:\s+([^\n]+)/', $msg, $m)) return trim($m[1]);
    return $msg;
}

function pg_tool($tool)
{
    global $PG_BIN;
    $win = stripos(PHP_OS, 'WIN') === 0;
    $exe = $tool . ($win ? '.exe' : '');
    $list = [];
    if ($PG_BIN !== '') $list[] = rtrim($PG_BIN, '/\\') . DIRECTORY_SEPARATOR . $exe;
    if ($win) {
        $found = glob('C:\\Program Files\\PostgreSQL\\*\\bin\\' . $exe) ?: [];
        natsort($found);
        foreach (array_reverse($found) as $f) $list[] = $f;
    }
    foreach (explode(PATH_SEPARATOR, getenv('PATH') ?: '') as $p) {
        if ($p !== '') $list[] = rtrim($p, '/\\') . DIRECTORY_SEPARATOR . $exe;
    }
    foreach ($list as $f) if (is_file($f)) return $f;
    return null;
}

function run_cmd($cmd, $env = [])
{
    $err = tempnam(sys_get_temp_dir(), 'pge');
    $p = proc_open($cmd, [1 => ['file', $err . '.out', 'w'], 2 => ['file', $err, 'w']], $pipes, null, array_merge(getenv(), $env));
    if (!is_resource($p)) return [-1, 'Could not start the process.'];
    $code = proc_close($p);
    $text = trim((string)file_get_contents($err));
    @unlink($err);
    @unlink($err . '.out');
    return [$code, $text];
}

function size_text($b)
{
    $u = ['B', 'KB', 'MB', 'GB'];
    $i = 0;
    $b = (float)$b;
    while ($b >= 1024 && $i < 3) {
        $b /= 1024;
        $i++;
    }
    return ($i ? number_format($b, 1) : (int)$b) . ' ' . $u[$i];
}

function backup_name_ok($n)
{
    return (bool)preg_match('/^[\w\-]+_\d{8}_\d{6}\.sql$/', $n);
}

function check_backup($path)
{
    $size = (int)filesize($path);
    if ($size === 0) return [false, 'File is empty'];
    $fh = fopen($path, 'rb');
    $head = (string)fread($fh, 4096);
    fseek($fh, max(0, $size - 4096));
    $tail = (string)fread($fh, 4096);
    fclose($fh);
    if (stripos($head, 'PostgreSQL database dump') === false) return [false, 'Header missing'];
    if (stripos($tail, 'PostgreSQL database dump complete') === false) return [false, 'Dump incomplete'];
    return [true, 'Valid'];
}

try {
    $pdo = new PDO(
        "pgsql:host={$DB['host']};port={$DB['port']};dbname={$DB['name']}",
        $DB['user'],
        $DB['pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (Exception $e) {
    die('<pre style="padding:2rem">Cannot connect to PostgreSQL: ' . h($e->getMessage()) .
        "\n\nCheck the settings at the top of index.php and that extension=pdo_pgsql is enabled in php.ini.</pre>");
}

$page = $_GET['page'] ?? 'records';
if (!in_array($page, ['records', 'backup'], true)) $page = 'records';
$req = $_GET['t'] ?? '';
$tbl = isset($T[$req]) ? $req : 'borrowers';
$def = $T[$tbl];
$pk  = $def['pk'];

if (isset($_GET['download'])) {
    $f = basename((string)$_GET['download']);
    if (backup_name_ok($f) && is_file("$BACKUP_DIR/$f")) {
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $f . '"');
        header('Content-Length: ' . filesize("$BACKUP_DIR/$f"));
        readfile("$BACKUP_DIR/$f");
        exit;
    }
    http_response_code(404);
    exit('File not found.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['action'] ?? '';
    $go  = "index.php?t=$tbl";
    try {
        if (in_array($act, ['create', 'update', 'delete'], true)) {
            $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);
            if ($act !== 'create' && $id === false) throw new Exception('Invalid record ID.');

            $vals = [];
            foreach ($def['cols'] as $c => $m) {
                $v = trim($_POST[$c] ?? '');
                $vals[$c] = $v === '' ? null : $v;
            }

            if ($act !== 'delete') {
                foreach ($def['cols'] as $c => $m) {
                    if ($vals[$c] === null) throw new Exception("{$m[0]} is required.");
                    if ($m[1] === 'select' && !in_array($vals[$c], $m[2], true)) throw new Exception("Invalid choice for {$m[0]}.");
                    if (in_array($m[1], ['number', 'fk'], true) && !ctype_digit($vals[$c])) throw new Exception("{$m[0]} must be a whole number (0 or more).");
                    if ($m[1] === 'date') {
                        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $vals[$c], $dm) || !checkdate((int)$dm[2], (int)$dm[3], (int)$dm[1])) {
                            throw new Exception("{$m[0]} must be a valid date (YYYY-MM-DD).");
                        }
                    }
                }
                if ($tbl === 'borrow_transactions' && $vals['due_date'] < $vals['borrow_date']) {
                    throw new Exception('Due date cannot be earlier than the borrow date.');
                }
            }

            $castOf = ['text' => 'varchar', 'number' => 'integer', 'date' => 'date', 'fk' => 'integer', 'select' => 'varchar'];
            $ph = [];
            $params = [];
            if ($act !== 'create') {
                $ph[] = 'CAST(:__id AS integer)';
                $params['__id'] = $id;
            }
            if ($act !== 'delete') {
                foreach ($def['cols'] as $c => $m) {
                    $ph[] = "CAST(:$c AS {$castOf[$m[1]]})";
                    $params[$c] = $vals[$c];
                }
            }
            $sql = 'CALL ' . $def['proc'][$act] . '(' . implode(', ', $ph) . ')';

            $pdo->beginTransaction();
            $pdo->prepare($sql)->execute($params);
            $pdo->commit();
            $_SESSION['msg'] = ['create' => 'Record added.', 'update' => 'Changes saved.', 'delete' => 'Record deleted.'][$act];

        } elseif (strpos($act, 'bk_') === 0) {
            $go = 'index.php?page=backup';
            set_time_limit(0);
            if (!is_dir($BACKUP_DIR)) mkdir($BACKUP_DIR, 0775, true);
            if ($act === 'bk_create') {
                $pgdump = pg_tool('pg_dump');
                if (!$pgdump) throw new Exception('pg_dump was not found. Set $PG_BIN at the top of index.php to your PostgreSQL bin folder.');
                $file = $DB['name'] . '_' . date('Ymd_His') . '.sql';
                $path = "$BACKUP_DIR/$file";
                [$code, $text] = run_cmd(
                    [$pgdump, '-h', $DB['host'], '-p', $DB['port'], '-U', $DB['user'], '-w', '-F', 'p', '--no-owner', '-f', $path, $DB['name']],
                    ['PGPASSWORD' => $DB['pass']]
                );
                if ($code !== 0) {
                    @unlink($path);
                    throw new Exception("pg_dump failed (exit code $code): $text");
                }
                [$ok, $note] = check_backup($path);
                if (!$ok) throw new Exception("Backup file was created but failed verification: $note");
                $_SESSION['msg'] = "Backup successful: $file (" . size_text(filesize($path)) . "). Verification: $note.";
            } elseif ($act === 'bk_delete') {
                $f = basename((string)($_POST['file'] ?? ''));
                if (!backup_name_ok($f) || !is_file("$BACKUP_DIR/$f")) throw new Exception('Backup file not found.');
                unlink("$BACKUP_DIR/$f");
                $_SESSION['msg'] = "Deleted $f.";
            }
        } else {
            throw new Exception('Unknown action.');
        }
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $_SESSION['err'] = clean_error($e);
    }
    header("Location: $go");
    exit;
}

$msg = $_SESSION['msg'] ?? '';
$err = $_SESSION['err'] ?? '';
unset($_SESSION['msg'], $_SESSION['err']);

$rows = [];
$lookup = [];
$q = trim($_GET['q'] ?? '');
$total = 0;
$pages = 1;
$cur = 1;

if ($page === 'records') {
    $bind = [];
    $like = '%' . addcslashes($q, '\\%_') . '%';
    $next = function () use (&$bind, $like) {
        $k = 's' . count($bind);
        $bind[$k] = $like;
        return ':' . $k;
    };
    $conds = [];

    if ($tbl === 'borrow_transactions') {
        $from   = 'FROM borrow_transactions t LEFT JOIN borrow_items bi ON bi.borrow_id = t.borrow_id';
        $select = 't.borrow_id, t.borrower_id, bi.equipment_id, t.borrow_date, t.due_date, t.status';
        $order  = 't.borrow_id DESC';
        if ($q !== '') {
            $conds = [
                't.borrow_id::text ILIKE ' . $next(),
                't.borrow_date::text ILIKE ' . $next(),
                't.due_date::text ILIKE ' . $next(),
                't.status ILIKE ' . $next(),
                't.borrower_id IN (SELECT borrower_id FROM borrowers WHERE full_name ILIKE ' . $next() . ')',
                'bi.equipment_id IN (SELECT equipment_id FROM equipment WHERE equipment_name ILIKE ' . $next() . ')'
            ];
        }
    } else {
        $from   = "FROM $tbl";
        $select = '*';
        $order  = "$pk DESC";
        if ($q !== '') {
            $conds[] = "{$pk}::text ILIKE " . $next();
            foreach ($def['cols'] as $c => $m) $conds[] = "{$c}::text ILIKE " . $next();
        }
    }
    $where = $conds ? 'WHERE ' . implode(' OR ', $conds) : '';

    $st = $pdo->prepare("SELECT COUNT(*) $from $where");
    $st->execute($bind);
    $total = (int)$st->fetchColumn();
    $pages = max(1, (int)ceil($total / $PER_PAGE));
    $cur   = min($pages, max(1, (int)($_GET['p'] ?? 1)));
    $offset = ($cur - 1) * $PER_PAGE;

    $st = $pdo->prepare("SELECT $select $from $where ORDER BY $order LIMIT $PER_PAGE OFFSET $offset");
    $st->execute($bind);
    $rows = $st->fetchAll();

    foreach ($def['cols'] as $c => $m) if ($m[1] === 'fk') {
        $ft = $m[2];
        foreach ($pdo->query("SELECT {$T[$ft]['pk']} AS id, {$T[$ft]['show']} AS label FROM $ft ORDER BY 1") as $r) {
            $lookup[$c][$r['id']] = "#{$r['id']} · {$r['label']}";
        }
    }
}

function link_to($p)
{
    global $tbl, $q;
    return 'index.php?' . http_build_query(['t' => $tbl, 'q' => $q, 'p' => $p]);
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Equipment Loans</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .navsep {
            height: 1px;
            background: #2a6a5f;
            margin: 12px 10px
        }

        .btn {
            display: inline-block;
            padding: 7px 14px;
            border: 1px solid var(--line);
            border-radius: 6px;
            background: #fff;
            color: var(--ink);
            text-decoration: none
        }

        .btn.off {
            opacity: .45
        }

        .pager {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 14px;
            color: var(--mute);
            font-size: 14px
        }

        .pager span.b {
            display: flex;
            gap: 6px
        }

        .card {
            background: var(--panel);
            border: 1px solid var(--line);
            border-radius: 8px;
            padding: 18px 20px;
            margin-bottom: 18px
        }

        .card h3 {
            margin: 0 0 12px;
            font: 700 18px Georgia, serif
        }

        .actions {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-top: 14px
        }

        .actions form {
            display: inline
        }

        .hint {
            color: var(--mute);
            font-size: 14px;
            margin: 8px 0 0
        }

        code {
            background: #f1f4ef;
            padding: 1px 5px;
            border-radius: 4px
        }

    </style>
</head>

<body>
    <aside>
        <h1>Equipment</h1>
        <?php foreach ($T as $k => $d): ?>
            <a href="index.php?t=<?= h($k) ?>" class="<?= ($page === 'records' && $k === $tbl) ? 'on' : '' ?>"><?= h($d['title']) ?></a>
        <?php endforeach; ?>
        <div class="navsep"></div>
        <a href="index.php?page=backup" class="<?= $page === 'backup' ? 'on' : '' ?>">Backup</a>
    </aside>

    <main>
        <?php if ($msg): ?><div class="note ok"><?= h($msg) ?></div><?php endif; ?>
        <?php if ($err): ?><div class="note er"><?= h($err) ?></div><?php endif; ?>

        <?php if ($page === 'records'): ?>
            <div class="bar">
                <h2><?= h($def['title']) ?></h2>
                <form method="get"><input type="hidden" name="t" value="<?= h($tbl) ?>">
                    <input type="search" name="q" value="<?= h($q) ?>" placeholder="Search this table">
                </form>
                <button class="pri" onclick="openForm()">Add record</button>
            </div>

            <div class="box">
                <?php if (!$rows): ?>
                    <div class="empty">No records found. Use “Add record” to create the first one.</div>
                <?php else: ?>
                    <table>
                        <tr>
                            <th>ID</th><?php foreach ($def['cols'] as $m): ?><th><?= h($m[0]) ?></th><?php endforeach; ?><th></th>
                        </tr>
                        <?php foreach ($rows as $r): ?>
                            <tr>
                                <td><?= h($r[$pk]) ?></td>
                                <?php foreach ($def['cols'] as $c => $m): ?>
                                    <td><?= h($m[1] === 'fk' ? ($lookup[$c][$r[$c]] ?? $r[$c]) : $r[$c]) ?></td>
                                <?php endforeach; ?>
                                <td class="act">
                                    <button onclick='openForm(<?= h(json_encode($r)) ?>)'>Edit</button>
                                    <form method="post" onsubmit="return confirm('Delete this record?')">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?= h($r[$pk]) ?>">
                                        <button class="del">Delete</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </table>
                <?php endif; ?>
            </div>

            <div class="pager">
                <span><?= number_format($total) ?> records · page <?= number_format($cur) ?> of <?= number_format($pages) ?></span>
                <span class="b">
                    <?php if ($cur > 1): ?><a class="btn" href="<?= h(link_to($cur - 1)) ?>">Previous</a><?php else: ?><span class="btn off">Previous</span><?php endif; ?>
                    <?php if ($cur < $pages): ?><a class="btn" href="<?= h(link_to($cur + 1)) ?>">Next</a><?php else: ?><span class="btn off">Next</span><?php endif; ?>
                </span>
            </div>
        <?php endif; ?>

        <?php if ($page === 'backup'):
            $pgdump = pg_tool('pg_dump');
            $files = [];
            if (is_dir($BACKUP_DIR)) {
                foreach (scandir($BACKUP_DIR) as $f) if (backup_name_ok($f)) $files[] = $f;
                rsort($files);
            }
        ?>
            <div class="bar">
                <h2>Backup</h2>
            </div>

            <div class="card">
                <h3>Create a backup</h3>
                <p class="hint"><?= $pgdump ? 'Using <code>' . h($pgdump) . '</code>' : 'pg_dump was not found. Set <code>$PG_BIN</code> at the top of index.php.' ?></p>
                <div class="actions">
                    <form method="post" onsubmit="this.querySelector('button').textContent='Backing up…'">
                        <input type="hidden" name="action" value="bk_create">
                        <button class="pri" <?= $pgdump ? '' : 'disabled' ?>>Back up now</button>
                    </form>
                </div>
            </div>

            <div class="card">
                <h3>Saved backups</h3>
                <?php if (!$files): ?>
                    <p class="hint">No backups yet.</p>
                <?php else: ?>
                    <div class="box">
                        <table>
                            <tr>
                                <th>File</th>
                                <th>Created</th>
                                <th>Size</th>
                                <th>Verification</th>
                                <th></th>
                            </tr>
                            <?php foreach ($files as $f):
                                $p = "$BACKUP_DIR/$f";
                                [$ok, $note] = check_backup($p); ?>
                                <tr>
                                    <td><?= h($f) ?></td>
                                    <td><?= h(date('Y-m-d H:i:s', filemtime($p))) ?></td>
                                    <td><?= h(size_text(filesize($p))) ?></td>
                                    <td><?= $ok ? '✓ ' : '✗ ' ?><?= h($note) ?></td>
                                    <td class="act">
                                        <a class="btn" href="index.php?download=<?= h(urlencode($f)) ?>">Download</a>
                                        <form method="post" onsubmit="return confirm('Delete this backup file?')">
                                            <input type="hidden" name="action" value="bk_delete">
                                            <input type="hidden" name="file" value="<?= h($f) ?>">
                                            <button class="del">Delete</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </table>
                    </div>
                    <p class="hint">Verification checks that the file begins and ends like a complete pg_dump file. To restore a backup, run it in pgAdmin’s Query Tool on an empty database.</p>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </main>

    <?php if ($page === 'records'): ?>
        <dialog id="dlg" data-cols='<?= h(json_encode(array_keys($def['cols']))) ?>' data-pk="<?= h($pk) ?>">
            <form method="post">
                <h3 id="ttl"></h3>
                <input type="hidden" name="action" id="action">
                <input type="hidden" name="id" id="id">

                <?php foreach ($def['cols'] as $c => $m): ?>
                    <label for="f_<?= h($c) ?>"><?= h($m[0]) ?></label>
                    <?php if ($m[1] === 'fk'): ?>
                        <select name="<?= h($c) ?>" id="f_<?= h($c) ?>" required>
                            <option value="">Choose…</option>
                            <?php foreach ($lookup[$c] ?? [] as $fid => $lb): ?><option value="<?= h($fid) ?>"><?= h($lb) ?></option><?php endforeach; ?>
                        </select>
                    <?php elseif ($m[1] === 'select'): ?>
                        <select name="<?= h($c) ?>" id="f_<?= h($c) ?>" required>
                            <option value="">Choose…</option>
                            <?php foreach ($m[2] as $o): ?><option value="<?= h($o) ?>"><?= h($o) ?></option><?php endforeach; ?>
                        </select>
                    <?php elseif ($m[1] === 'date'): ?>
                        <div class="datewrap">
                            <input type="text" name="<?= h($c) ?>" id="f_<?= h($c) ?>" required
                                placeholder="YYYY-MM-DD" inputmode="numeric" autocomplete="off"
                                pattern="\d{4}-\d{2}-\d{2}" title="Use the format YYYY-MM-DD">
                            <button type="button" class="cal" onclick="pickDate('<?= h($c) ?>')" title="Pick a date">📅</button>
                            <input type="date" id="p_<?= h($c) ?>" class="hidepick" tabindex="-1" aria-hidden="true"
                                onchange="document.getElementById('f_<?= h($c) ?>').value = this.value">
                        </div>
                    <?php else: ?>
                        <input type="<?= h($m[1]) ?>" name="<?= h($c) ?>" id="f_<?= h($c) ?>" required <?= $m[1] === 'number' ? 'min="0" step="1"' : '' ?>>
                    <?php endif; ?>
                <?php endforeach; ?>
                <div class="foot">
                    <button type="button" onclick="dlg.close()">Cancel</button>
                    <button class="pri">Save</button>
                </div>
            </form>
        </dialog>
        <script src="script.js"></script>
    <?php endif; ?>
</body>

</html>