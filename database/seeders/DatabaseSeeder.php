<?php

namespace Database\Seeders;

// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // UserFactory ставит всем общеизвестный пароль 'password' и сразу
        // проставляет email_verified_at — на проде случайный db:seed завёл бы
        // десяток готовых к использованию учёток.
        if (app()->environment('production')) {
            $this->command?->warn('DatabaseSeeder пропущен: на production сидеры не выполняются.');

            return;
        }

        User::factory(10)->create();
    }
}
