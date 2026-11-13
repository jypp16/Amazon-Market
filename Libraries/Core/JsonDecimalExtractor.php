<?php

namespace Libraries\Core;

/**
 * Inspecciona claves de primer nivel en JSON crudo y extrae literales numéricos
 * antes de la conversión a float de json_decode().
 */
class JsonDecimalExtractor
{
    /**
     * Inspecciona todas las apariciones de $targetKey en el objeto JSON raíz
     * (después de decodificar escapes de clave como \u005f) sin confundirlas con
     * claves dentro de objetos/arreglos anidados ni texto dentro de cadenas.
     *
     * @return array{count:int,number_literal:?string}
     */
    public static function inspectTopLevelKey(string $rawJson, string $targetKey): array
    {
        $len = strlen($rawJson);
        $i = self::skipWhitespace($rawJson, 0, $len);
        if ($i >= $len || $rawJson[$i] !== '{') {
            return ['count' => 0, 'number_literal' => null];
        }
        $i++;

        $occurrences = 0;
        $numberLiteral = null;

        while ($i < $len) {
            $i = self::skipWhitespace($rawJson, $i, $len);
            if ($i >= $len || $rawJson[$i] === '}') {
                break;
            }

            if ($rawJson[$i] !== '"') {
                return ['count' => 0, 'number_literal' => null];
            }

            [$key, $i] = self::readJsonString($rawJson, $i, $len);
            if ($key === null) {
                return ['count' => 0, 'number_literal' => null];
            }

            $i = self::skipWhitespace($rawJson, $i, $len);
            if ($i >= $len || $rawJson[$i] !== ':') {
                return ['count' => 0, 'number_literal' => null];
            }
            $i++;
            $i = self::skipWhitespace($rawJson, $i, $len);
            if ($i >= $len) {
                return ['count' => 0, 'number_literal' => null];
            }

            if ($key === $targetKey) {
                $occurrences++;
                if ($occurrences > 1) {
                    return ['count' => $occurrences, 'number_literal' => null];
                }
                $ch = $rawJson[$i];
                if ($ch === '-' || ($ch >= '0' && $ch <= '9')) {
                    $start = $i;
                    while ($i < $len && !self::isDelimiterOrWhitespace($rawJson[$i])) {
                        $i++;
                    }
                    $numberLiteral = substr($rawJson, $start, $i - $start);
                } else {
                    $i = self::skipJsonValue($rawJson, $i, $len);
                    if ($i === null) {
                        return ['count' => 0, 'number_literal' => null];
                    }
                }
            } else {
                $i = self::skipJsonValue($rawJson, $i, $len);
                if ($i === null) {
                    return ['count' => 0, 'number_literal' => null];
                }
            }

            $i = self::skipWhitespace($rawJson, $i, $len);
            if ($i >= $len) {
                return ['count' => 0, 'number_literal' => null];
            }
            if ($rawJson[$i] === ',') {
                $i++;
                continue;
            }
            if ($rawJson[$i] === '}') {
                break;
            }
            return ['count' => 0, 'number_literal' => null];
        }

        return [
            'count'          => $occurrences,
            'number_literal' => $occurrences === 1 ? $numberLiteral : null,
        ];
    }

    /**
     * Devuelve el literal numérico crudo únicamente si $targetKey aparece exactamente una vez
     * en el objeto JSON raíz y su valor es un número JSON.
     */
    public static function extractTopLevelNumberLiteral(string $rawJson, string $targetKey): ?string
    {
        $info = self::inspectTopLevelKey($rawJson, $targetKey);
        return $info['count'] === 1 ? $info['number_literal'] : null;
    }

    private static function skipWhitespace(string $s, int $i, int $len): int
    {
        while ($i < $len && ($s[$i] === ' ' || $s[$i] === "\t" || $s[$i] === "\r" || $s[$i] === "\n")) {
            $i++;
        }
        return $i;
    }

    private static function isDelimiterOrWhitespace(string $ch): bool
    {
        return $ch === ',' || $ch === '}' || $ch === ']' || $ch === ' ' || $ch === "\t" || $ch === "\r" || $ch === "\n";
    }

    /**
     * @return array{0:?string,1:int}
     */
    private static function readJsonString(string $s, int $start, int $len): array
    {
        $i = $start + 1;
        while ($i < $len) {
            $ch = $s[$i];
            if ($ch === '\\') {
                $i += 2;
                continue;
            }
            if ($ch === '"') {
                $rawToken = substr($s, $start, $i - $start + 1);
                $decoded = json_decode($rawToken, true);
                return [is_string($decoded) ? $decoded : null, $i + 1];
            }
            $i++;
        }
        return [null, $len];
    }

    private static function skipJsonValue(string $s, int $i, int $len): ?int
    {
        $ch = $s[$i];
        if ($ch === '"') {
            [, $next] = self::readJsonString($s, $i, $len);
            return $next;
        }
        if ($ch === '{' || $ch === '[') {
            $depth = 1;
            $i++;
            while ($i < $len && $depth > 0) {
                $c = $s[$i];
                if ($c === '"') {
                    [, $i] = self::readJsonString($s, $i, $len);
                    continue;
                }
                if ($c === '{' || $c === '[') {
                    $depth++;
                } elseif ($c === '}' || $c === ']') {
                    $depth--;
                }
                $i++;
            }
            return $depth === 0 ? $i : null;
        }
        while ($i < $len && !self::isDelimiterOrWhitespace($s[$i])) {
            $i++;
        }
        return $i;
    }
}
