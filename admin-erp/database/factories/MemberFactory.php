<?php

namespace Database\Factories;

use App\Models\Member;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<Member>
 */
class MemberFactory extends Factory
{
    protected static ?string $password;

    public function definition(): array
    {
        return [
            'member_code' => null,
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'phone' => fake()->unique()->numerify('01#########'),
            'password' => static::$password ??= Hash::make('password'),
            'status' => 'pending',
            'public_profile_enabled' => false,
            'public_profile_approved' => false,
            'email_verified_at' => now(),
            'remember_token' => Str::random(10),
        ];
    }

    public function active(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'active',
            'member_code' => 'PF-'.fake()->unique()->numerify('######'),
        ]);
    }

    public function publiclyVisible(): static
    {
        return $this->active()->state(fn (array $attributes) => [
            'public_profile_enabled' => true,
            'public_profile_approved' => true,
        ]);
    }
}
