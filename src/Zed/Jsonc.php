<?php

namespace Sauron\Zed;

/**
 * Zed writes JSONC: line/block comments and trailing commas are legal there but
 * make json_decode() choke. The scanner below is string-aware so a "//" or a
 * "," living inside a string literal survives untouched.
 */
final class Jsonc
{
    /** @return array<string, mixed> */
    public static function decode(string $raw): array
    {
        $out = '';
        $length = \strlen($raw);
        $i = 0;

        while ($i < $length) {
            $char = $raw[$i];

            if ('"' === $char) {
                $end = $i + 1;
                while ($end < $length) {
                    if ('\\' === $raw[$end]) {
                        $end += 2;

                        continue;
                    }
                    if ('"' === $raw[$end]) {
                        break;
                    }
                    ++$end;
                }
                $out .= substr($raw, $i, $end - $i + 1);
                $i = $end + 1;

                continue;
            }

            if ('/' === $char && $i + 1 < $length && '/' === $raw[$i + 1]) {
                $newline = strpos($raw, "\n", $i);
                $i = false === $newline ? $length : $newline;

                continue;
            }

            if ('/' === $char && $i + 1 < $length && '*' === $raw[$i + 1]) {
                $close = strpos($raw, '*/', $i + 2);
                $i = false === $close ? $length : $close + 2;

                continue;
            }

            if ('}' === $char || ']' === $char) {
                $trimmed = rtrim($out);
                if (str_ends_with($trimmed, ',')) {
                    $out = substr($trimmed, 0, -1);
                }
            }

            $out .= $char;
            ++$i;
        }

        if ('' === trim($out)) {
            return [];
        }

        $decoded = self::normalize(json_decode($out, false, 512, \JSON_THROW_ON_ERROR));

        if (!\is_array($decoded)) {
            throw new \RuntimeException('Expected a JSON object at the root.');
        }

        return $decoded;
    }

    /**
     * PHP arrays cannot tell `{}` from `[]`, and Zed rejects a sequence where it
     * wants a map. Maps that would re-encode as a JSON array come back as an
     * ArrayObject, which still spreads and array-accesses like an array but
     * always encodes as an object.
     */
    private static function normalize(mixed $value): mixed
    {
        if ($value instanceof \stdClass) {
            $map = [];

            foreach (get_object_vars($value) as $key => $item) {
                $map[$key] = self::normalize($item);
            }

            return array_is_list($map) ? new \ArrayObject($map) : $map;
        }

        if (\is_array($value)) {
            return array_map(self::normalize(...), $value);
        }

        return $value;
    }

    /** @param array<string, mixed> $data */
    public static function encode(array $data): string
    {
        return json_encode($data, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR) . "\n";
    }
}
