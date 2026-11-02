<?php
// Публичная страница о нашем краулере: для издателей, служб защиты сайтов и заявки на статус Verified Bot.
$cfgAll = require __DIR__ . '/config.php';
$contact = trim((string)($cfgAll['bot_contact'] ?? ''));
$ips = array_filter((array)($cfgAll['bot_ips'] ?? []));
header('Content-Type: text/html; charset=utf-8');
header('X-Robots-Tag: all');
function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
?><!DOCTYPE html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>MediaMonitoringBot</title>
<style>body{font-family:Inter,system-ui,sans-serif;color:#0F1B2D;background:#F3F6FA;margin:0;line-height:1.55}main{max-width:760px;margin:0 auto;padding:40px 20px}
h1{font-size:28px;margin:0 0 8px}h2{font-size:18px;margin:28px 0 8px}.card{background:#fff;border:1px solid #E1E7F0;border-radius:14px;padding:24px}code{background:#E8F2FC;border-radius:6px;padding:2px 6px}
li{margin:4px 0}.muted{color:#3C4C63}</style></head><body><main><div class="card">
<h1>MediaMonitoringBot</h1>
<p class="muted">Crawler of the Media Monitoring platform (mediamonitoring.am). It reads news sources for media monitoring clients: it collects headlines, text and publication dates and links back to the original article.</p>
<h2>How it identifies itself</h2>
<p>User-Agent: <code>Mozilla/5.0 (compatible; MediaMonitoringBot/1.0; +https://beta.mediamonitoring.am/bot.php)</code><br>Robots.txt token: <code>MediaMonitoringBot</code></p>
<h2>What it does</h2>
<ul><li>Reads RSS/Atom feeds, sitemaps and public article lists, then opens each new article page once.</li>
<li>Honours <code>robots.txt</code> (RFC 9309). Disallowed pages are not opened.</li>
<li>At most one request per second to the same host, with conditional requests (ETag, If-Modified-Since) for feeds.</li>
<li>Does not log in, submit forms or bypass paywalls.</li></ul>
<h2>Server addresses</h2>
<?php if ($ips): ?><ul><?php foreach ($ips as $ip): ?><li><code><?= h((string)$ip) ?></code></li><?php endforeach; ?></ul>
<?php else: ?><p class="muted">The list of addresses is published here by the operator (config key <code>bot_ips</code>).</p><?php endif; ?>
<h2>If our requests are blocked by your protection</h2>
<p>Please add a rule that lets the addresses above through (for Cloudflare: a custom WAF rule with the action <em>Skip</em> matching <code>ip.src</code>, optionally together with the user agent above).
If our crawler causes load or you want it to stop, tell us and we will remove your site at once.</p>
<h2>Contact</h2>
<p><?= $contact !== '' ? h($contact) : '<span class="muted">The contact address is set by the operator (config key <code>bot_contact</code>).</span>' ?></p>
</div></main></body></html>
