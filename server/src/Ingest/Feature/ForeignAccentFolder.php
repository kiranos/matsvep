<?php

declare(strict_types=1);

namespace Matsvep\Ingest\Feature;

final class ForeignAccentFolder
{
    public static function fold(string $text): string
    {
        return strtr(mb_strtolower($text), [
            'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'á' => 'a', 'à' => 'a', 'â' => 'a',
            'í' => 'i', 'î' => 'i', 'ï' => 'i',
            'ó' => 'o', 'ô' => 'o',
            'ú' => 'u', 'û' => 'u', 'ü' => 'u',
            'ç' => 'c', 'ñ' => 'n',
        ]);
    }
}
