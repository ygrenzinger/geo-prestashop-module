<?php

namespace LlmsCatalog\Controller\Admin;

use Configuration;
use Language;
use LlmsCatalog\Service\CacheManager;
use LlmsCatalog\Service\LanguageResolver;
use LlmsCatalog\Service\Settings;
use LlmsCatalog\Service\UrlBuilder;
use PrestaShopBundle\Controller\Admin\PrestaShopAdminController;
use PrestaShopBundle\Security\Attribute\AdminSecurity;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class ConfigurationController extends PrestaShopAdminController
{
    public function __construct(private FormFactoryInterface $formFactory)
    {
    }

    #[AdminSecurity("is_granted('read', request.get('_legacy_controller')) or is_granted('update', request.get('_legacy_controller'))")]
    public function indexAction(Request $request): Response
    {
        return $this->configurationAction($request);
    }

    #[AdminSecurity("is_granted('update', request.get('_legacy_controller'))")]
    public function saveAction(Request $request): Response
    {
        return $this->configurationAction($request);
    }

    private function configurationAction(Request $request): Response
    {
        $settings = new Settings();
        $data = $settings->formData();
        $builder = $this->formFactory->createNamedBuilder('llmscatalog', FormType::class, $data, [
            'method' => 'POST',
            'action' => $this->generateUrl('llmscatalog_configuration_save'),
            'translation_domain' => 'Modules.Llmscatalog.Admin',
        ]);

        $builder
            ->add('enabled', CheckboxType::class, ['required' => false, 'label' => 'Publish llms.txt'])
            ->add('max_products', IntegerType::class, ['label' => 'Maximum products', 'required' => true, 'invalid_message' => 'Enter a valid number.'])
            ->add('complete_indexes', CheckboxType::class, ['required' => false, 'label' => 'Enable complete catalog indexes'])
            ->add('max_featured_categories', IntegerType::class, ['label' => 'Maximum featured categories', 'required' => true, 'invalid_message' => 'Enter a valid number.'])
            ->add('max_bytes', IntegerType::class, ['label' => 'Maximum output bytes', 'required' => true, 'invalid_message' => 'Enter a valid number.'])
            ->add('cache_ttl', IntegerType::class, ['label' => 'Cache TTL in seconds', 'required' => true, 'invalid_message' => 'Enter a valid number.'])
            ->add('include_categories', CheckboxType::class, ['required' => false, 'label' => 'Include categories'])
            ->add('include_products', CheckboxType::class, ['required' => false, 'label' => 'Include products'])
            ->add('include_price', CheckboxType::class, ['required' => false, 'label' => 'Include public prices'])
            ->add('include_availability', CheckboxType::class, ['required' => false, 'label' => 'Include availability'])
            ->add('inject_links', CheckboxType::class, [
                'required' => false,
                'label' => 'Publish discovery metadata on catalog pages',
                'help' => 'Adds standard describedby links to the homepage and category pages, and alternate plus describedby links to public product pages. Links are published in both HTML metadata and HTTP response headers.',
            ]);

        $shop = \Context::getContext()->shop;
        $groupId = (int) ($shop->id_shop_group ?? 0);
        $shopId = (int) ($shop->id ?? 0);
        $builder->add('augment_organization', CheckboxType::class, [
            'required' => false,
            'label' => 'Add Organization JSON-LD only when no compatible entity exists (experimental)',
            'help' => 'Opt-in per shop. Existing or malformed JSON-LD is left unchanged.',
            'data' => (bool) Configuration::get('LLMSCATALOG_AUGMENT_ORGANIZATION', null, $groupId, $shopId),
        ]);
        $builder->add('augment_product', CheckboxType::class, [
            'required'=>false,
            'label'=>'Complete missing Product Offer data on canonical pages (experimental)',
            'help'=>'Opt-in per shop. Missing Offer fields are completed only on one canonical product entity; conflicting, malformed, and ambiguous markup is left unchanged.',
            'data'=>(bool)Configuration::get('LLMSCATALOG_AUGMENT_PRODUCT',null,$groupId,$shopId),
        ]);
        $builder->add('augment_breadcrumb', CheckboxType::class, [
            'required'=>false,
            'label'=>'Add Breadcrumb JSON-LD only when no compatible entity exists (experimental)',
            'help'=>'Opt-in per shop. Existing or malformed JSON-LD and ambiguous pages are never modified.',
            'data'=>(bool)Configuration::get('LLMSCATALOG_AUGMENT_BREADCRUMB',null,$groupId,$shopId),
        ]);
        $builder->add('augmentation_preview_confirmed', CheckboxType::class, ['required'=>false,'mapped'=>false,'label'=>'I reviewed the current audit report and proposed augmentation preview']);

        $builder->add('rich_product', CheckboxType::class, ['required' => false, 'label' => 'Enable rich product Markdown (experimental)']);
        $builder->add('product_providers', TextType::class, ['required' => false, 'label' => 'Enabled product provider IDs (comma separated)']);
        foreach (['ratings'=>'Ratings','shipping'=>'Shipping','returns'=>'Returns','warranty'=>'Warranty'] as $section=>$label) $builder->add('product_'.$section, CheckboxType::class, ['required'=>false,'label'=>'Enable provider section: '.$label]);
        foreach ([
            'product_max_bytes' => 'Maximum product bytes', 'product_max_variants' => 'Maximum variants',
            'product_max_images' => 'Maximum images', 'product_max_specs' => 'Maximum specifications',
            'product_max_categories' => 'Maximum assigned categories', 'product_max_pack_items' => 'Maximum pack components',
            'product_max_custom_fields' => 'Maximum customization fields',
        ] as $field => $label) $builder->add($field, IntegerType::class, ['label' => $label, 'required' => true, 'invalid_message' => 'Enter a valid number.']);

        foreach ($this->adminLanguages() as $language) {
            $id = (int) $language['id_lang'];
            $locale = (string) $language['locale'];
            $builder
                ->add('title_' . $id, TextType::class, ['label' => 'Title (%locale%)', 'label_translation_parameters' => ['%locale%' => $locale], 'required' => false])
                ->add('summary_' . $id, TextareaType::class, ['label' => 'Summary (%locale%)', 'label_translation_parameters' => ['%locale%' => $locale], 'required' => false]);
        }

        $form = $builder->getForm();
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $enabling = !empty($form->get('augment_organization')->getData())
                || !empty($form->get('augment_product')->getData())
                || !empty($form->get('augment_breadcrumb')->getData());
            $languageId = (int) (\Context::getContext()->language->id ?? 0);
            $audit = $request->getSession()->get('llmscatalog_audit_' . $shopId . '_' . $languageId);
            $hasBlocker = false;
            foreach (($audit['findings'] ?? []) as $finding) {
                if (($finding['severity'] ?? '') === 'blocker') {
                    $hasBlocker = true;
                    break;
                }
            }
            if ($enabling && (!$audit || empty($audit['complete']) || $hasBlocker || !$form->get('augmentation_preview_confirmed')->getData())) {
                $form->get('augmentation_preview_confirmed')->addError(new \Symfony\Component\Form\FormError('Complete an audit with no blocker findings and review the preview before enabling augmentation.'));
            } else {
                $settings->saveFormData($form->getData());
                Configuration::updateValue(
                    'LLMSCATALOG_AUGMENT_ORGANIZATION',
                    !empty($form->get('augment_organization')->getData()) ? '1' : '0',
                    false,
                    $groupId,
                    $shopId
                );
                Configuration::updateValue(
                    'LLMSCATALOG_AUGMENT_PRODUCT',
                    !empty($form->get('augment_product')->getData()) ? '1' : '0',
                    false,
                    $groupId,
                    $shopId
                );
                Configuration::updateValue(
                    'LLMSCATALOG_AUGMENT_BREADCRUMB',
                    !empty($form->get('augment_breadcrumb')->getData()) ? '1' : '0',
                    false,
                    $groupId,
                    $shopId
                );
                (new CacheManager())->clearAll();
                $this->addFlash('success', $this->trans('LLMs.txt settings updated.', [], 'Modules.Llmscatalog.Admin'));
                return $this->redirectToRoute('llmscatalog_configuration');
            }
        }

        $context = \Context::getContext();
        $urls = new UrlBuilder($context);
        $localizedUrls = [];
        $missingFullLocale = false;
        foreach (Language::getLanguages(true, (int) $context->shop->id) as $language) {
            $id = (int) $language['id_lang'];
            $locale = $urls->canonicalLocale($id);
            if (strpos($locale, '-') === false) {
                $missingFullLocale = true;
            }
            $localizedUrls[] = ['locale' => $locale, 'url' => $urls->indexUrl($id)];
        }

        return $this->render('@Modules/llmscatalog/views/templates/admin/configuration.html.twig', [
            'form' => $form->createView(),
            // Mirror the standard module configuration page so the merchant always
            // has an explicit way back to the module list.
            'layoutHeaderToolbarBtn' => [
                'module-back' => [
                    'href' => $this->generateUrl('admin_module_manage'),
                    'desc' => $this->trans('Back', [], 'Admin.Global'),
                    'icon' => 'arrow_back',
                    'help' => $this->trans('Module Manager', [], 'Admin.Navigation.Menu'),
                ],
            ],
            'can_update_settings' => $this->isGranted('update', (string) $request->attributes->get('_legacy_controller')),
            'shop_name' => (string) Configuration::get('PS_SHOP_NAME'),
            'root_index_url' => $urls->rootIndexUrl(),
            'localized_index_urls' => $localizedUrls,
            'missing_full_locale' => $missingFullLocale,
            'configured_product_limit' => $settings->configuredMaxProducts(),
            'effective_product_limit' => $settings->maxProducts(),
            'configured_byte_limit' => $settings->maxBytes(),
            'translation_domain' => 'Modules.Llmscatalog.Admin',
        ]);
    }

    private function adminLanguages(): array
    {
        $context = \Context::getContext();
        if (\Shop::isFeatureActive() && \Shop::getContext() === \Shop::CONTEXT_SHOP) {
            return Language::getLanguages(true, (int) $context->shop->id);
        }
        if (\Shop::isFeatureActive() && \Shop::getContext() === \Shop::CONTEXT_GROUP) {
            $result = [];
            foreach (\Shop::getShops(true, (int) $context->shop->id_shop_group) as $shop) {
                foreach (Language::getLanguages(true, (int) $shop['id_shop']) as $language) {
                    $result[(int) $language['id_lang']] = $language;
                }
            }
            return array_values($result);
        }
        return Language::getLanguages(true);
    }
}
