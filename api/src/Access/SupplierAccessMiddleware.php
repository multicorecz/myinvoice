<?php

declare(strict_types=1);

namespace MyInvoice\Access;

use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as Handler;

/**
 * Vynucení per-firemního přístupu (CUSTOM / fork).
 *
 * Běží HNED PO `SupplierScopeMiddleware` (čte jím vystavené `supplier.current_id`). Pokud uživatel
 * na aktuální firmu nemá právo, přepíše scope na jeho první povolenou firmu (graceful — nikdy
 * nevrátí cizí data). Prázdná množina → `supplier.current_id = 0` (akce vrátí prázdno).
 *
 * `SupplierScopeMiddleware.php` se NEEDITUJE — tohle je samostatná vrstva (snadno odebratelná).
 * Gated `access.per_supplier_enabled` (flag OFF → no-op = dnešní chování).
 */
final class SupplierAccessMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly SupplierAccess $access,
    ) {}

    public function process(Request $request, Handler $handler): Response
    {
        if (!$this->access->enabled()) {
            return $handler->handle($request);
        }

        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        if ($user === []) {
            // Nepřihlášeno (public path) — scope neřešíme.
            return $handler->handle($request);
        }

        $current = (int) $request->getAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, 0);
        $allowed = $this->access->allowedIds($user);

        if (!in_array($current, $allowed, true)) {
            $current = $allowed[0] ?? 0;
            $request = $request->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $current);
        }

        return $handler->handle($request);
    }
}
