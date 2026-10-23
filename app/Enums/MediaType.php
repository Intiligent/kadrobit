<?php

namespace App\Enums;

enum MediaType: string
{
    case Photo = 'photo';
    case Video = 'video';
    case Vector = 'vector';
    case Illustration = 'illustration';

    /** MIME types of vector originals. */
    public const VECTOR_MIMES = ['image/svg+xml', 'application/postscript', 'image/x-eps'];

    /**
     * Type of a work by the MIME type of its original. Illustrations cannot be told apart
     * from photos by the file; the user changes it later.
     */
    public static function fromMime(string $mime): self
    {
        return match (true) {
            str_starts_with($mime, 'video/') => self::Video,
            in_array($mime, self::VECTOR_MIMES, true) => self::Vector,
            default => self::Photo,
        };
    }
}
