<?php

declare(strict_types=1);

namespace MyInvoice\Action\Invoice;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Pdf\PdfArchiveService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * CUSTOM(fork): DELETE /api/invoices/{id}/pdfs/{archiveId} — smaže JEDNU archivovanou
 * verzi PDF z historie faktury.
 *
 * POUZE admin. RoleMiddleware pravidlo `'* #^/api/invoices(/|$)#'` pouští na /api/invoices/*
 * i účetního, takže admin-only vynucujeme přímo tady (conflict-resistant — nesaháme do
 * RoleMiddleware pravidel, která upstream mění).
 *
 * Autorizace:
 *   1. role === 'admin' (jinak 403)
 *   2. SupplierGuard (faktura patří current supplier / povolené firmě)
 *   3. archive ID musí patřit této faktuře (scoped DELETE v service)
 */
final class DeleteArchivedPdfAction
{
    public function __construct(
        private readonly InvoiceRepository $repo,
        private readonly PdfArchiveService $archive,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        if (($user['role'] ?? '') !== 'admin') {
            return Json::error($response, 'forbidden', 'Mazání historie PDF je povoleno jen administrátorovi.', 403);
        }

        $id        = (int) ($args['id'] ?? 0);
        $archiveId = (int) ($args['archiveId'] ?? 0);

        $invoice = $this->repo->find($id);
        if (!SupplierGuard::owns($request, $invoice)) {
            return Json::error($response, 'not_found', 'Faktura nenalezena.', 404);
        }

        if (!$this->archive->deleteArchiveEntry($archiveId, $id)) {
            return Json::error($response, 'not_found', 'Archivní PDF nenalezeno.', 404);
        }

        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log('pdf.archive_deleted', $user['id'] ?? null, 'invoice', $id, [
            'archive_id' => $archiveId,
        ], $ip, $request->getHeaderLine('User-Agent'));

        return Json::ok($response, ['deleted' => true]);
    }
}
