<?php

declare(strict_types=1);

namespace OpenTelemetry\SDK\Trace\SpanProcessor;

use Closure;
use OpenTelemetry\Context\ContextInterface;
use OpenTelemetry\SDK\Behavior\LogsMessagesTrait;
use OpenTelemetry\SDK\Common\Future\CancellationInterface;
use OpenTelemetry\SDK\Trace\ReadableSpanInterface;
use OpenTelemetry\SDK\Trace\ReadWriteSpanInterface;
use OpenTelemetry\SDK\Trace\SpanExporterInterface;
use OpenTelemetry\SDK\Trace\SpanProcessorInterface;
use SplQueue;
use function sprintf;
use Throwable;

class SimpleSpanProcessor implements SpanProcessorInterface
{
    use LogsMessagesTrait;

    private SpanExporterInterface $exporter;

    private bool $running = false;
    /** @var SplQueue<array{Closure, string, bool}> */
    private SplQueue $queue;

    private bool $closed = false;

    public function __construct(SpanExporterInterface $exporter)
    {
        $this->exporter = $exporter;

        $this->queue = new SplQueue();
    }

    public function onStart(ReadWriteSpanInterface $span, ContextInterface $parentContext): void
    {
    }

    public function onEnd(ReadableSpanInterface $span): void
    {
        if ($this->closed) {
            return;
        }
        if (!$span->getContext()->isSampled()) {
            return;
        }

        $spanData = $span->toSpanData();
        $this->flush(fn () => $this->exporter->export([$spanData])->await(), 'export');
    }

    public function forceFlush(?CancellationInterface $cancellation = null): bool
    {
        if ($this->closed) {
            return false;
        }

        return $this->flush(fn (): bool => $this->exporter->forceFlush($cancellation), __FUNCTION__, true);
    }

    public function shutdown(?CancellationInterface $cancellation = null): bool
    {
        if ($this->closed) {
            return false;
        }

        $this->closed = true;

        return $this->flush(fn (): bool => $this->exporter->shutdown($cancellation), __FUNCTION__, true);
    }

    private function flush(Closure $task, string $taskName, bool $propagateResult = false): bool
    {
        $this->queue->enqueue([$task, $taskName, $propagateResult && !$this->running]);

        if ($this->running) {
            return false;
        }

        $success = true;
        $exception = null;
        $this->running = true;

        try {
            while (!$this->queue->isEmpty()) {
                [$task, $taskName, $propagateResult] = $this->queue->dequeue();

                try {
                    $result = $task();
                    if ($propagateResult) {
                        $success = $result;
                    }
                } catch (Throwable $e) {
                    if ($propagateResult) {
                        $exception = $e;

                        continue;
                    }
                    self::logError(sprintf('Unhandled %s error', $taskName), ['exception' => $e]);
                }
            }
        } finally {
            $this->running = false;
        }

        if ($exception !== null) {
            throw $exception;
        }

        return $success;
    }
}
