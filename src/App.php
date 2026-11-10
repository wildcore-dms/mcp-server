<?php

declare(strict_types=1);

namespace WildcoreMcp;

use Dotenv\Dotenv;
use Mcp\Server;
use Mcp\Server\Transport\StdioTransport;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Log\LoggerInterface;
use Spiral\RoadRunner\Http\PSR7Worker;
use Spiral\RoadRunner\Worker;

/**
 * Entry point. Under RoadRunner (RR_MODE=http) works as HTTP worker,
 * otherwise serves MCP over stdio.
 */
final class App
{
    private readonly LoggerInterface $logger;
    private readonly WildcoreClient $client;

    public function __construct(private readonly Config $config, ?LoggerInterface $logger = null)
    {
        $this->logger = $logger ?? new StderrLogger($config->logLevel);
        $this->client = new WildcoreClient($config);
    }

    /**
     * Loads optional .env from the project root and reads configuration from environment.
     *
     * @throws \InvalidArgumentException on invalid configuration
     */
    public static function fromEnvironment(string $root): self
    {
        if (is_file($root.'/.env')) {
            Dotenv::createImmutable($root)->safeLoad();
        }

        return new self(Config::fromEnv($_ENV + getenv()));
    }

    public function run(): int
    {
        $this->checkPhpVersion();
        if ('http' === getenv('RR_MODE')) {
            return $this->runHttp();
        }
        $this->logger->info('Starting WildCore MCP server over stdio for {url}', ['url' => $this->config->wildcoreUrl]);
        $result = $this->buildServer()->run(new StdioTransport(logger: $this->logger));

        return \is_int($result) ? $result : 0;
    }

    /**
     * Builds server for the current token owner. When WildCore can not be asked about
     * permissions, all tools allowed by local config are listed and WildCore rejects the rest.
     */
    public function buildServer(?string $sessionDir = null): Server
    {
        try {
            $capabilities = $this->client->capabilities();
            $this->logger->info('WildCore user {user}: root={root}, components: {components}', [
                'user' => $capabilities['user'],
                'root' => $capabilities['root'] ? 'yes' : 'no',
                'components' => implode(',', $capabilities['components']),
            ]);
        } catch (\Throwable $e) {
            $capabilities = null;
            $this->logger->warning('Could not load WildCore permissions, tools are not filtered by them: {error}', ['error' => $e->getMessage()]);
        }

        return (new ServerFactory($this->config, $this->logger, $this->client))->build($capabilities, $sessionDir);
    }

    /**
     * RoadRunner worker loop. RoadRunner (Go) parses HTTP and limits the body size,
     * the CLI worker gets a ready PSR-7 request - PHP never parses request bodies itself.
     * RoadRunner restarts the worker after max_jobs requests (.rr.yaml).
     */
    private function runHttp(): int
    {
        if (null === $this->config->httpBearerToken) {
            $this->logger->warning('MCP_HTTP_BEARER_TOKEN is not set - MCP endpoint is open to anyone who can reach it, all calls run with WILDCORE_TOKEN permissions');
        }
        $factory = new Psr17Factory();
        $worker = new PSR7Worker(Worker::create(), $factory, $factory, $factory);
        $handler = new HttpHandler($this->config, $this, $this->logger, sys_get_temp_dir().'/wildcore-mcp-sessions', $this->config->capabilitiesTtl);

        while (true) {
            try {
                $request = $worker->waitRequest();
                if (null === $request) {
                    return 0;
                }
            } catch (\Throwable $e) {
                $worker->respond($factory->createResponse(400));
                continue;
            }
            try {
                $worker->respond($handler->handle($request));
            } catch (\Throwable $e) {
                $this->logger->error('Unhandled HTTP error: {error}', ['error' => $e->getMessage(), 'exception' => $e]);
                $worker->respond($factory->createResponse(500));
            }
        }
    }

    /**
     * PHP before 8.3.36 / 8.4.27 / 8.5.12 is affected by CVE-2026-109618 and CVE-2026-109619
     * (state corruption after OOM in multipart parsing). composer.json requires patched versions,
     * this guards against running the code with another interpreter.
     */
    private function checkPhpVersion(): void
    {
        $patched = ['8.3' => '8.3.36', '8.4' => '8.4.27', '8.5' => '8.5.12'];
        $branch = \PHP_MAJOR_VERSION.'.'.\PHP_MINOR_VERSION;
        if (isset($patched[$branch]) ? version_compare(\PHP_VERSION, $patched[$branch], '<') : version_compare(\PHP_VERSION, '8.3', '<')) {
            $this->logger->warning('PHP {version} is affected by CVE-2026-109618/CVE-2026-109619, upgrade to a patched release', ['version' => \PHP_VERSION]);
        }
    }
}
