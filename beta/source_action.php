<?php
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/layout.php';
$user = require_perm('sources.view');

$id = (int)($_POST['id'] ?? 0);
$act = (string)($_POST['act'] ?? '');
$back = ($_POST['back'] ?? '') === 'detail' ? '/source.php?id=' . $id : '/sources.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_ok()) {
    header('Location: /sources.php');
    exit;
}

$r = cdb()->query("SELECT * FROM sources WHERE id = $id");
$src = $r ? $r->fetch_assoc() : null;
if (!$src) {
    flash_set('err', 'Source not found.');
    header('Location: /sources.php');
    exit;
}

switch ($act) {
    case 'start':
    case 'fetch':
        if ($act === 'start') {
            cdb()->query("UPDATE sources SET is_active = 1 WHERE id = $id");
            $src['is_active'] = 1;
        }
        [$type, $msg] = crawl_flash(crawl_source_sync(cdb(), $src), $src['name']);   // реальный забег прямо сейчас
        flash_set($type, $msg);
        $back = '/source.php?id=' . $id;
        break;
    case 'retry':
        $n = ft_reset_source(cdb(), $id);
        $ft = ft_process_source(cdb(), $id, 20);
        flash_set('ok', sprintf('%d articles queued for another full-text attempt. Now: %d complete, %d still failing%s.', $n, $ft['full'], $ft['failed'] + $ft['partial'], $ft['last_error'] ? ' (' . $ft['last_error'] . ')' : ''));
        $back = '/source.php?id=' . $id;
        break;
    case 'pause':
        cdb()->query("UPDATE sources SET is_active = 0 WHERE id = $id");
        flash_set('ok', 'Source paused.');
        break;
    case 'delete_only':
        cdb()->query("DELETE FROM url_queue WHERE source_id = $id");
        cdb()->query("DELETE FROM sources WHERE id = $id");
        flash_set('ok', 'Source deleted. Its articles were kept in the database.');
        $back = '/sources.php';
        break;
    case 'delete_all':
        set_time_limit(300);
        do {
            $ids = [];
            $q = cdb()->query("SELECT id FROM articles WHERE source_id = $id LIMIT 2000");
            while ($q && ($x = $q->fetch_row())) {
                $ids[] = (int)$x[0];
            }
            if ($ids) {
                $in = implode(',', $ids);
                journal_add(cdb(), $ids, 'delete');       // Manticore узнает об удалении
                cdb()->query("DELETE FROM article_raw WHERE article_id IN ($in)");
                cdb()->query("DELETE FROM articles WHERE id IN ($in)");
            }
        } while ($ids);
        cdb()->query("DELETE FROM crawl_log WHERE source_id = $id");
        cdb()->query("DELETE FROM url_queue WHERE source_id = $id");
        cdb()->query("DELETE FROM sources WHERE id = $id");
        flash_set('ok', 'Source and its articles deleted.');
        $back = '/sources.php';
        break;
}
header('Location: ' . $back);
