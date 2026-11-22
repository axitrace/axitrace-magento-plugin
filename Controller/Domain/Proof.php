<?php

declare(strict_types=1);

namespace AxiTrace\Tracking\Controller\Domain;

use AxiTrace\Tracking\Model\Config\ModuleConfig;
use AxiTrace\Tracking\Model\Domain\DomainProof;
use AxiTrace\Tracking\Model\Domain\StoreHosts;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Storefront endpoint: GET /axitrace/domain/proof?nonce=<nonce>.
 *
 * Answers AxiTrace's store domain proof (see DomainProof) with the keys of the
 * store view Magento resolved for the request. Needs no session, form key or
 * login, and is never cached (Cache-Control: no-store), so a full-page cache or
 * Varnish cannot serve another nonce's answer.
 *
 * The signature uses the host AxiTrace claimed: the request host when it is one
 * of this store view's base URL hosts, otherwise the secure base URL host (for a
 * request that reached the store through a redirect).
 */
class Proof implements HttpGetActionInterface
{
    public function __construct(
        private readonly HttpRequest $request,
        private readonly JsonFactory $jsonFactory,
        private readonly StoreManagerInterface $storeManager,
        private readonly ModuleConfig $config,
    ) {
    }

    public function execute(): Json
    {
        $store = $this->storeManager->getStore();
        $storeId = (int) $store->getId();

        $answer = DomainProof::answer(
            (string) $this->request->getQueryValue('nonce', ''),
            trim($this->config->getWorkspacePublicKey($storeId)),
            $this->config->getDomainProofSecretKey($storeId),
            $store instanceof Store ? $this->claimedHost($store) : ''
        );

        $result = $this->jsonFactory->create();
        $result->setHttpResponseCode($answer['status']);
        $result->setHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0', true);
        $result->setHeader('Pragma', 'no-cache', true);
        $result->setHeader('Expires', '0', true);
        $result->setHeader('X-Robots-Tag', 'noindex, nofollow', true);
        $result->setData($answer['body']);

        return $result;
    }

    private function claimedHost(Store $store): string
    {
        $hosts = StoreHosts::hostsOf($store);
        $requestHost = StoreHosts::normalizeHostHeader((string) $this->request->getServerValue('HTTP_HOST', ''));
        if ($requestHost !== '' && in_array($requestHost, $hosts, true)) {
            return $requestHost;
        }

        return $hosts[0] ?? '';
    }
}
