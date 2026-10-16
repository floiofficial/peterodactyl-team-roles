<?php

declare(strict_types=1);

// Team management - by floiofficial.
// Tests the pure rules for ticket attachments (TicketFiles). Run: php tests/ticket_files_test.php

require __DIR__ . '/../extension/team-roles/src/Support/TicketFiles.php';

use TeamRoles\Support\TicketFiles as F;

$failed = 0;
$total = 0;
function check(string $name, bool $ok): void
{
    global $failed, $total;
    $total++;
    if (! $ok) {
        $failed++;
        echo "FAIL  $name\n";
    }
}

$png = "\x89PNG\r\n\x1a\n" . str_repeat("\0", 20);
$jpg = "\xFF\xD8\xFF\xE0" . str_repeat("\1", 20);
$gif = 'GIF89a' . str_repeat("\0", 10);
$webp = 'RIFF' . "\x10\0\0\0" . 'WEBP' . 'VP8 ';

// --- sniffing decides, not the name
check('png', F::sniff($png) === 'png');
check('jpg', F::sniff($jpg) === 'jpg');
check('gif', F::sniff($gif) === 'gif');
check('webp', F::sniff($webp) === 'webp');
check('RIFF that is not webp', F::sniff('RIFF' . "\0\0\0\0" . 'WAVE' . "\0\0") === null);
check('text', F::sniff("Error: connection refused\nline 2\n") === 'txt');
check('text with umlauts', F::sniff("Fehler: Größe überschritten") === 'txt');
check('binary (exe/zip/pdf-like with NUL) rejected', F::sniff("MZ\x90\0\x03\0\0\0") === null && F::sniff("PK\x03\x04\0\0") === null);
check('invalid utf-8 rejected', F::sniff("\xC3\x28 broken") === null);
check('empty rejected', F::sniff('') === null);

// --- inspect
$r = F::inspect($png, 'Screenshot 1.png');
check('png ok', $r['ok'] && $r['kind'] === 'png' && $r['mime'] === 'image/png' && $r['name'] === 'Screenshot 1.png' && $r['size'] === strlen($png));
$r = F::inspect($png, 'evil.exe');
check('png called .exe gets a .png name', $r['ok'] && $r['name'] === 'evil.png');
$r = F::inspect($jpg, 'photo.jpeg');
check('jpeg extension kept', $r['ok'] && $r['name'] === 'photo.jpeg' && $r['ext'] === 'jpg');
$r = F::inspect("log line\n", 'server.log');
check('.log kept for text', $r['ok'] && $r['name'] === 'server.log' && $r['mime'] === 'text/plain; charset=UTF-8');
$r = F::inspect("<script>alert(1)</script>", 'page.html');
check('html is just text and renamed .txt', $r['ok'] && $r['kind'] === 'txt' && $r['name'] === 'page.txt' && ! F::isInline('txt'));
$r = F::inspect("MZ\0\0", 'a.png');
check('exe disguised as png rejected', ! $r['ok'] && is_string($r['error']) && str_contains($r['error'], 'only images'));
check('empty file rejected', ! F::inspect('', 'a.png')['ok']);
check('too big rejected', ! F::inspect($png . str_repeat('x', F::MAX_BYTES), 'big.png')['ok']);
check('exactly max accepted', F::inspect(substr($png . str_repeat("\0", F::MAX_BYTES), 0, F::MAX_BYTES), 'max.png')['ok']);

// --- names
check('path stripped', F::cleanName('../../etc/passwd') === 'passwd' && F::cleanName('C:\\Users\\x\\shot.png') === 'shot.png');
check('control chars and quotes replaced', F::cleanName("a\r\nb\"c<d>.png") === 'a_b_c_d_.png');
check('empty becomes file', F::cleanName('') === 'file' && F::cleanName('...') === 'file' && F::cleanName('   ') === 'file');
check('long name cut', mb_strlen(F::cleanName(str_repeat('a', 300) . '.png')) === 100);
check('unicode kept', F::cleanName('Bildschirmfoto für Ticket.png') === 'Bildschirmfoto für Ticket.png');

// --- storage names and misc
$a = F::storageName('png');
$b = F::storageName('png');
check('storage name random, no user input, safe', $a !== $b && preg_match('/^[0-9a-f]{32}\.png$/', $a) === 1);
check('inline only for images', F::isInline('png') && F::isInline('jpg') && F::isInline('gif') && F::isInline('webp') && ! F::isInline('txt'));
check('mimeFor', F::mimeFor('jpg') === 'image/jpeg' && F::mimeFor('zzz') === 'application/octet-stream');
check('human size', F::humanSize(500) === '1 KB' && F::humanSize(2048) === '2 KB' && F::humanSize(3 * 1048576) === '3.0 MB');
check('limits', F::MAX_FILES_PER_MESSAGE === 3 && F::MAX_FILES_PER_TICKET === 30 && F::MAX_BYTES === 5242880);

echo "\n" . ($failed === 0 ? "OK: $total checks, 0 failed\n" : "$failed of $total checks FAILED\n");
exit($failed === 0 ? 0 : 1);
