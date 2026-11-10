<?php

declare(strict_types=1);

namespace WildcoreMcp;

use Mcp\Server;
use Mcp\Server\Transport\Http\Middleware\CorsMiddleware;
use Mcp\Server\Transport\Http\Middleware\DnsRebindingProtectionMiddleware;
use Mcp\Server\Transport\StreamableHttpTransport;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;

/**
 * HTTP entry: /health for liveness, /mcp for MCP Streamable HTTP.
 * Lives in a long-running RoadRunner worker, the MCP server is rebuilt
 * every CAPABILITIES_TTL seconds to pick up changed permissions and components.
 */
final class HttpHandler
{
    public const MCP_PATH = '/mcp';
    public const HEALTH_PATH = '/health';
    /** Second line of defense, RoadRunner already limits the body (http.max_request_size). */
    private const MAX_BODY_BYTES = 1_048_576;

    private ?Server $server = null;
    private int $builtAt = 0;
    private Psr17Factory $factory;

    public function __construct(
        private readonly Config $config,
        private readonly App $app,
        private readonly LoggerInterface $logger,
        private readonly string $sessionDir,
        private readonly int $capabilitiesTtl = 300,
    ) {
        $this->factory = new Psr17Factory();
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $path = rtrim($request->getUri()->getPath(), '/') ?: '/';
        // Liveness does not call WildCore, so its outage does not get MCP server restarted
        if (self::HEALTH_PATH === $path) {
            return $this->text(200, 'ok');
        }
        if (self::MCP_PATH !== $path) {
            return $this->text(404, 'Not found');
        }
        if (null !== $this->config->httpBearerToken && !$this->isAuthorized($request)) {
            return $this->text(401, 'Unauthorized')->withHeader('WWW-Authenticate', 'Bearer');
        }
        // JSON only: multipart/form-data and other form bodies are never accepted
        if ('POST' === $request->getMethod() && !str_starts_with(strtolower($request->getHeaderLine('Content-Type')), 'application/json')) {
            return $this->text(415, 'Content-Type must be application/json');
        }

        $transport = new StreamableHttpTransport(
            $request,
            $this->factory,
            $this->factory,
            $this->logger,
            [new CorsMiddleware(), new DnsRebindingProtectionMiddleware($this->config->allowedHosts)],
            self::MAX_BODY_BYTES,
        );

        return $this->server()->run($transport);
    }

    private function server(): Server
    {
        if (null === $this->server || time() - $this->builtAt >= $this->capabilitiesTtl) {
            $this->server = $this->app->buildServer($this->sessionDir);
            $this->builtAt = time();
        }

        return $this->server;
    }

    private function isAuthorized(ServerRequestInterface $request): bool
    {
        $header = $request->getHeaderLine('Authorization');

        return str_starts_with($header, 'Bearer ') && hash_equals((string) $this->config->httpBearerToken, substr($header, 7));
    }

    private function text(int $status, string $body): ResponseInterface
    {
        return $this->factory->createResponse($status)
            ->withHeader('Content-Type', 'text/plain; charset=utf-8')
            ->withBody($this->factory->createStream($body));
    }
}
