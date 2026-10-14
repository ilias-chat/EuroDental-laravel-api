<?php

namespace App\Services\Clients;

use Illuminate\Support\Str;

class ClientDuplicateDetector
{
    /** @param array<int, array<string, mixed>> $clients */
    public function detect(array $clients): array
    {
        $records = [];
        $buckets = [];

        foreach ($clients as $client) {
            $id = (int) $client['id'];
            $first = $this->normalize((string) ($client['first_name'] ?? ''));
            $last = $this->normalize((string) ($client['last_name'] ?? ''));
            $email = strtolower(trim((string) ($client['email'] ?? '')));
            $mobile = $this->phone((string) ($client['phone_number'] ?? ''));
            $landline = $this->phone((string) ($client['fixed_phone_number'] ?? ''));

            $records[$id] = compact('client', 'first', 'last', 'email', 'mobile', 'landline');
            $keys = [];
            if (filter_var($email, FILTER_VALIDATE_EMAIL)) $keys[] = 'e:' . $email;
            if ($mobile !== '') $keys[] = 'p:' . $mobile;
            if ($landline !== '') $keys[] = 'p:' . $landline;
            if (strlen($first) >= 3) $keys[] = 'f:' . $first;
            if (strlen($last) >= 3) $keys[] = 'l:' . $last;
            if (strlen($first) >= 3) $keys[] = 'x:' . $first;
            if (strlen($last) >= 3) $keys[] = 'x:' . $last;
            if (strlen($first) >= 2 && strlen($last) >= 2) {
                $keys[] = 'n:' . substr($first, 0, 2) . ':' . substr($last, 0, 2);
            }
            foreach (array_unique($keys) as $key) $buckets[$key][] = $id;
        }

        $seen = [];
        $matches = [];
        foreach ($buckets as $ids) {
            $count = count($ids);
            for ($i = 0; $i < $count; $i++) {
                for ($j = $i + 1; $j < $count; $j++) {
                    $leftId = min($ids[$i], $ids[$j]);
                    $rightId = max($ids[$i], $ids[$j]);
                    $key = $leftId . ':' . $rightId;
                    if (isset($seen[$key])) continue;
                    $seen[$key] = true;
                    $match = $this->compare($records[$leftId], $records[$rightId]);
                    if ($match !== null) $matches[] = $match;
                }
            }
        }

        usort($matches, fn ($a, $b) => $b['score'] <=> $a['score'] ?: $a['clients'][0]['id'] <=> $b['clients'][0]['id'] ?: $a['clients'][1]['id'] <=> $b['clients'][1]['id']);

        return $matches;
    }

    private function compare(array $left, array $right): ?array
    {
        $first = $this->similarity($left['first'], $right['first']);
        $last = $this->similarity($left['last'], $right['last']);
        $swappedFirst = $this->similarity($left['first'], $right['last']);
        $swappedLast = $this->similarity($left['last'], $right['first']);
        $nameScore = max($first * .55 + $last * .45, $swappedFirst * .55 + $swappedLast * .45);
        $nameMatch = ($first >= .58 && $last >= .78 || $swappedFirst >= .58 && $swappedLast >= .78) && $nameScore >= .74;
        $emailMatch = $left['email'] !== '' && filter_var($left['email'], FILTER_VALIDATE_EMAIL) && $left['email'] === $right['email'];
        $mobileMatch = $left['mobile'] !== '' && in_array($left['mobile'], [$right['mobile'], $right['landline']], true)
            || $right['mobile'] !== '' && $right['mobile'] === $left['landline'];
        $landlineMatch = $left['landline'] !== '' && $left['landline'] === $right['landline'] && $nameScore >= .5;

        if (! $nameMatch && ! $emailMatch && ! $mobileMatch && ! $landlineMatch) return null;

        $reasons = [];
        $score = 0;
        if ($emailMatch) { $reasons[] = 'Meme e-mail'; $score = 95; }
        if ($mobileMatch) { $reasons[] = 'Meme telephone mobile'; $score = max($score, 90); }
        if ($landlineMatch) { $reasons[] = 'Meme telephone fixe'; $score = max($score, 78); }
        if ($nameMatch) {
            $reasons[] = $left['first'] === $right['first'] && $left['last'] === $right['last'] ? 'Meme nom' : 'Noms similaires';
            $score = max($score, (int) round(72 + 23 * min(1, max(0, ($nameScore - .74) / .26))));
        }
        if (count($reasons) > 1) $score = min(99, $score + 4);

        return [
            'score' => $score,
            'reasons' => $reasons,
            'clients' => [$left['client'], $right['client']],
        ];
    }

    private function similarity(string $left, string $right): float
    {
        if ($left === '' || $right === '') return 0;
        if ($left === $right) return 1;

        $direct = 1 - levenshtein($left, $right) / max(strlen($left), strlen($right));
        $consonantsLeft = preg_replace('/[aeiouy]/', '', $left);
        $consonantsRight = preg_replace('/[aeiouy]/', '', $right);
        if (strlen($consonantsLeft) < 2 || strlen($consonantsRight) < 2) return max(0, $direct);
        $consonants = 1 - levenshtein($consonantsLeft, $consonantsRight) / max(strlen($consonantsLeft), strlen($consonantsRight));

        return max(0, $direct, $consonants * .9);
    }

    private function normalize(string $value): string
    {
        $ascii = strtolower(Str::ascii(trim($value)));
        return preg_replace('/[^a-z0-9]/', '', str_replace(['ph', 'ou'], ['f', 'u'], $ascii)) ?? '';
    }

    private function phone(string $value): string
    {
        $digits = preg_replace('/\D/', '', $value) ?? '';
        if (str_starts_with($digits, '00212')) $digits = substr($digits, 2);
        if (str_starts_with($digits, '2120') && strlen($digits) === 13) $digits = substr($digits, 3);
        if (str_starts_with($digits, '212') && strlen($digits) === 12) $digits = '0' . substr($digits, 3);
        if (strlen($digits) === 9 && preg_match('/^[5-7]/', $digits)) $digits = '0' . $digits;
        return strlen($digits) >= 9 ? $digits : '';
    }
}
