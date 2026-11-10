<?php

declare(strict_types=1);

namespace WildcoreMcp;

use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;

/**
 * Logs to stderr: stdout belongs to the MCP protocol in stdio mode,
 * and RoadRunner passes worker stderr to its own log.
 */
final class StderrLogger extends AbstractLogger
{
    private const LEVELS = [
        LogLevel::DEBUG => 0, LogLevel::INFO => 1, LogLevel::NOTICE => 2, LogLevel::WARNING => 3,
        LogLevel::ERROR => 4, LogLevel::CRITICAL => 5, LogLevel::ALERT => 6, LogLevel::EMERGENCY => 7,
    ];

    private int $threshold;

    /** @var resource */
    private $stream;

    public function __construct(string $level = LogLevel::INFO)
    {
        $this->threshold = self::LEVELS[$level] ?? throw new \InvalidArgumentException("Unknown LOG_LEVEL '$level', use: ".implode(', ', array_keys(self::LEVELS)));
        $this->stream = \defined('STDERR') ? \STDERR : fopen('php://stderr', 'w');
    }

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        if ((self::LEVELS[$level] ?? 1) < $this->threshold) {
            return;
        }
        $replace = [];
        foreach ($context as $key => $value) {
            if ('exception' === $key && $value instanceof \Throwable) {
                $value = $value::class.': '.$value->getMessage();
            }
            if (\is_scalar($value) || $value instanceof \Stringable) {
                $replace['{'.$key.'}'] = (string) $value;
            }
        }
        $line = strtr((string) $message, $replace);
        if (isset($context['exception']) && $context['exception'] instanceof \Throwable) {
            $line .= ' ['.$context['exception']::class.': '.$context['exception']->getMessage().']';
        }
        fwrite($this->stream, \sprintf("%s %s %s\n", date('c'), strtoupper((string) $level), $line));
    }
}
