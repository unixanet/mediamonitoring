<?php
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/layout.php';
require_perm('sources.view');

$id = (int)($_POST['id'] ?? 0);
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_ok() && ($_POST['act'] ?? '') === 'retry') {
    $r = cdb()->query("SELECT source_id, content_status FROM articles WHERE id = $id");
    if ($r && ($a = $r->fetch_assoc())) {
        if ($a['content_status'] === 'full') {
            flash_set('ok', 'This article already has the full text.');
        } else {
            $r = engine_call('/article/' . $id . '/retry', ['wait' => 20], 30);
            if (!empty($r['running'])) $r = ['full' => 0, 'last_error' => 'still running in the background, refresh in a minute'];
            flash_set(!empty($r['full']) ? 'ok' : 'err', !empty($r['full']) ? 'Full text downloaded.' : 'Still no full text: ' . ($r['last_error'] ?? $r['error'] ?? 'unknown reason'));
        }
    }
}
header('Location: /article.php?id=' . $id);
