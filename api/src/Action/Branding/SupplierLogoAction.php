<?php

declare(strict_types=1);

namespace MyInvoice\Action\Branding;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Service\Mail\SafeLogoPath;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * CUSTOM(fork): servíruje logo AKTUÁLNÍ firmy (supplier.logo_path) jako obrázek pro
 * zobrazení v hlavičce SPA (místo „MyInvoice.cz", když je logo definované).
 *
 * GET /api/branding/logo[?supplier_id=N]
 *   - aktuální firma = SupplierScopeMiddleware (header X-Supplier-Id nebo ?supplier_id=),
 *     navíc přescopováno SupplierAccessMiddleware → uživatel dostane jen povolenou firmu.
 *   - bez loga / nevalidní cesta → 404 (frontend pak ukáže fallback MyInvoice).
 *
 * Izolovaná custom akce — žádný háček do upstream souborů kromě 1 řádku v Routes.php.
 */
final class SupplierLogoAction
{
    private const MIME = [
        'png'  => 'image/png',
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'svg'  => 'image/svg+xml',
        'webp' => 'image/webp',
    ];

    public function __construct(
        private readonly Connection $db,
    ) {}

    public function __invoke(Request $request, Response $response): Response
    {
        $sid = (int) $request->getAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, 0);
        if ($sid <= 0) {
            return $response->withStatus(404);
        }

        $stmt = $this->db->pdo()->prepare('SELECT logo_path FROM supplier WHERE id = ? LIMIT 1');
        $stmt->execute([$sid]);
        $logoPath = $stmt->fetchColumn();
        if (!$logoPath) {
            return $response->withStatus(404);
        }

        $abs = SafeLogoPath::resolve((string) $logoPath, $sid);
        if ($abs === null || !is_file($abs)) {
            return $response->withStatus(404);
        }

        $bytes = (string) @file_get_contents($abs);
        if ($bytes === '') {
            return $response->withStatus(404);
        }

        $ext  = strtolower(pathinfo($abs, PATHINFO_EXTENSION));
        $mime = self::MIME[$ext] ?? 'application/octet-stream';

        $response->getBody()->write($bytes);
        return $response
            ->withHeader('Content-Type', $mime)
            ->withHeader('Cache-Control', 'private, max-age=300')
            // Defense-in-depth (parita s DownloadArchivedPdfAction/DocumentFileAction):
            // nosniff + sandbox — logo může být SVG, které by při přímé navigaci na URL
            // mohlo spustit vložený <script> (XSS). V <img> se neexekuuje, přímý GET ano.
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Content-Security-Policy', "default-src 'none'; sandbox")
            ->withStatus(200);
    }
}
