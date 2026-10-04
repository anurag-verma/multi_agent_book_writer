<?php

namespace App\AI\Providers;

use App\AI\Interfaces\LLMInterface;
use App\AI\Interfaces\SearchProviderInterface;
use App\AI\Services\LLMService;
use App\AI\Services\WebSearchService;
use Illuminate\Support\ServiceProvider;

/**
 * Binds the abstract AI contracts to their default implementations.
 *
 * Swap a provider by changing config('ai.llm.provider') / config('ai.search.provider')
 * or by rebinding these interfaces at runtime, without touching any agent.
 */
class AIServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(LLMInterface::class, LLMService::class);
        $this->app->singleton(SearchProviderInterface::class, WebSearchService::class);
    }
}