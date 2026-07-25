<?php

namespace Jackardios\FileStash\Support;

/**
 * Outcome of an attempt to delete a cached file.
 */
enum DeleteResult
{
    /** The file was deleted by this call. */
    case Deleted;

    /** The file exists but was kept (in use, or could not be verified/removed). */
    case Skipped;

    /** The file no longer exists — someone else already removed it. */
    case Gone;
}
