<?php

declare(strict_types=1);

namespace Tlon\App;

function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES);
}

function layout(string $title, string $body, array $crumbs = []): string
{
    $trail = '<a href="/">sources</a>';
    foreach ($crumbs as $label => $href) {
        $trail .= ' <span class="sep">/</span> ' . ($href === '' ? e($label) : '<a href="' . e($href) . '">' . e($label) . '</a>');
    }

    return <<<HTML
<!doctype html>
<html lang="en"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>{$title} · tlon</title>
<style>
:root{--paper:#f3f4f2;--surface:#fbfbfa;--ink:#16191c;--soft:#3d454b;--muted:#69737a;--rule:#d6dbd8;--accent:#2f5b44}
@media(prefers-color-scheme:dark){:root{--paper:#121517;--surface:#191d20;--ink:#e7ece9;--soft:#c2cac6;--muted:#8f9a96;--rule:#2a3134;--accent:#77af90}}
*{box-sizing:border-box}
body{margin:0;background:var(--paper);color:var(--ink);font:15px/1.55 ui-sans-serif,system-ui,-apple-system,sans-serif}
main{max-width:1000px;margin:0 auto;padding:32px 24px 80px}
nav{font:12px ui-monospace,monospace;color:var(--muted);margin-bottom:28px;letter-spacing:.04em}
nav a{color:var(--muted)}.sep{opacity:.5;padding:0 4px}
h1{font-size:1.6rem;font-weight:600;letter-spacing:-.01em;margin:0 0 4px}
h2{font-size:.95rem;font-weight:600;margin:34px 0 10px}
p.sub{color:var(--muted);margin:0 0 24px;font-size:13.5px}
a{color:var(--accent);text-underline-offset:2px}
table{width:100%;border-collapse:collapse;background:var(--surface);border:1px solid var(--rule);border-radius:4px;overflow:hidden;font-size:13.5px}
th{text-align:left;font:11px ui-monospace,monospace;letter-spacing:.1em;text-transform:uppercase;color:var(--muted);padding:10px 14px;border-bottom:1px solid var(--rule)}
td{padding:9px 14px;border-bottom:1px solid var(--rule);color:var(--soft);vertical-align:top}
tr:last-child td{border-bottom:0}
td.n{font:12px ui-monospace,monospace;font-variant-numeric:tabular-nums;color:var(--muted)}
td strong{color:var(--ink);font-weight:600}
.tag{font:10px ui-monospace,monospace;letter-spacing:.08em;text-transform:uppercase;padding:2px 6px;border-radius:3px;border:1px solid var(--rule);color:var(--muted)}
.absent{color:#8e4230;border-color:#8e4230}
@media(prefers-color-scheme:dark){.absent{color:#d68f79;border-color:#d68f79}}
.empty{color:var(--muted);font-size:13px;padding:14px 0}
form{margin:12px 0 0;display:flex;gap:8px;flex-wrap:wrap}
input,button{font:13px inherit;padding:7px 10px;border:1px solid var(--rule);border-radius:4px;background:var(--surface);color:var(--ink)}
button{background:var(--accent);color:#fff;border-color:var(--accent);cursor:pointer}
</style></head><body><main><nav>{$trail}</nav>{$body}</main></body></html>
HTML;
}

function table(array $headings, array $rows, string $emptyMessage): string
{
    if ($rows === []) {
        return '<p class="empty">' . e($emptyMessage) . '</p>';
    }
    $head = '';
    foreach ($headings as $heading) {
        $head .= '<th>' . e($heading) . '</th>';
    }
    $body = '';
    foreach ($rows as $row) {
        $body .= '<tr>';
        foreach ($row as $cell) {
            $body .= is_array($cell) ? '<td class="n">' . $cell[0] . '</td>' : '<td>' . $cell . '</td>';
        }
        $body .= '</tr>';
    }

    return '<table><thead><tr>' . $head . '</tr></thead><tbody>' . $body . '</tbody></table>';
}
