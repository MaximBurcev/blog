<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\Widgets\SystemHealthOverview;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use ReflectionMethod;
use Tests\TestCase;

class FilamentSystemHealthWidgetTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => UserRole::Admin]);
    }

    public function test_system_health_widget_renders_successfully(): void
    {
        $this->actingAs($this->admin());

        Livewire::test(SystemHealthOverview::class)
            ->assertSuccessful();
    }

    public function test_widget_returns_all_six_system_stats(): void
    {
        $this->actingAs($this->admin());

        $widget = new SystemHealthOverview;
        $method = new ReflectionMethod($widget, 'getStats');
        $method->setAccessible(true);

        $stats = $method->invoke($widget);

        $this->assertCount(6, $stats);

        $labels = array_map(fn ($stat) => $stat->getLabel(), $stats);

        $this->assertContains('Диск', $labels);
        $this->assertContains('База данных', $labels);
        $this->assertContains('Очередь задач', $labels);
        $this->assertContains('Поиск (Meili)', $labels);
        $this->assertContains('Резервная копия', $labels);
        $this->assertContains('WebSockets', $labels);
    }
}
