<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Short copy IDs for QR stickers (design §5.1), e.g. "K7Q2X".
 *
 * 4 random characters plus 1 check character. Alphabet is Crockford Base32
 * (no I, L, O, U). Data characters use only the first 31 symbols, so the
 * check (weighted sum mod 31) catches every single-character error and every
 * swap of two neighbouring characters.
 */
final class MAUS_Copy_ID {

    private const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';
    private const DATA_LEN = 4;
    private const MOD      = 31;

    public static function generate(): string {
        $code = '';
        for ($i = 0; $i < self::DATA_LEN; $i++) {
            $code .= self::ALPHABET[random_int(0, self::MOD - 1)];
        }
        return $code . self::check_char($code);
    }

    /**
     * Normalises scanned or typed input (also a full URL) to a valid ID.
     * Returns null when it is not a valid ID.
     */
    public static function parse(string $input): ?string {
        $input = trim($input);
        // From a URL only the last path segment counts; the domain is ignored.
        $input = preg_replace('~[?#].*$~', '', $input);
        $input = basename(rtrim($input, '/'));

        $code = strtoupper(preg_replace('/[\s-]+/', '', $input));
        $code = strtr($code, ['O' => '0', 'I' => '1', 'L' => '1']);

        if (strlen($code) !== self::DATA_LEN + 1) {
            return null;
        }
        $data = substr($code, 0, self::DATA_LEN);
        for ($i = 0; $i < self::DATA_LEN; $i++) {
            $pos = strpos(self::ALPHABET, $data[$i]);
            if ($pos === false || $pos >= self::MOD) {
                return null;
            }
        }
        return self::check_char($data) === $code[self::DATA_LEN] ? $code : null;
    }

    private static function check_char(string $data): string {
        $sum = 0;
        for ($i = 0; $i < strlen($data); $i++) {
            $sum += (strpos(self::ALPHABET, $data[$i])) * ($i + 1);
        }
        return self::ALPHABET[$sum % self::MOD];
    }
}
