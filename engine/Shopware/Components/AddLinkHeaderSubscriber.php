<?php

declare(strict_types=1);

/**
 * Shopware 5
 * Copyright (c) shopware AG
 *
 * According to our licensing model, this program can be used
 * under the terms of the GNU Affero General Public License, version 3.
 *
 * The texts of the GNU Affero General Public License with an additional
 * permission can be found at and in the LICENSE file you have received
 * along with this program.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
 * See the GNU Affero General Public License for more details.
 *
 * "Shopware" is a registered trademark of shopware AG.
 * The licensing of the program under the AGPLv3 does not imply a
 * trademark license. Therefore, any rights, title and interest in
 * our trademarks remain entirely with the shopware AG.
 */

namespace Shopware\Components;

use Enlight\Event\SubscriberInterface;
use Enlight_Controller_Request_Request;
use Enlight_Event_EventArgs;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\WebLink\HttpHeaderSerializer;

class AddLinkHeaderSubscriber implements SubscriberInterface
{
    private HttpHeaderSerializer $serializer;

    private bool $pushEnabled;

    private WebLinkManager $webLinkManager;

    public function __construct(
        HttpHeaderSerializer $headerSerializer,
        WebLinkManager $webLinkManager,
        bool $pushEnabled
    ) {
        $this->serializer = $headerSerializer;
        $this->pushEnabled = $pushEnabled;
        $this->webLinkManager = $webLinkManager;
    }

    /**
     * {@inheritdoc}
     */
    public static function getSubscribedEvents(): array
    {
        return [
            'Enlight_Controller_Front_DispatchLoopShutdown' => 'onDispatchLoopShutdown',
        ];
    }

    public function onDispatchLoopShutdown(Enlight_Event_EventArgs $args): void
    {
        $request = $args->get('request');
        if (!$request instanceof Enlight_Controller_Request_Request) {
            return;
        }

        // Only use Server Push if it is enabled in the settings and the current module is "frontend"
        if (!$this->pushEnabled
            || $request->getModuleName() !== 'frontend') {
            return;
        }

        $response = $args->get('response');
        if (!$response instanceof Response) {
            return;
        }

        $links = $this->webLinkManager->getLinkProvider()->getLinks();
        if (empty($links)) {
            return;
        }

        $response->headers->set('link', $this->serializer->serialize($links));
    }
}
