<?php

namespace App\Support;

/**
 * What a file actually IS, read from its first bytes.
 *
 * readmeAlamgir: "Server verifies type/signature". An extension is chosen by
 * whoever uploads, and the browser's content type is simply a header — neither
 * is evidence. A .pdf that is really a script is a .pdf to both of them.
 *
 * Only PDF, JPEG and PNG are accepted, which is what a signed contract or a
 * photograph of one arrives as.
 */
class ContractFileType
{
    /** Leading bytes that identify each accepted format. */
    private const SIGNATURES = [
        'pdf'  => ["%PDF-"],
        'jpeg' => ["\xFF\xD8\xFF"],
        'png'  => ["\x89PNG\r\n\x1a\n"],
    ];

    /**
     * The format these bytes really are, or null if it is not one we accept.
     */
    public static function detect(string $head): ?string
    {
        foreach (self::SIGNATURES as $type => $magics) {
            foreach ($magics as $magic) {
                if (str_starts_with($head, $magic)) {
                    return $type;
                }
            }
        }

        return null;
    }

    /** Read just enough of a file to identify it. */
    public static function detectFile(string $absolutePath): ?string
    {
        $handle = @fopen($absolutePath, 'rb');
        if (! $handle) {
            return null;
        }

        $head = (string) fread($handle, 16);
        fclose($handle);

        return self::detect($head);
    }

    /** The extension to store a verified file under. */
    public static function extensionFor(string $type): string
    {
        return $type === 'jpeg' ? 'jpg' : $type;
    }
}
