<?php

use LlmsCatalog\Service\PublicDocumentApplicationService;
use LlmsCatalog\Service\PublicResponseEmitter;
use LlmsCatalog\ValueObject\PublicDocument;

class LlmsCatalogRootModuleFrontController extends ModuleFrontController
{
    public $auth = false;
    public $guestAllowed = true;
    private ?PublicDocument $document = null;

    public function initContent()
    {
        if (class_exists('Cookie')) Cookie::disallowWriting();
        header_remove('Set-Cookie');
        $module = Module::getInstanceByName('llmscatalog');
        $this->document = (new PublicDocumentApplicationService())->root($this->context, $module);
    }

    public function display()
    {
        if ($this->document === null) $this->document = new PublicDocument('', 'text/plain', 0, 503, false, 0, ['Retry-After' => '60']);
        (new PublicResponseEmitter())->emit($this->document);
    }
}
