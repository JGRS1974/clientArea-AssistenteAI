<?php

namespace App\Services\WhatsApp;

class MessageChunker
{
    private const SOFT_LIMIT = 3800;
    private const HARD_LIMIT = 4000;

    public function chunk(array $messages): array
    {
        $result = [];
        foreach ($messages as $message) {
            foreach ($this->split((string) $message) as $part) {
                if ($part !== '') {
                    $result[] = $part;
                }
            }
        }

        return $result;
    }

    private function split(string $text): array
    {
        $text = trim($text);
        if ($text === '') {
            return [];
        }

        if (mb_strlen($text) <= self::HARD_LIMIT) {
            return [$text];
        }

        $chunks = [];
        $remaining = $text;

        while (mb_strlen($remaining) > self::HARD_LIMIT) {
            $slice = mb_substr($remaining, 0, self::SOFT_LIMIT);
            $breakpoint = $this->findBreakpoint($slice);

            $chunks[] = trim(mb_substr($remaining, 0, $breakpoint));
            $remaining = ltrim(mb_substr($remaining, $breakpoint));
        }

        if (trim($remaining) !== '') {
            $chunks[] = trim($remaining);
        }

        return $chunks;
    }

    private function findBreakpoint(string $segment): int
    {
        $candidates = [
            "\n\n",
            "\n",
            '. ',
            '! ',
            '? ',
            '; ',
            ': ',
            ', ',
            ' • ',
            ' - ',
            ' '
        ];

        foreach ($candidates as $delimiter) {
            $pos = mb_strrpos($segment, $delimiter, 0, 'UTF-8');
            if ($pos !== false && $pos >= self::SOFT_LIMIT - 600) {
                return $pos + mb_strlen($delimiter, 'UTF-8');
            }
        }

        return self::SOFT_LIMIT;
    }
}

