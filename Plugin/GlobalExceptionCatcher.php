<?php

namespace JustBetter\Sentry\Plugin;

// phpcs:disable Magento2.CodeAnalysis.EmptyBlock

use JustBetter\Sentry\Helper\Data as SenteryHelper;
use JustBetter\Sentry\Model\ReleaseIdentifier;
use JustBetter\Sentry\Model\SentryInteraction;
use Magento\Framework\AppInterface;
use Magento\Framework\DataObjectFactory;
use Magento\Framework\Event\ManagerInterface as EventManagerInterface;
use Sentry\SentrySdk;
use Sentry\Tracing\Span;
use Sentry\Tracing\SpanContext;
use Sentry\Tracing\Transaction;
use Sentry\Tracing\TransactionContext;
use Throwable;
use function Sentry\startTransaction;

class GlobalExceptionCatcher
{
    /** @var SenteryHelper */
    protected SenteryHelper $sentryHelper;

    /** @var ReleaseIdentifier */
    private ReleaseIdentifier $releaseIdentifier;

    /** @var SentryInteraction */
    private SentryInteraction $sentryInteraction;

    /** @var EventManagerInterface */
    private EventManagerInterface $eventManager;

    /** @var DataObjectFactory */
    private DataObjectFactory $dataObjectFactory;

    /**
     * ExceptionCatcher constructor.
     *
     * @param SenteryHelper $sentryHelper
     * @param ReleaseIdentifier $releaseIdentifier
     * @param SentryInteraction $sentryInteraction
     * @param EventManagerInterface $eventManager
     * @param DataObjectFactory $dataObjectFactory
     */
    public function __construct(
        SenteryHelper         $sentryHelper,
        ReleaseIdentifier     $releaseIdentifier,
        SentryInteraction     $sentryInteraction,
        EventManagerInterface $eventManager,
        DataObjectFactory     $dataObjectFactory
    )
    {
        $this->sentryHelper = $sentryHelper;
        $this->releaseIdentifier = $releaseIdentifier;
        $this->sentryInteraction = $sentryInteraction;
        $this->eventManager = $eventManager;
        $this->dataObjectFactory = $dataObjectFactory;
    }

    protected ?Transaction $profilingTransaction = null;
    protected ?Span $profilingDefaultSpan = null;

    protected function getProfilingTransactionName()
    {
        $uri = $_SERVER['REQUEST_URI'] ?? null;

        if ($uri) {
            $uri = parse_url($uri, PHP_URL_PATH);
        }

        return $uri ?: '*';
    }

    protected function initDefaultProfilingTransaction()
    {
        // Setup context for the full transaction
        $transactionContext = new TransactionContext();
        $transactionContext->setName($this->getProfilingTransactionName());
        $transactionContext->setOp('http.server');

// Start the transaction
        $transaction = startTransaction($transactionContext);

// Set the current transaction as the current span so we can retrieve it later
        SentrySdk::getCurrentHub()->setSpan($transaction);

// Setup the context for the expensive operation span
        $spanContext = new SpanContext();
        $spanContext->setOp('default_operation');

// Start the span
        $span1 = $transaction->startChild($spanContext);

// Set the current span to the span we just started
        SentrySdk::getCurrentHub()->setSpan($span1);

        $this->profilingTransaction = $transaction;
        $this->profilingDefaultSpan = $span1;
    }

    protected function finishDefaultProfilingTransaction()
    {
        if ($this->profilingDefaultSpan) {
            // Finish the span
            $this->profilingDefaultSpan->finish();
            $this->profilingDefaultSpan = null;
        }

        if ($this->profilingTransaction) {
            // Set the current span back to the transaction since we just finished the previous span
            SentrySdk::getCurrentHub()->setSpan($this->profilingTransaction);

            // Finish the transaction, this submits the transaction and it's span to Sentry
            $this->profilingTransaction->finish();

            $this->profilingTransaction = null;
        }
    }

    public function aroundLaunch(AppInterface $subject, callable $proceed)
    {
        if ((!$this->sentryHelper->isActive()) || (!$this->sentryHelper->isPhpTrackingEnabled())) {
            return $proceed();
        }

        $config = $this->dataObjectFactory->create();

        /** @noinspection PhpUndefinedMethodInspection */
        $config->setDsn($this->sentryHelper->getDSNBackend());
        if ($release = $this->releaseIdentifier->getReleaseId()) {
            /** @noinspection PhpUndefinedMethodInspection */
            $config->setRelease($release);
        }

        if ($environment = $this->sentryHelper->getEnvironment()) {
            /** @noinspection PhpUndefinedMethodInspection */
            $config->setEnvironment($environment);
        }

        if ($traces_sample_rate = $this->sentryHelper->getTracingSampleRate()) {
            /** @noinspection PhpUndefinedMethodInspection */
            $config->setTracesSampleRate($traces_sample_rate);
        }

        if ($profiles_sample_rate = $this->sentryHelper->getProfilesSampleRate()) {
            /** @noinspection PhpUndefinedMethodInspection */
            $config->setProfilesSampleRate($profiles_sample_rate);
        }

        $this->eventManager->dispatch('sentry_before_init', [
            'config' => $config,
        ]);

        $this->sentryInteraction->initialize($config->getData());

        $this->initDefaultProfilingTransaction();

        try {
            return $proceed();
        } catch (Throwable $ex) {
            try {
                if ($this->sentryHelper->shouldCaptureException($ex)) {
                    $this->sentryInteraction->captureException($ex);
                }
            } catch (Throwable $bigProblem) {
                // do nothing if sentry fails
            }

            throw $ex;
        } finally {
            $this->finishDefaultProfilingTransaction();
        }
    }
}
