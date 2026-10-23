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
            cdb()->query("UPDATE articles SET content_status='pending', fetch_attempts=0, next_retry_at=NOW(), fail_reason=NULL WHERE id = $id");
            $ft = ft_run_jobs(cdb(), array_values(array_filter(ft_collect_jobs(cdb(), 1000, (int)$a['source_id']), fn($j) => $j['kind'] === 'article' && (int)$j['id'] === $id)), 1, 1);
            flash_set($ft['full'] ? 'ok' : 'err', $ft['full'] ? 'Full text downloaded.' : 'Still no full text: ' . ($ft['last_error'] ?: 'unknown reason'));
        }
    }
}
header('Location: /article.php?id=' . $id);
