<?php

namespace Database\Factories;

use App\Models\Video;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Video>
 */
class VideoFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'type' => 0,
            'title' => $this->faker->unique()->words(3, true),
            'started' => $this->faker->dateTimeBetween('-30 years'),
            'source' => 0,
        ];
    }
}
