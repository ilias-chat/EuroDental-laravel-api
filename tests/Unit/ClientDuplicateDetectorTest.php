<?php

namespace Tests\Unit;

use App\Services\Clients\ClientDuplicateDetector;
use PHPUnit\Framework\TestCase;

class ClientDuplicateDetectorTest extends TestCase
{
    public function test_it_finds_exact_and_fuzzy_names_without_case_sensitivity(): void
    {
        $matches = (new ClientDuplicateDetector)->detect([
            $this->client(1, 'MAYAM', 'Berrada'),
            $this->client(2, 'Meryem', 'BERRADA'),
            $this->client(3, 'Mohammd', 'El Idrissi'),
            $this->client(4, 'Mohamed', 'el idrissi'),
            $this->client(5, 'Sara', 'Berrada'),
        ]);

        $this->assertContains([1, 2], array_map(fn ($match) => array_column($match['clients'], 'id'), $matches));
        $this->assertContains([3, 4], array_map(fn ($match) => array_column($match['clients'], 'id'), $matches));
        $this->assertNotContains([1, 5], array_map(fn ($match) => array_column($match['clients'], 'id'), $matches));
    }

    public function test_it_normalizes_email_and_moroccan_phone_formats(): void
    {
        $matches = (new ClientDuplicateDetector)->detect([
            $this->client(1, 'Amina', 'Alaoui', ' Test@Example.com ', '+212 612 345 678'),
            $this->client(2, 'Youssef', 'Benali', 'test@example.com', '0612345678'),
        ]);

        $this->assertCount(1, $matches);
        $this->assertContains('Meme e-mail', $matches[0]['reasons']);
        $this->assertContains('Meme telephone mobile', $matches[0]['reasons']);
    }

    public function test_a_shared_landline_alone_does_not_mark_unrelated_clients(): void
    {
        $matches = (new ClientDuplicateDetector)->detect([
            $this->client(1, 'Karim', 'Alaoui', null, null, '0522334455'),
            $this->client(2, 'Sara', 'Benali', null, null, '0522334455'),
        ]);

        $this->assertSame([], $matches);
    }

    public function test_it_matches_a_number_entered_in_different_phone_fields(): void
    {
        $matches = (new ClientDuplicateDetector)->detect([
            $this->client(1, 'Amal', 'Rami', null, '0612345678'),
            $this->client(2, 'Amal', 'Rami', null, null, '+212 612 345 678'),
        ]);

        $this->assertCount(1, $matches);
        $this->assertContains('Meme telephone mobile', $matches[0]['reasons']);
    }

    private function client(int $id, string $first, string $last, ?string $email = null, ?string $phone = null, ?string $landline = null): array
    {
        return [
            'id' => $id,
            'first_name' => $first,
            'last_name' => $last,
            'name' => "$first $last",
            'email' => $email,
            'phone_number' => $phone,
            'fixed_phone_number' => $landline,
            'source' => 'admin',
        ];
    }
}
