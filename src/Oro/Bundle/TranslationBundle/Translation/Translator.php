<?php

namespace Oro\Bundle\TranslationBundle\Translation;

use Doctrine\Common\Cache\Cache;
use Doctrine\Common\Cache\ClearableCache;

use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Translation\Loader\LoaderInterface;
use Symfony\Component\Translation\MessageSelector;
use Symfony\Bundle\FrameworkBundle\Translation\Translator as BaseTranslator;

use Oro\Bundle\TranslationBundle\Strategy\TranslationStrategyProvider;

/**
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 */
class Translator extends BaseTranslator
{
    const DEFAULT_LOCALE = 'en';

    /** @var DynamicTranslationMetadataCache|null */
    protected $databaseTranslationMetadataCache;

    /** @var Cache|null */
    protected $resourceCache;

    /**
     * @var array
     *  [
     *      locale => [
     *          [
     *              'resource' => DynamicResourceInterface,
     *              'format'   => string,
     *              'code'     => string,
     *              'domain'   => string
     *          ],
     *          ...
     *      ],
     *      ...
     *  ]
     */
    protected $dynamicResources = [];

    /** @var bool */
    protected $installed;

    /** @var string|null */
    protected $strategyName;

    /** @var MessageSelector */
    protected $messageSelector;

    /** @var array */
    protected $originalOptions;

    /**
     * {@inheritdoc}
     */
    public function __construct(
        ContainerInterface $container,
        MessageSelector $messageSelector,
        $loaderIds = [],
        array $options = []
    ) {
        $this->messageSelector = $messageSelector;
        $this->originalOptions = $options;

        parent::__construct($container, $messageSelector, $loaderIds, $options);
    }

    /**
     * Collector of translations
     *
     * Collects all translations for corresponded domains and locale,
     * takes in account fallback of locales.
     * Method is used for exposing of collected translations.
     *
     * @param array       $domains list of required domains, by default empty, means all domains
     * @param string|null $locale  locale of translations, by default is current locale
     *
     * @return array
     */
    public function getTranslations(array $domains = array(), $locale = null)
    {
        // if new strategy was selected
        if ($this->getStrategyProvider()->getStrategy()->getName() !== $this->strategyName) {
            $this->applyCurrentStrategy();
        }

        if (null === $locale) {
            $locale = $this->getLocale();
        }

        if (!isset($this->catalogues[$locale])) {
            $this->loadCatalogue($locale);
        }

        $fallbackCatalogues   = array();
        $fallbackCatalogues[] = $catalogue = $this->catalogues[$locale];
        while ($catalogue = $catalogue->getFallbackCatalogue()) {
            $fallbackCatalogues[] = $catalogue;
        }

        $domains      = array_flip($domains);
        $translations = array();
        for ($i = count($fallbackCatalogues) - 1; $i >= 0; $i--) {
            $localeTranslations = $fallbackCatalogues[$i]->all();
            // if there are domains -> filter only their translations
            if ($domains) {
                $localeTranslations = array_intersect_key($localeTranslations, $domains);
            }
            foreach ($localeTranslations as $domain => $domainTranslations) {
                if (!empty($translations[$domain])) {
                    $translations[$domain] = array_merge($translations[$domain], $domainTranslations);
                } else {
                    $translations[$domain] = $domainTranslations;
                }
            }
        }

        return $translations;
    }

    /**
     * Checks if the given message has a translation.
     *
     * @param string $id     The message id (may also be an object that can be cast to string)
     * @param string $domain The domain for the message
     * @param string $locale The locale
     *
     * @return bool Whether string have translation
     */
    public function hasTrans($id, $domain = null, $locale = null)
    {
        if (null === $locale) {
            $locale = $this->getLocale();
        }

        if (null === $domain) {
            $domain = 'messages';
        }

        if (!isset($this->catalogues[$locale])) {
            $this->loadCatalogue($locale);
        }

        $id = (string)$id;

        $catalogue = $this->catalogues[$locale];
        $result    = $catalogue->defines($id, $domain);
        while (!$result && $catalogue = $catalogue->getFallbackCatalogue()) {
            $result = $catalogue->defines($id, $domain);
        }

        return $result;
    }

    /**
     * {@inheritdoc}
     */
    public function addLoader($format, LoaderInterface $loader)
    {
        if (null !== $this->resourceCache) {
            // wrap a resource loader by a caching loader to prevent loading of the same resource several times
            // it strongly decreases a translation catalogue loading time
            // for example a time of translation cache warming up is decreased in about 4 times
            $loader = new CachingTranslationLoader($loader, $this->resourceCache);
        }
        parent::addLoader($format, $loader);
    }

    /**
     * {@inheritdoc}
     */
    public function getCatalogue($locale = null)
    {
        // if new strategy was selected
        if ($this->getStrategyProvider()->getStrategy()->getName() !== $this->strategyName) {
            $this->applyCurrentStrategy();
        }

        return parent::getCatalogue($locale);
    }

    /**
     * {@inheritdoc}
     */
    public function warmUp($cacheDir)
    {
        $this->applyCurrentStrategy();

        parent::warmUp($cacheDir);
    }

    /**
     * Rebuilds all cached message catalogs, w/o any delay at clients side
     */
    public function rebuildCache()
    {
        $cacheDir = $this->originalOptions['cache_dir'];

        if (!$cacheDir || !is_dir($cacheDir)) {
            $this->warmUp($cacheDir);
            return;
        }

        $tmpDir = $cacheDir . DIRECTORY_SEPARATOR . uniqid('CACHE_', true);

        $options = array_merge($this->originalOptions, ['cache_dir' => $tmpDir]);

        $translator = new static($this->container, $this->messageSelector, $this->loaderIds, $options);
        $translator->setDatabaseMetadataCache($this->databaseTranslationMetadataCache);

        $translator->warmUp($tmpDir);

        $iterator = new \IteratorIterator(new \DirectoryIterator($tmpDir));
        foreach ($iterator as $path) {
            if (!$path->isFile()) {
                continue;
            }
            copy($path->getPathName(), $cacheDir . DIRECTORY_SEPARATOR . $path->getFileName());
            unlink($path->getPathName());
        }

        rmdir($tmpDir);

        // cleanup local cache
        $this->catalogues = [];
    }

    /**
     * Sets a cache of dynamic translation metadata
     *
     * @param DynamicTranslationMetadataCache $cache
     */
    public function setDatabaseMetadataCache(DynamicTranslationMetadataCache $cache)
    {
        $this->databaseTranslationMetadataCache = $cache;
    }

    /**
     * Sets a cache of loaded translation resources
     *
     * @param Cache $cache
     */
    public function setResourceCache(Cache $cache)
    {
        $this->resourceCache = $cache;
    }

    protected function applyCurrentStrategy()
    {
        $strategyProvider = $this->getStrategyProvider();
        $strategy = $strategyProvider->getStrategy();

        // store current strategy name to skip all following requests to it
        $this->strategyName = $strategy->getName();

        // use current set of fallback locales to build translation cache
        $fallbackLocales = $strategyProvider->getAllFallbackLocales($strategy);
        $this->setFallbackLocales($fallbackLocales);

        // clear catalogs to generate new ones for new strategy
        $this->catalogues = [];
    }

    /**
     * {@inheritdoc}
     */
    protected function computeFallbackLocales($locale)
    {
        $strategyProvider = $this->getStrategyProvider();
        $strategy = $strategyProvider->getStrategy();

        return $strategyProvider->getFallbackLocales($strategy, $locale);
    }

    /**
     * {@inheritdoc}
     */
    protected function loadCatalogue($locale)
    {
        $this->initializeDynamicResources($locale);
        parent::loadCatalogue($locale);
    }

    /**
     * {@inheritdoc}
     */
    protected function initialize()
    {
        parent::initialize();
        // add dynamic resources to the end to make sure that they override static translations
        $this->registerDynamicResources();
    }

    /**
     * Initializes dynamic translation resources
     *
     * @param string $locale
     */
    protected function initializeDynamicResources($locale)
    {
        $this->ensureDynamicResourcesLoaded($locale);

        // check if any dynamic resource is changed and update translation catalogue if needed
        if (!empty($this->dynamicResources[$locale])) {
            $catalogueFile = $this->options['cache_dir']
                . '/catalogue.' . $locale . '.' . sha1(serialize($this->getFallbackLocales())) . '.php';
            if (is_file($catalogueFile)) {
                $time = filemtime($catalogueFile);
                foreach ($this->dynamicResources[$locale] as $item) {
                    /** @var DynamicResourceInterface $dynamicResource */
                    $dynamicResource = $item['resource'];
                    if (!$dynamicResource->isFresh($time)) {
                        // remove translation catalogue to allow parent class to rebuild it
                        unlink($catalogueFile);
                        // make sure that translations will be loaded from source resources
                        if ($this->resourceCache instanceof ClearableCache) {
                            $this->resourceCache->deleteAll();
                        }
                        break;
                    }
                }
            }
        }
    }

    /**
     * Adds dynamic translation resources to the translator
     */
    protected function registerDynamicResources()
    {
        $defaultLocale = isset($this->catalogues[Translator::DEFAULT_LOCALE])
            ? $this->catalogues[Translator::DEFAULT_LOCALE]
            : null;

        foreach ($this->dynamicResources as $items) {
            if (!$defaultLocale) {
                foreach ($items as $item) {
                    $this->addResource($item['format'], $item['resource'], $item['code'], $item['domain']);
                }
            }
        }

        //prevents loding default locale many times (Default locale should be is default fallback locale)
        if ($defaultLocale && !isset($this->catalogues[Translator::DEFAULT_LOCALE])) {
            $this->catalogues[Translator::DEFAULT_LOCALE] = $defaultLocale;
        }
    }

    /**
     * Makes sure that dynamic translation resources are added to $this->dynamicResources
     *
     * @param string $locale
     */
    protected function ensureDynamicResourcesLoaded($locale)
    {
        if (null !== $this->databaseTranslationMetadataCache && $this->isInstalled()) {
            $hasDatabaseResources = false;
            if (!empty($this->dynamicResources[$locale])) {
                foreach ($this->dynamicResources[$locale] as $item) {
                    if ($item['format'] === 'oro_database_translation') {
                        $hasDatabaseResources = true;
                        break;
                    }
                }
            }
            if (!$hasDatabaseResources && $this->checkDatabase()) {
                $locales = $this->getFallbackLocales();
                array_unshift($locales, $locale);
                $locales = array_unique($locales);

                $availableDomainsData = $this->container->get('oro_translation.manager.translation')
                    ->findAvailableDomainsForLocales($locales);
                foreach ($availableDomainsData as $item) {
                    $item['resource'] = new OrmTranslationResource(
                        $item['code'],
                        $this->databaseTranslationMetadataCache
                    );
                    $item['format']   = 'oro_database_translation';

                    $this->dynamicResources[$locale][] = $item;
                }
            }
        }
    }

    /**
     * Check if the platform is installed
     *
     * @return bool
     */
    protected function isInstalled()
    {
        return $this->container->hasParameter('installed') && $this->container->getParameter('installed');
    }

    /**
     * Checks whether the translations table exists in the database
     *
     * @return bool
     */
    protected function checkDatabase()
    {
        if (null === $this->installed) {
            $this->installed = (bool)$this->container->getParameter('installed');
        }

        return $this->installed;
    }

    /**
     * @return TranslationStrategyProvider
     */
    protected function getStrategyProvider()
    {
        // can't inject strategy provider directly because container creates new instances for each injected service
        return $this->container->get('oro_translation.strategy.provider');
    }
}
