<?php

declare(strict_types=1);

namespace TeamRoles\Support;

/**
 * Team management - by floiofficial.
 *
 * Pure rules for file attachments on tickets (no Laravel, testable on its own).
 * The file TYPE is decided by looking at the content (magic bytes), never by the name or the
 * type the browser claims. Only screenshots/images and plain text/log files are accepted.
 */
final class TicketFiles
{
    public const MAX_FILES_PER_MESSAGE = 3;
    public const MAX_FILES_PER_TICKET = 30;
    public const MAX_BYTES = 5 * 1024 * 1024;

    /** kind => [mime, extension, shown inline] */
    private const KINDS = [
        'png' => ['image/png', 'png', true],
        'jpg' => ['image/jpeg', 'jpg', true],
        'gif' => ['image/gif', 'gif', true],
        'webp' => ['image/webp', 'webp', true],
        'txt' => ['text/plain; charset=UTF-8', 'txt', false],
    ];

    /** @return string|null png|jpg|gif|webp|txt, or null when the content is not allowed */
    public static function sniff(string $content): ?string
    {
        if ($content === '') {
            return null;
        }
        if (str_starts_with($content, "\x89PNG\r\n\x1a\n")) {
            return 'png';
        }
        if (str_starts_with($content, "\xFF\xD8\xFF")) {
            return 'jpg';
        }
        if (str_starts_with($content, 'GIF87a') || str_starts_with($content, 'GIF89a')) {
            return 'gif';
        }
        if (strlen($content) >= 12 && str_starts_with($content, 'RIFF') && substr($content, 8, 4) === 'WEBP') {
            return 'webp';
        }
        // Plain text: no NUL bytes and valid UTF-8 (it is always served as text/plain, never as a web page).
        if (! str_contains($content, "\0") && mb_check_encoding($content, 'UTF-8')) {
            return 'txt';
        }

        return null;
    }

    /**
     * Check one uploaded file.
     *
     * @return array{ok: bool, error: ?string, kind?: string, mime?: string, ext?: string, name?: string, size?: int}
     */
    public static function inspect(string $content, string $originalName): array
    {
        $size = strlen($content);
        $name = self::cleanName($originalName);
        if ($size === 0) {
            return ['ok' => false, 'error' => $name . ': the file is empty.'];
        }
        if ($size > self::MAX_BYTES) {
            return ['ok' => false, 'error' => $name . ': the file is larger than ' . (self::MAX_BYTES / 1048576) . ' MB.'];
        }
        $kind = self::sniff($content);
        if ($kind === null) {
            return ['ok' => false, 'error' => $name . ': only images (PNG, JPG, GIF, WebP) and text files (.txt, .log) are allowed.'];
        }
        [$mime, $ext] = self::KINDS[$kind];

        return ['ok' => true, 'error' => null, 'kind' => $kind, 'mime' => $mime, 'ext' => $ext, 'name' => self::withExtension($name, $ext, $kind), 'size' => $size];
    }

    public static function isInline(string $kind): bool
    {
        return self::KINDS[$kind][2] ?? false;
    }

    public static function mimeFor(string $kind): string
    {
        return self::KINDS[$kind][0] ?? 'application/octet-stream';
    }

    /** Display name: no path, no control characters, no odd symbols, at most 100 characters. */
    public static function cleanName(string $name): string
    {
        $name = str_replace('\\', '/', $name);
        $name = basename($name);
        $name = (string) preg_replace('/[^\p{L}\p{N} ._()\-]+/u', '_', $name);
        $name = trim($name, " .\t");
        if (mb_strlen($name) > 100) {
            $name = mb_substr($name, 0, 100);
        }

        return $name === '' ? 'file' : $name;
    }

    /** Fix the extension so the name matches the real content (a PNG called "x.exe" becomes "x.png"). */
    private static function withExtension(string $name, string $ext, string $kind): string
    {
        $dot = strrpos($name, '.');
        $current = $dot === false ? '' : strtolower(substr($name, $dot + 1));
        $base = $dot === false ? $name : substr($name, 0, $dot);
        $ok = $kind === 'jpg' ? ['jpg', 'jpeg'] : ($kind === 'txt' ? ['txt', 'log'] : [$ext]);
        if (in_array($current, $ok, true)) {
            return $name;
        }

        return ($base === '' ? 'file' : $base) . '.' . $ext;
    }

    /** Random storage name; the original name never touches the disk. */
    public static function storageName(string $ext): string
    {
        return bin2hex(random_bytes(16)) . '.' . $ext;
    }

    /** Human readable size for e-mails/UI texts. */
    public static function humanSize(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 1) . ' MB';
        }

        return max(1, (int) round($bytes / 1024)) . ' KB';
    }
}
