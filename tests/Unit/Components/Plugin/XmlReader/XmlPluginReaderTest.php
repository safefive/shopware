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

namespace Shopware\Tests\Unit\Components\Plugin\XmlReader;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Shopware\Components\Plugin\XmlReader\XmlPluginReader;

class XmlPluginReaderTest extends TestCase
{
    private XmlPluginReader $pluginReader;

    protected function setUp(): void
    {
        $this->pluginReader = new XmlPluginReader();
    }

    public function testReadFile(): void
    {
        $result = $this->readFile();

        static::assertArrayHasKey('label', $result);
        static::assertArrayHasKey('en', $result['label']);
        static::assertArrayHasKey('de', $result['label']);
        static::assertEquals('My plugin', $result['label']['de']);
        static::assertEquals('My plugin', $result['label']['en']);

        static::assertArrayHasKey('description', $result);
        static::assertArrayHasKey('en', $result['description']);
        static::assertArrayHasKey('de', $result['description']);
        static::assertEquals("<h2>Mein Plugin</h2>\n<p>Meine Plugin Beschreibung</p>", $result['description']['de']);
        static::assertEquals("<h2>My Plugin</h2>\n<p>My long description</p>", $result['description']['en']);

        static::assertArrayHasKey('version', $result);
        static::assertArrayHasKey('license', $result);
        static::assertArrayHasKey('author', $result);
        static::assertArrayHasKey('copyright', $result);
        static::assertArrayHasKey('link', $result);

        static::assertEquals('1.5.3', $result['version']);
        static::assertEquals('MIT', $result['license']);
        static::assertEquals('Hasna Corp.', $result['author']);
        static::assertEquals('(c) Hansa Corp.', $result['copyright']);
        static::assertEquals('Some link', $result['link']);

        static::assertArrayHasKey('changelog', $result);
        static::assertArrayHasKey('1.0.6', $result['changelog']);
        static::assertArrayHasKey('1.0.5', $result['changelog']);
        static::assertArrayHasKey('de', $result['changelog']['1.0.6']);
        static::assertArrayHasKey('en', $result['changelog']['1.0.6']);
        static::assertCount(3, $result['changelog']['1.0.6']['de']);
        static::assertCount(1, $result['changelog']['1.0.6']['en']);

        static::assertArrayHasKey('compatibility', $result);
        static::assertArrayHasKey('minVersion', $result['compatibility']);
        static::assertArrayHasKey('maxVersion', $result['compatibility']);
        static::assertArrayHasKey('blacklist', $result['compatibility']);

        static::assertCount(2, $result['compatibility']['blacklist']);

        static::assertArrayHasKey('requiredPlugins', $result);

        static::assertCount(2, $result['requiredPlugins']);

        $firstRequiredPlugin = $result['requiredPlugins'][0];

        static::assertArrayHasKey('minVersion', $firstRequiredPlugin);
        static::assertArrayHasKey('maxVersion', $firstRequiredPlugin);
        static::assertArrayHasKey('blacklist', $firstRequiredPlugin);

        static::assertCount(2, $firstRequiredPlugin['blacklist']);

        static::assertEquals('1.0.2', $firstRequiredPlugin['blacklist'][0]);
        static::assertEquals('1.0.3', $firstRequiredPlugin['blacklist'][1]);

        $secondRequiredPlugin = $result['requiredPlugins'][1];

        static::assertArrayNotHasKey('minVersion', $secondRequiredPlugin);
        static::assertArrayNotHasKey('maxVersion', $secondRequiredPlugin);
        static::assertArrayNotHasKey('blacklist', $secondRequiredPlugin);
    }

    /**
     * Third party plugins commonly ship a plugin.xml with elements Shopware does not know, e.g.
     * <supplier> or <support>. Those files have to keep validating, see SFIN-132.
     */
    public function testReadFileWithUnknownNodes(): void
    {
        $result = $this->readFile('plugin_unknown_nodes');

        static::assertEquals('1.5.3', $result['version']);
        static::assertEquals('MIT', $result['license']);
        static::assertEquals('Hasna Corp.', $result['author']);
        static::assertEquals('(c) Hansa Corp.', $result['copyright']);
        static::assertEquals('Some link', $result['link']);
        static::assertEquals(['de' => 'My plugin', 'en' => 'My plugin'], $result['label']);
        static::assertEquals(['en' => 'My long description'], $result['description']);

        static::assertArrayHasKey('compatibility', $result);
        static::assertEquals('5.1.0', $result['compatibility']['minVersion']);
        static::assertEquals(['5.1.2'], $result['compatibility']['blacklist']);

        // unknown elements are ignored, not imported
        static::assertArrayNotHasKey('supplier', $result);
        static::assertArrayNotHasKey('support', $result);
        static::assertArrayNotHasKey('foobar', $result);
    }

    /**
     * The lax wildcard in plugin.xsd must not turn off validation of the elements Shopware does know.
     *
     * @dataProvider invalidPluginFileProvider
     */
    public function testKnownNodesAreStillValidated(string $fileName, string $expectedMessage): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches($expectedMessage);
        $this->readFile($fileName);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public function invalidPluginFileProvider(): array
    {
        return [
            'missing required attribute' => [
                'plugin_invalid_required_plugin',
                "/The attribute 'pluginName' is required but missing/",
            ],
            'invalid attribute value' => [
                'plugin_invalid_lang',
                "/attribute 'lang'.*is not a valid value/",
            ],
            'unknown child of a known element' => [
                'plugin_invalid_compatibility',
                "/Element 'nope': This element is not expected/",
            ],
        ];
    }

    public function testReadFileWithoutVersion(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Element with name "version" not found in file ".*\/tests\/Unit\/Components\/Plugin\/XmlReader\/examples\/plugin\/plugin_missing_version\.xml"/');
        $this->readFile('plugin_missing_version');
    }

    public function testReadFileWithMultipleVersion(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Element with name "version" found multiple times in file ".*\/tests\/Unit\/Components\/Plugin\/XmlReader\/examples\/plugin\/plugin_multiple_versions\.xml", but expected to be there only once/');
        $this->readFile('plugin_multiple_versions');
    }

    private function readFile(string $fileName = 'plugin'): array
    {
        return $this->pluginReader->read(
            sprintf('%s/examples/plugin/%s.xml', __DIR__, $fileName)
        );
    }
}
