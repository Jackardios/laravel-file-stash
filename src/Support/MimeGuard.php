<?php

namespace Jackardios\FileStash\Support;

use Jackardios\FileStash\Exceptions\MimeTypeIsNotAllowedException;

/**
 * Whitelist checks for MIME types with a single normalization rule.
 *
 * Sources report MIME types in different shapes ("Text/HTML; charset=UTF-8"
 * from HTTP headers and S3 disks, bare finfo strings, null when detection
 * fails): parameters are stripped and the bare type lowercased before
 * comparison, and an undetectable type becomes "(unknown)" — which never
 * matches a whitelist (deny by default).
 */
final class MimeGuard
{
    private const UNKNOWN = '(unknown)';

    /**
     * Normalize a reported MIME type to its bare lowercase form.
     */
    public static function normalize(mixed $type): string
    {
        if (! is_string($type)) {
            return self::UNKNOWN;
        }

        $bare = strtolower(trim(explode(';', $type)[0]));

        return $bare === '' ? self::UNKNOWN : $bare;
    }

    /**
     * Throw unless the reported type passes the whitelist.
     *
     * An empty whitelist allows everything.
     *
     * @param  array<int, string>  $allowedTypes  Lowercase MIME types (see ConfigNormalizer).
     *
     * @throws MimeTypeIsNotAllowedException
     */
    public static function ensureAllowed(mixed $type, array $allowedTypes): void
    {
        if ($allowedTypes === []) {
            return;
        }

        $normalized = self::normalize($type);

        if (! in_array($normalized, $allowedTypes, true)) {
            throw MimeTypeIsNotAllowedException::create($normalized);
        }
    }
}
