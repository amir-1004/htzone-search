<?php

namespace App\Search;

/**
 * Vectors are stored packed as float32 (2 KB for 512 dimensions) and
 * normalized to unit length, so cosine similarity is a plain dot product.
 */
class VectorMath
{
    /**
     * @param  list<float>  $vector
     */
    public static function pack(array $vector): string
    {
        return pack('g*', ...self::normalize($vector));
    }

    /**
     * @return list<float>
     */
    public static function unpack(string $packed): array
    {
        return array_values(unpack('g*', $packed));
    }

    /**
     * @param  list<float>  $vector
     * @return list<float>
     */
    public static function normalize(array $vector): array
    {
        $length = sqrt(array_sum(array_map(fn (float $v) => $v * $v, $vector)));

        return $length > 0 ? array_map(fn (float $v) => $v / $length, $vector) : $vector;
    }

    /**
     * @param  list<float>  $a
     * @param  list<float>  $b
     */
    public static function dot(array $a, array $b): float
    {
        $sum = 0.0;

        foreach ($a as $i => $value) {
            $sum += $value * $b[$i];
        }

        return $sum;
    }
}
