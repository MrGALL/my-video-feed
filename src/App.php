<?php

declare(strict_types=1);

namespace App;

final class App
{
    public function __construct(
        private readonly Ingestor $ingestor,
        private readonly Feed $feed,
        private readonly string $basePath = '',
    ) {}

    public function run(): void
    {
        $route = $this->route();
        match (true) {
            $route === 'channels'  => $this->renderChannel(),
            $route === 'excluded'  => $this->renderExcluded(),
            $route === 'included'  => $this->renderIncluded(),
            $route === ''          => $this->renderHome(),
            default                => $this->handleChannel($route),
        };
    }

    private function route(): string
    {
        $uri = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?? '';
        $path = trim($uri, '/');
        $base = trim($this->basePath, '/');
        if ($base !== '' && str_starts_with($path, $base)) {
            $path = trim(substr($path, strlen($base)), '/');
        }
        return $path;
    }

    private function renderHome(): void
    {
        header('Content-Type: text/html; charset=UTF-8', true);
        echo $this->feed->renderHtml();
    }

    private function handleChannel(string $slug): void
    {
        if (isset($_GET['hub_challenge'])) {
            // Plain text so a reflected challenge can't run as HTML (XSS).
            header('Content-Type: text/plain; charset=UTF-8');
            echo $_GET['hub_challenge'];
            return;
        }
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $body = file_get_contents('php://input');
            if (stripos($body, '<yt:videoId>') !== false) {
                $this->ingestor->processChannel($slug, $body);
                $this->ingestor->pingChannel();
            }
            // Always 'ok' so the hub stops retrying, even for payloads we ignored.
            echo 'ok';
            return;
        }
        $this->ingestor->processChannel($slug);
        $this->ingestor->pingChannel();
    }

    private function renderChannel(): void
    {
        header('Content-Type: text/xml; charset=UTF-8', true);
        echo $this->feed->renderAggregate();
    }

    private function renderExcluded(): void
    {
        echo '<pre>' . htmlspecialchars($this->feed->renderExcluded(), ENT_QUOTES, 'UTF-8') . '</pre>';
    }

    private function renderIncluded(): void
    {
        echo '<pre>' . htmlspecialchars($this->feed->renderIncluded(), ENT_QUOTES, 'UTF-8') . '</pre>';
    }
}
