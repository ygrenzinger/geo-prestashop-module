<?php

use LlmsCatalog\Service\CookiePolicy;
use LlmsCatalog\Service\PublicDocumentApplicationService;
use LlmsCatalog\Service\PublicResponseEmitter;
use LlmsCatalog\ValueObject\PublicDocument;

class LlmsCatalogProductModuleFrontController extends ModuleFrontController
{
    public $auth = false;
    public $guestAllowed = true;
    private ?PublicDocument $document = null;

    public function initContent()
    {
        CookiePolicy::disallowWriting();
        header_remove('Set-Cookie');
        $module = Module::getInstanceByName('llmscatalog');
        $this->document = (new PublicDocumentApplicationService())->product($this->context, $module);
    }

    public function display()
    {
        if ($this->document === null) $this->document = new PublicDocument('', 'text/markdown', 0, 503, false, 0, ['Retry-After' => '60']);
        (new PublicResponseEmitter())->emit($this->document);
    }
}
