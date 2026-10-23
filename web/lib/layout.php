<?php
function page_head(string $title): void
{ ?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title><?= e($title) ?></title>
<script src="https://cdn.tailwindcss.com"></script>
</head>
<?php }
