<?php

use LlmsCatalog\Service\CookiePolicy;
use LlmsCatalog\Service\PublicDocumentApplicationService;
use LlmsCatalog\Service\PublicResponseEmitter;
use LlmsCatalog\ValueObject\PublicDocument;

/**
 * Serves the localized llms.txt index (/{locale}/llms.txt).
 *
 * The controller is named "catalogindex" rather than "index" because PrestaShop's
 * Dispatcher::getControllersInDirectory() deliberately skips files named "index.php",
 * so a module front controller whose name is "index" can never be dispatched.
 */
class LlmsCatalogCatalogIndexModuleFrontController extends ModuleFrontController
{
    public $auth = false;
    public $guestAllowed = true;
    private ?PublicDocument $document = null;

    public function initContent()
    {
        CookiePolicy::disallowWriting();
        header_remove('Set-Cookie');
        $module = Module::getInstanceByName('llmscatalog');
        $this->document = (new PublicDocumentApplicationService())->index($this->context, $module);
    }

    public function display()
    {
        if ($this->document === null) $this->document = new PublicDocument('', 'text/plain', 0, 503, false, 0, ['Retry-After' => '60']);
        (new PublicResponseEmitter())->emit($this->document);
    }
}
