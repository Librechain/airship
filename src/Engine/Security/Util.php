<?php
declare(strict_types=1);
namespace Airship\Engine\Security;

use ParagonIE\ConstantTime\Binary;

/**
 * Class Util
 *
 * Contains various utilities that may be useful in developing Airship cabins.
 *
 * @package Airship\Engine\Security
 */
abstract class Util
{
    const NON_DIRECTORY = "\x20\x21\x22\x23\x24\x25\x26\x27" .
        "\x28\x29\x2a\x2b\x2c\x2d\x2e" .
        "\x30\x31\x32\x33\x34\x35\x36\x37" .
        "\x38\x39\x3a\x3b\x3c\x3d\x3e\x3f" .
        "\x40\x41\x42\x43\x44\x45\x46\x47" .
        "\x48\x49\x4a\x4b\x4c\x4d\x4e\x4f" .
        "\x50\x51\x52\x53\x54\x55\x56\x57" .
        "\x58\x59\x5a\x5b\x5c\x5d\x5e\x5f" .
        "\x60\x61\x62\x63\x64\x65\x66\x67" .
        "\x68\x69\x6a\x6b\x6c\x6d\x6e\x6f" .
        "\x70\x71\x72\x73\x74\x75\x76\x77" .
        "\x78\x79\x7a\x7b\x7c\x7d\x7e";
    const PRINTABLE_ASCII = "\x20\x21\x22\x23\x24\x25\x26\x27" .
        "\x28\x29\x2a\x2b\x2c\x2d\x2e\x2f" .
        "\x30\x31\x32\x33\x34\x35\x36\x37" .
        "\x38\x39\x3a\x3b\x3c\x3d\x3e\x3f" .
        "\x40\x41\x42\x43\x44\x45\x46\x47" .
        "\x48\x49\x4a\x4b\x4c\x4d\x4e\x4f" .
        "\x50\x51\x52\x53\x54\x55\x56\x57" .
        "\x58\x59\x5a\x5b\x5c\x5d\x5e\x5f" .
        "\x60\x61\x62\x63\x64\x65\x66\x67" .
        "\x68\x69\x6a\x6b\x6c\x6d\x6e\x6f" .
        "\x70\x71\x72\x73\x74\x75\x76\x77" .
        "\x78\x79\x7a\x7b\x7c\x7d\x7e";
    const UPPER_ALPHA = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
    const LOWER_ALPHA = 'abcdefghijklmnopqrstuvwxyz';
    const NUMERIC = '0123456789';
    const ALPHANUMERIC = self::NUMERIC .
        self::UPPER_ALPHA .
        self::LOWER_ALPHA;
    const DEFAULT_MIME_BLOCK = ['text/', 'application', 'xml', 'svg'];

    const BASE64_URLSAFE = self::ALPHANUMERIC . '-_';
    const MIME_CHARS = self::BASE64_URLSAFE . '/=';

    /**
     * Only allow characters present in the whitelist string to pass through
     * the filter.
     *
     * @param string $input
     * @param string $whitelist
     * @return string
     */
    public static function charWhitelist(
        string $input,
        string $whitelist = self::PRINTABLE_ASCII
    ): string {
        $output = '';
        $length = self::stringLength($input);
        for ($i = 0; $i < $length; ++$i) {
            if (\strpos($whitelist, $input[$i]) !== false) {
                $output .= $input[$i];
            }
        }
        return $output;
    }

    /**
     * Get the appropriate MIME Type for a file download.
     *
     * @param string $mimeType
     * @param string $default
     * @param array<int, string> $badSubstrings
     * @return string
     */
    public static function downloadFileType(
        string $mimeType,
        string $default = 'text/plain',
        array $badSubstrings = self::DEFAULT_MIME_BLOCK
    ): string {
        $block = false;
        foreach ($badSubstrings as $str) {
            $block = $block || \strpos($mimeType, $str) !== false;
        }

        if ($block) {
            $p = \strpos($mimeType, ';');
            if ($p !== false) {
                return self::charWhitelist(
                        $default,
                        self::MIME_CHARS
                    ) .
                    '; ' .
                    self::charWhitelist(
                        self::subString($mimeType, $p),
                        self::MIME_CHARS
                    );
            }
            return $default;
        }

        // Not a blocked MIME type, but we should still filter it
        // to prevent CRLF injections, etc. A character whitelist
        // is better than a blacklist.
        $p = \strpos($mimeType, ';');
        if ($p !== false) {
            return self::charWhitelist(
                self::subString($mimeType, 0, $p),
                self::MIME_CHARS
            ) .
            '; ' .
            self::charWhitelist(
                self::subString($mimeType, $p + 1),
                self::MIME_CHARS
            );
        }
        return self::charWhitelist(
            $mimeType,
            self::MIME_CHARS
        );
    }

    /**
     * @param string $address
     *
     * @return bool
     */
    public static function isValidEmail(string $address): bool
    {
        return \filter_var($address, FILTER_VALIDATE_EMAIL) !== false;
    }
    
    /**
     * Don't allow any HTML tags or attributes to be inserted into the DOM.
     * Prevents XSS attacks.
     * 
     * @param string $untrusted
     * @return string
     */
    public static function noHTML(string $untrusted): string
    {
        return \htmlspecialchars(
            $untrusted,
            ENT_QUOTES | ENT_HTML5,
            'UTF-8'
        );
    }
    
    /**
     * Binary-safe substr() implementation
     *
     * @param string $str
     * @param int $start
     * @param int|null $length
     * @return string
     */
    public static function subString(
        string $str,
        int $start,
        ?int $length = null
    ): string {
        try {
            return Binary::safeSubstr($str, $start, $length);
        } catch (\Throwable $ex) {
            return '';
        }
    }

    /**
     * Generate a random string of a given length and character set
     *
     * @param int $length How many characters do you want?
     * @param string $characters Which characters to choose from
     *
     * @return string
     */
    public static function randomString(
        int $length = 64,
        string $characters = self::PRINTABLE_ASCII
    ): string {
        $str = '';
        $l = self::stringLength($characters) - 1;
        for ($i = 0; $i < $length; ++$i) {
            $r = \random_int(0, $l);
            $str .= $characters[$r];
        }
        return $str;
    }

    /**
     * Binary-safe strlen() implementation
     *
     * @param string $str
     * @return int
     */
    public static function stringLength(string $str) : int
    {
        return Binary::safeStrlen($str);
    }
}
