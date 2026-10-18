<?php

declare(strict_types=1);

namespace App\Middleware;

class DownloadSignatureMiddleware implements \Framework\Http\Interfaces\MiddlewareInterface
{
    private $downloads;

    public function __construct(\App\Services\Storage\DownloadService $downloads)
    {
        $this->downloads = $downloads;
    }

    public function process(\Framework\Http\Interfaces\ServerRequestInterface $request, \Framework\Http\Interfaces\RequestHandlerInterface $handler): \Framework\Http\Interfaces\ResponseInterface
    {
        $this->downloads->verify($request, time());
        return $handler->handle($request);
    }
}
