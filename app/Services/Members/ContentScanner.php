<?php

declare(strict_types=1);

namespace App\Services\Members;

/**
 * Pattern checks run on member-written text as it is saved.
 *
 * Stored as flags at write time so the risk engine and the flagged-message
 * stream read facts rather than re-scanning history — and so the website and
 * the mobile API flag exactly the same things.
 */
final class ContentScanner
{
    public static function containsLink(string $text): bool
    {
        return (bool) preg_match('~https?://|www\.|\b[a-z0-9-]+\.(com|net|org|io|co|me)\b~i', $text);
    }

    /** Phone numbers, handles and off-platform app names — the usual scam opener. */
    public static function containsContactInfo(string $text): bool
    {
        return (bool) preg_match('~@[a-z0-9._]{3,}|\+?\d[\d\s().-]{7,}\d|\b(whatsapp|telegram|snap(chat)?|insta(gram)?)\b~i', $text);
    }
}
