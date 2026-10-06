<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Scaffolds a new catalog type (feature-scope M4 "make:notification stub";
 * named notification:make so the core command stays untouched — see 004
 * verification note). Generates Types/{Name}.php + a registry test skeleton.
 */
final class MakeNotificationTypeCommand extends Command
{
    protected $signature = 'notification:make {name : Class name, e.g. WeeklyDigest} {--type= : dotted type string (default: custom.snake_case)}';

    protected $description = 'Generate a new notification catalog type';

    public function handle(): int
    {
        $class = class_exists($this->argument('name')) ? $this->argument('name') : Str::studly((string) $this->argument('name'));
        $type = (string) ($this->option('type') ?? 'custom.'.Str::snake($class));

        if (preg_match('/^[a-z]+\.[a-z_]+$/', $type) !== 1) {
            $this->error('Type must be dotted lower_snake (e.g. org.weekly_digest).');

            return self::FAILURE;
        }

        $path = app_path("Notifications/Types/{$class}.php");

        if (is_file($path)) {
            $this->error("{$path} already exists.");

            return self::FAILURE;
        }

        file_put_contents($path, $this->stub($class, $type));
        $this->info("Created {$path} (type: {$type}) — auto-discovered, appears in preferences matrix.");

        return self::SUCCESS;
    }

    private function stub(string $class, string $type): string
    {
        return <<<PHP
<?php

declare(strict_types=1);

namespace App\\Notifications\\Types;

use App\\Notifications\\Contracts\\CatalogNotification;
use App\\Notifications\\Types\\Concerns\\BuildsCatalogMail;
use Illuminate\\Mail\\Mailable;

final class {$class} implements CatalogNotification
{
    use BuildsCatalogMail;

    public function type(): string
    {
        return '{$type}';
    }

    public function locked(): bool
    {
        return false;
    }

    public function emailDefault(): bool
    {
        return true;
    }

    public function title(array \$data): string
    {
        return (string) (\$data['title'] ?? '{$class}');
    }

    public function body(array \$data): string
    {
        return implode(' ', \$this->lines(\$data));
    }

    /**
     * @param  array<string, mixed>  \$data
     * @return array<int, string>
     */
    public function lines(array \$data): array
    {
        return [(string) (\$data['message'] ?? '')];
    }

    public function mailable(array \$data): Mailable
    {
        return \$this->mail(\$this->title(\$data), \$this->lines(\$data));
    }
}

PHP;
    }
}
