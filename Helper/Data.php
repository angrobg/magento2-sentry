<?php

namespace JustBetter\Sentry\Helper;

use JustBetter\Sentry\Block\SentryScript;
use Magento\Framework\App\Area;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\App\Helper\Context;
use Magento\Framework\App\ProductMetadataInterface;
use Magento\Framework\App\State;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;

class Data extends AbstractHelper
{
    const XML_PATH_SRS = 'sentry/general/';
    const XML_PATH_SRS_ISSUE_GROUPING = 'sentry/issue_grouping/';

    /**
     * @var StoreManagerInterface
     */
    protected $storeManager;

    /**
     * @var State
     */
    protected $appState;

    /**
     * @var ScopeConfigInterface
     */
    protected $scopeConfig;

    /**
     * @var ProductMetaDataInterface
     */
    protected $productMetadataInterface;

    /**
     * @var DeploymentConfig
     */
    protected $deploymentConfig;

    /**
     * @var array
     */
    protected $config = [];

    /**
     * @var array
     */
    protected $configKeys = [
        'dsn',
        'dsn_backend',
        'logrocket_key',
        'log_level',
        'errorexception_reporting',
        'ignore_exceptions',
        'mage_mode_development',
        'environment',
        'js_sdk_version',
        'tracing_enabled',
        'tracing_sample_rate',
        'profiles_sample_rate',
    ];

    /**
     * Data constructor.
     *
     * @param Context $context
     * @param StoreManagerInterface $storeManager
     * @param State $appState
     */
    public function __construct(
        Context                  $context,
        StoreManagerInterface    $storeManager,
        State                    $appState,
        ProductMetadataInterface $productMetadataInterface,
        DeploymentConfig         $deploymentConfig
    )
    {
        $this->storeManager = $storeManager;
        $this->appState = $appState;
        $this->scopeConfig = $context->getScopeConfig();
        $this->productMetadataInterface = $productMetadataInterface;
        $this->deploymentConfig = $deploymentConfig;
        $this->collectModuleConfig();

        parent::__construct($context);
    }

    /**
     * @return mixed
     */
    public function getDSNBackend()
    {
        return $this->config['dsn_backend'] ?? $this->config['dsn'];
    }

    /**
     * @return mixed
     */
    public function getDSNFrontend()
    {
        return $this->config['dsn'];
    }

    public function getDSN()
    {
        return $this->getDSNFrontend();
    }

    public function isTracingEnabled(): bool
    {
        return $this->config['tracing_enabled'] ?? false;
    }

    public function getTracingSampleRate(): float
    {
        return (float)$this->config['tracing_sample_rate'] ?? 0.2;
    }

    public function getProfilesSampleRate(): float
    {
        return (float)$this->config['profiles_sample_rate'] ?? 0.2;
    }

    /**
     * @return string the version of the js sdk of Sentry
     */
    public function getJsSdkVersion()
    {
        return $this->config['js_sdk_version'] ?: SentryScript::CURRENT_VERSION;
    }

    /**
     * @return mixed
     */
    public function getEnvironment()
    {
        return $this->config['environment'];
    }

    /**
     * @param      $field
     * @param null $storeId
     *
     * @return mixed
     */
    public function getConfigValue($field, $storeId = null)
    {
        return $this->scopeConfig->getValue(
            $field,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * @param      $code
     * @param null $storeId
     *
     * @return mixed
     */
    public function getGeneralConfig($code, $storeId = null)
    {
        return $this->getConfigValue(self::XML_PATH_SRS . $code, $storeId);
    }

    /**
     * @return array
     */
    public function collectModuleConfig()
    {
        $this->config['enabled'] = $this->deploymentConfig->get('sentry') !== null;

        foreach ($this->configKeys as $value) {
            $this->config[$value] = $this->deploymentConfig->get('sentry/' . $value);
        }

        return $this->config;
    }

    /**
     * @return bool
     */
    public function isActive()
    {
        return $this->isActiveWithReason()['active'];
    }

    /**
     * @param string $reason : Reason to tell the user why it's not active (Github issue #53)
     *
     * @return bool
     */
    public function isActiveWithReason()
    {
        $reasons = [];
        $emptyConfig = empty($this->config);
        $configEnabled = array_key_exists('enabled', $this->config) && $this->config['enabled'];
        $dsnNotEmpty = $this->getDSN();
        $productionMode = ($this->isProductionMode() || $this->isOverwriteProductionMode());

        if ($emptyConfig) {
            $reasons[] = __('Config is empty.');
        }
        if (!$configEnabled) {
            $reasons[] = __('Module is not enabled in config.');
        }
        if (!$dsnNotEmpty) {
            $reasons[] = __('DSN is empty.');
        }
        if (!$productionMode) {
            $reasons[] = __('Not in production and development mode is false.');
        }

        return count($reasons) > 0 ? ['active' => false, 'reasons' => $reasons] : ['active' => true];
    }

    /**
     * @return bool
     */
    public function isProductionMode()
    {
        return $this->appState->emulateAreaCode(Area::AREA_GLOBAL, [$this, 'getAppState']) == 'production';
    }

    /**
     * @return string
     */
    public function getAppState()
    {
        return $this->appState->getMode();
    }

    /**
     * @return mixed
     */
    public function isOverwriteProductionMode()
    {
        return array_key_exists('mage_mode_development', $this->config) && $this->config['mage_mode_development'];
    }

    /**
     *  Get the current magento version.
     *
     * @return string
     */
    public function getMagentoVersion()
    {
        return $this->productMetadataInterface->getVersion();
    }

    /**
     * Get the current store.
     */
    public function getStore()
    {
        return $this->storeManager ? $this->storeManager->getStore() : null;
    }

    /**
     * @return bool
     */
    public function isPhpTrackingEnabled()
    {
        return $this->scopeConfig->isSetFlag(static::XML_PATH_SRS . 'enable_php_tracking');
    }

    /**
     * @return bool
     */
    public function isDebuggingEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(static::XML_PATH_SRS . 'enable_debug');
    }

    /**
     * @return ?array
     */
    public function getSessionReplayOnlyUrls(): ?array
    {
        return array_filter(
            explode("\n", trim($this->getConfigValue(static::XML_PATH_SRS . 'enable_session_replay_only_urls')))
        ) ?: null;
    }

    /**
     * @return ?array
     */
    public function getLimitDebugIps(): ?array
    {
        return array_filter(
            explode("\n", trim($this->getConfigValue(static::XML_PATH_SRS . 'limit_debug_ips')))
        ) ?: null;
    }

    /**
     * @return bool
     */
    public function useScriptTag()
    {
        return $this->scopeConfig->isSetFlag(static::XML_PATH_SRS . 'enable_script_tag');
    }

    public function useSessionReplay(): bool
    {
        return $this->scopeConfig->isSetFlag(static::XML_PATH_SRS . 'enable_session_replay');
    }

    public function getReplaySessionSampleRate(): float
    {
        return $this->getConfigValue(static::XML_PATH_SRS . 'replay_session_sample_rate') ?? 0.1;
    }

    public function getReplayErrorSampleRate(): float
    {
        return $this->getConfigValue(static::XML_PATH_SRS . 'replay_error_sample_rate') ?? 1;
    }

    public function getReplayBlockMedia(): bool
    {
        return $this->getConfigValue(static::XML_PATH_SRS . 'replay_block_media') ?? true;
    }

    public function getReplayMaskText(): bool
    {
        return $this->getConfigValue(static::XML_PATH_SRS . 'replay_mask_text') ?? true;
    }

    /**
     * @param $blockName
     *
     * @return bool
     */
    public function showScriptTagInThisBlock($blockName)
    {
        $config = $this->getGeneralConfig('script_tag_placement');
        if (!$config) {
            return false;
        }

        $name = 'sentry.' . $config;

        return $name == $blockName;
    }

    /**
     * @return mixed
     */
    public function getLogrocketKey()
    {
        return $this->config['logrocket_key'];
    }

    /**
     * @return bool
     */
    public function useLogrocket()
    {
        return $this->scopeConfig->isSetFlag(static::XML_PATH_SRS . 'use_logrocket') &&
            array_key_exists('logrocket_key', $this->config) &&
            $this->config['logrocket_key'] != null;
    }

    /**
     * @return bool
     */
    public function useLogrocketIdentify()
    {
        return $this->scopeConfig->isSetFlag(static::XML_PATH_SRS . 'logrocket_identify');
    }

    /**
     * @return bool
     */
    public function stripStaticContentVersion()
    {
        return $this->scopeConfig->isSetFlag(static::XML_PATH_SRS_ISSUE_GROUPING . 'strip_static_content_version');
    }

    /**
     * @return bool
     */
    public function stripStoreCode()
    {
        return $this->scopeConfig->isSetFlag(static::XML_PATH_SRS_ISSUE_GROUPING . 'strip_store_code');
    }

    /**
     * @return int
     */
    public function getErrorExceptionReporting()
    {
        return $this->config['errorexception_reporting'] ?? E_ALL;
    }

    /**
     * @return int
     */
    public function getIgnoreExceptions()
    {
        return (array)($this->config['ignore_exceptions'] ?? []);
    }

    /**
     * @param \Throwable $ex
     *
     * @return bool
     */
    public function shouldCaptureException(\Throwable $ex)
    {
        if ($ex instanceof \ErrorException && !($ex->getSeverity() & $this->getErrorExceptionReporting())) {
            return false;
        }

        if (in_array(get_class($ex), $this->getIgnoreExceptions())) {
            return false;
        }

        return true;
    }
}
