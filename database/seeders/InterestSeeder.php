<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Interest;
use Illuminate\Database\Seeder;

class InterestSeeder extends Seeder
{
    /** @return array<string, array<int, string>> */
    private const CATALOGUE = [
        'Sports' => ['Running', 'Football', 'Climbing', 'Yoga', 'Cycling', 'Swimming', 'Tennis', 'Gym', 'Surfing', 'Skiing'],
        'Music' => ['Live music', 'Vinyl', 'Indie', 'Hip hop', 'Jazz', 'Techno', 'Karaoke', 'Playing guitar', 'Festivals'],
        'Food & Drink' => ['Coffee', 'Cooking', 'Baking', 'Wine', 'Craft beer', 'Street food', 'Vegan', 'Brunch', 'Whisky'],
        'Travel' => ['Backpacking', 'Road trips', 'Beaches', 'Hiking', 'City breaks', 'Camping', 'Languages'],
        'Arts' => ['Photography', 'Painting', 'Museums', 'Theatre', 'Film', 'Writing', 'Design', 'Pottery'],
        'Gaming' => ['Video games', 'Board games', 'Chess', 'Dungeons & Dragons', 'Puzzles', 'Esports'],
        'Wellness' => ['Meditation', 'Therapy-positive', 'Sleep hygiene', 'Journalling', 'Sober curious', 'Cold water'],
        'Pets' => ['Dogs', 'Cats', 'Horses', 'Reptiles', 'Birdwatching'],
        'Nightlife' => ['Dancing', 'Cocktails', 'Pubs', 'Comedy', 'Clubbing'],
        'Values' => ['Climate action', 'Volunteering', 'Feminism', 'LGBTQ+ ally', 'Politics', 'Spirituality', 'Family first'],
    ];

    public function run(): void
    {
        foreach (self::CATALOGUE as $category => $interests) {
            foreach ($interests as $name) {
                // firstOrCreate: re-seeding must not undo an operator's edits in Masters.
                Interest::query()->firstOrCreate(
                    ['slug' => str($name)->slug()->toString()],
                    ['name' => $name, 'category' => $category],
                );
            }
        }

        $this->command?->info('Seeded '.Interest::query()->count().' interests.');
    }
}
