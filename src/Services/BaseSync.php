<?php

namespace ItHealer\LaravelEvm\Services;

use Closure;
use ItHealer\LaravelEvm\Exceptions\SyncCancelledException;

abstract class BaseSync
{
    protected ?Closure $logger = null;
    protected ?Closure $progressCallback = null;
    protected ?Closure $cancelCallback = null;
    protected int $processedCount = 0;
    protected float $startedAt;

    public function setLogger(?Closure $logger): self
    {
        $this->logger = $logger;

        return $this;
    }

    /**
     * Callback invoked after each processed item: fn(int $processedCount, string $stage)
     */
    public function onProgress(?Closure $callback): self
    {
        $this->progressCallback = $callback;

        return $this;
    }

    /**
     * Callback polled during sync: return true to abort with SyncCancelledException
     */
    public function cancelWhen(?Closure $callback): self
    {
        $this->cancelCallback = $callback;

        return $this;
    }

    protected function reportProgress(string $stage): void
    {
        $this->processedCount++;

        if ($this->progressCallback) {
            call_user_func($this->progressCallback, $this->processedCount, $stage);
        }
    }

    /**
     * @throws SyncCancelledException
     */
    protected function checkCancelled(): void
    {
        if ($this->cancelCallback && call_user_func($this->cancelCallback)) {
            throw new SyncCancelledException();
        }
    }

    protected function log(string $message, ?string $type = null): void
    {
        if ($this->logger) {
            call_user_func($this->logger, '['.round((microtime(true) - $this->startedAt), 4).' s] '.$message, $type);
        }
    }

    public function run(): void
    {
        $this->startedAt = microtime(true);
    }
}