<?php

declare(strict_types=1);
/**
 * Shopware 5
 * Copyright (c) shopware AG
 *
 * According to our dual licensing model, this program can be used either
 * under the terms of the GNU Affero General Public License, version 3,
 * or under a proprietary license.
 *
 * The texts of the GNU Affero General Public License with an additional
 * permission and of our proprietary license can be found at and
 * in the LICENSE file you have received along with this program.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * "Shopware" is a registered trademark of shopware AG.
 * The licensing of the program under the AGPLv3 does not imply a
 * trademark license. Therefore any rights, title and interest in
 * our trademarks remain entirely with us.
 */

namespace Shopware\Tests\Mink\Tests\General\Helpers;

use Behat\Mink\Driver\DriverInterface;
use Behat\Mink\Driver\Selenium2Driver;
use Behat\Mink\Mink;
use Behat\Mink\Session;
use Behat\MinkExtension\Context\MinkAwareContext;
use RuntimeException;
use SensioLabs\Behat\PageObjectExtension\Context\PageObjectContext;
use SensioLabs\Behat\PageObjectExtension\PageObject\Page;
use Shopware\Behat\ShopwareExtension\Context\KernelAwareContext;
use Shopware\Kernel;
use Shopware\Tests\Mink\Page\Helper\Elements\MultipleElement;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Throwable;

class SubContext extends PageObjectContext implements KernelAwareContext, MinkAwareContext
{
    private Mink $mink;

    private array $minkParameters;

    private Kernel $kernel;

    /**
     * Sets Mink instance.
     *
     * @param Mink $mink Mink session manager
     */
    public function setMink(Mink $mink): void
    {
        $this->mink = $mink;
    }

    public function getMink(): Mink
    {
        return $this->mink;
    }

    /**
     * Sets parameters provided for Mink.
     */
    public function setMinkParameters(array $parameters): void
    {
        $this->minkParameters = $parameters;
    }

    /**
     * Returns specific mink parameter.
     */
    public function getMinkParameter(string $name): ?string
    {
        return $this->minkParameters[$name] ?? null;
    }

    public function getSession(): Session
    {
        return $this->mink->getSession();
    }

    public function getDriver(): DriverInterface
    {
        return $this->getSession()->getDriver();
    }

    public function setKernel(Kernel $kernel): void
    {
        $this->kernel = $kernel;
    }

    /**
     * Returns HttpKernel service container.
     */
    public function getContainer(): ContainerInterface
    {
        return $this->kernel->getContainer();
    }

    /**
     * @template TService of object
     *
     * @param class-string<TService> $id
     *
     * @return TService
     */
    protected function getService(string $id): object
    {
        return $this->getContainer()->get($id);
    }

    /**
     * @template TElement of MultipleElement
     *
     * @param Page                   $page        Parent page
     * @param class-string<TElement> $elementName Name of the element
     * @param int                    $instance    Instance of the element
     *
     * @return TElement
     */
    protected function getMultipleElement(Page $page, string $elementName, int $instance = 1): MultipleElement
    {
        $element = $this->getElement($elementName);
        if (!$element instanceof $elementName) {
            Helper::throwException(sprintf('Element expected to be a %s', $elementName));
        }

        $element->setParent($page);

        if ($instance > 1) {
            $element = $element->setInstance($instance);
        }

        return $element;
    }

    protected function isBrowserSessionAlive(): bool
    {
        $session = $this->getSession();
        if (!$session->isStarted()) {
            return false;
        }

        try {
            $session->evaluateScript('return true');

            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    protected function restartBrowserSession(): void
    {
        $session = $this->getSession();

        try {
            if ($session->isStarted()) {
                $session->stop();
            }
        } catch (Throwable $e) {
            // Session may already be gone after a browser crash.
        }

        $session->start();
    }

    protected function recoverBrowserSessionIfNeeded(): void
    {
        if (!$this->getDriver() instanceof Selenium2Driver) {
            return;
        }

        if ($this->isBrowserSessionAlive()) {
            return;
        }

        $this->restartBrowserSession();
    }

    /**
     * @template TReturn
     *
     * @param callable(): TReturn $action
     *
     * @return TReturn
     */
    protected function executeWithBrowserRecovery(callable $action)
    {
        try {
            return $action();
        } catch (Throwable $e) {
            if (!$this->isRecoverableWebDriverException($e) && !$this->isSpinTimeoutException($e)) {
                throw $e;
            }
        }

        if ($this->getDriver() instanceof Selenium2Driver) {
            $this->restartBrowserSession();
        } else {
            $this->recoverBrowserSessionIfNeeded();
        }

        return $action();
    }

    protected function isSpinTimeoutException(Throwable $e): bool
    {
        return $e instanceof RuntimeException
            && str_contains($e->getMessage(), 'Spin function timed out');
    }

    protected function isRecoverableWebDriverException(Throwable $e): bool
    {
        $message = strtolower($e->getMessage());

        foreach (
            [
                'session deleted',
                'tab crashed',
                'invalid session id',
                'cannot determine loading status',
                'chrome not reachable',
                'disconnected',
            ] as $needle
        ) {
            if (str_contains($message, $needle)) {
                return true;
            }
        }

        return false;
    }
}
