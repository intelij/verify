<?php

namespace App\Console\Commands;

use App\Models\Consumer;
use Illuminate\Console\Command;

class ConsumerCreateCommand extends Command
{
    protected $signature = 'consumer:create {name} {slug} {--policies=*} {--rate-limit=60}';
    protected $description = 'Provision an API consumer; prints the bearer token once';

    public function handle(): int
    {
        [$consumer, $token] = Consumer::provision(
            $this->argument('name'),
            $this->argument('slug'),
            $this->option('policies') ?: null,
        );
        $consumer->update(['rate_limit_per_minute' => (int) $this->option('rate-limit')]);

        $this->info("Consumer '{$consumer->name}' created.");
        $this->line("key_id:          {$consumer->key_id}");
        $this->line("bearer token:    {$token}");
        $this->line("webhook secret:  {$consumer->webhook_secret}");
        $this->warn('Store these now — the secret is shown only once.');

        return self::SUCCESS;
    }
}
