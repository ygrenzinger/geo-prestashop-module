<?php
namespace LlmsCatalog\Controller\Admin;

use LlmsCatalog\Audit\AuditApplicationService;
use LlmsCatalog\Audit\AuditResponseFactory;
use LlmsCatalog\Service\SafeDiagnosticLogger;
use PrestaShopBundle\Controller\Admin\PrestaShopAdminController;
use PrestaShopBundle\Security\Attribute\AdminSecurity;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/** HTTP adapter for the back-office audit and report routes. */
final class AuditController extends PrestaShopAdminController
{
    public function __construct(
        private readonly CsrfTokenManagerInterface $csrf,
        private readonly AuditApplicationService $audit,
        private readonly AuditResponseFactory $responses
    ) {}

    #[AdminSecurity("is_granted('read', request.get('_legacy_controller'))")]
    public function runAction(Request $request): Response
    {
        if (!$request->isMethod('POST')) {
            return new JsonResponse(['error' => 'POST required'], 405);
        }

        $payload = json_decode((string) $request->getContent(), true) ?: $request->request->all();
        if (!$this->validToken((string) ($payload['_token'] ?? ''))) {
            return new JsonResponse(['error' => 'Invalid CSRF token'], 403);
        }

        $context = \Context::getContext();
        $shopId = (int) $context->shop->id;
        $languageId = (int) $context->language->id;
        $locale = (string) $context->language->locale;
        $session = $request->getSession();
        $action = (string) ($payload['action'] ?? '');

        if ($action === 'feed') {
            return $this->compareFeed($request, $context, $session, $shopId, $languageId, $locale);
        }

        $result = $this->audit->run($context, $session, $shopId, $languageId, $locale, $action);
        if (!empty($result['cancelled'])) {
            return new JsonResponse(['cancelled' => true]);
        }

        $response = $this->responses->batch(
            $shopId,
            $locale,
            $result['complete'],
            (int) ($result['state']['offset'] ?? 0),
            $result['total'],
            $result['state']
        );
        $response['download'] = $this->generateUrl('llmscatalog_audit_report');
        return new JsonResponse($response);
    }

    #[AdminSecurity("is_granted('read', request.get('_legacy_controller'))")]
    public function reportAction(Request $request): Response
    {
        $context = \Context::getContext();
        $shopId = (int) $context->shop->id;
        $languageId = (int) $context->language->id;
        $state = $this->audit->currentReport($request->getSession(), $shopId, $languageId);
        if ($state === null) {
            return new JsonResponse(['error' => 'No current audit report'], 404);
        }

        $response = new JsonResponse($this->responses->download($shopId, (string) $context->language->locale, $state));
        $response->headers->set('Content-Disposition', 'attachment; filename="llmscatalog-audit.json"');
        return $response;
    }

    private function compareFeed(Request $request, \Context $context, SessionInterface $session, int $shopId, int $languageId, string $locale): Response
    {
        $file = $request->files->get('feed');
        if (!$file || !$file->isValid() || $file->getSize() > 2097152) {
            return new JsonResponse(['error' => 'Upload a valid feed no larger than 2 MiB'], 400);
        }
        if (!$this->validToken((string) $request->request->get('_token', ''))) {
            return new JsonResponse(['error' => 'Invalid CSRF token'], 403);
        }

        $format = strtolower($file->getClientOriginalExtension()) === 'tsv' ? 'tsv' : 'xml';
        try {
            $result = $this->audit->compareFeed(
                $context,
                $session,
                $shopId,
                $languageId,
                $locale,
                (string) file_get_contents($file->getPathname()),
                $format
            );
        } catch (\Throwable $exception) {
            SafeDiagnosticLogger::log('admin.audit.feed-compare', $shopId, $exception);
            return new JsonResponse(['error' => 'Feed could not be parsed within the configured limits'], 400);
        }

        $response = $this->responses->feed($result['feed_rows'], $result['catalog_sample'], $result['state']);
        $response['download'] = $this->generateUrl('llmscatalog_audit_report');
        return new JsonResponse($response);
    }

    private function validToken(string $token): bool
    {
        return $this->csrf->isTokenValid(new CsrfToken('llmscatalog-audit', $token));
    }
}
