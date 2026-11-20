<?php

namespace App\Controller;

use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Receives Content-Security-Policy-Report-Only reports (M9) and writes a short line to the log. */
final class CspReportController
{
    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    #[Route('/csp-report', name: 'app_csp_report', methods: ['POST'])]
    public function __invoke(Request $request): Response
    {
        $body = substr((string) $request->getContent(), 0, 8192);
        $report = json_decode($body, true)['csp-report'] ?? null;
        if (is_array($report)) {
            $this->logger->notice('CSP report-only: {directive} blocked {blocked} on {page}', [
                'directive' => substr((string) ($report['violated-directive'] ?? $report['effective-directive'] ?? '?'), 0, 120),
                'blocked' => substr((string) ($report['blocked-uri'] ?? '?'), 0, 200),
                'page' => substr((string) parse_url((string) ($report['document-uri'] ?? ''), PHP_URL_PATH), 0, 200),
            ]);
        }
        return new Response('', Response::HTTP_NO_CONTENT);
    }
}
