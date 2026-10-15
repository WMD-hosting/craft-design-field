<?php
declare(strict_types=1);

namespace wmd\designfield\tests\unit;

use PHPUnit\Framework\TestCase;
use wmd\designfield\fields\Design;
use wmd\designfield\helpers\Registry;
use wmd\designfield\integrations\McpServers;
use wmd\designfield\models\DesignValue;
use yii\base\Component;
use yii\base\Event;

/**
 * Stand-in for rewi.sh/craft-mcp's plugin class and its FieldTranslatorsEvent.
 */
final class FakeMcp extends Component
{
}

final class FakeTranslatorsEvent extends Event
{
    /** @var array<class-string, callable> */
    public array $translators = [];
}

final class McpServersTest extends TestCase
{
    private function value(array $keys): DesignValue
    {
        return Registry::fromConfig([
            'groups' => [
                'tone' => ['options' => ['light' => 'bg-white', 'dark' => 'bg-black'], 'default' => 'light'],
                'spacing' => ['options' => ['tight' => 'py-4', 'loose' => 'py-16'], 'default' => 'tight'],
            ],
            'profiles' => ['blockCta' => ['tone', 'spacing']],
        ], static fn() => [])->value($keys, 'blockCta');
    }

    protected function tearDown(): void
    {
        Event::offAll();
    }

    public function testJsonIsTheKeysNotTheClasses(): void
    {
        $value = $this->value(['tone' => 'dark']);

        self::assertSame('bg-black py-4', (string)$value, 'Twig still prints classes');
        self::assertSame('{"tone":"dark","spacing":"tight"}', json_encode($value));
    }

    public function testReadReturnsWhatASaveStores(): void
    {
        $value = $this->value(['tone' => 'dark', 'columns' => '3']);

        self::assertSame(
            ['tone' => 'dark', 'columns' => '3', 'spacing' => 'tight'],
            McpServers::translate(new \stdClass(), $value, 'read'),
            'keys of options outside the layout survive a read-edit-write round trip',
        );
    }

    public function testWriteAcceptsTheObjectOrItsJson(): void
    {
        $field = new \stdClass();

        self::assertSame(['tone' => 'dark'], McpServers::translate($field, ['tone' => 'dark'], 'write'));
        self::assertSame(['tone' => 'dark'], McpServers::translate($field, '{"tone":"dark"}', 'write'));
        self::assertSame([], McpServers::translate($field, null, 'write'));
        self::assertSame('not json', McpServers::translate($field, 'not json', 'write'), 'left for the field to reject');
    }

    public function testRegistersOnlyWhenTheMcpPluginIsInstalled(): void
    {
        McpServers::register('rewish\craft\mcp\NotInstalled');
        self::assertFalse(Event::hasHandlers('rewish\craft\mcp\NotInstalled', McpServers::REWISH_REGISTER_FIELD_TRANSLATORS));

        McpServers::register(FakeMcp::class);
        $event = new FakeTranslatorsEvent();
        (new FakeMcp())->trigger(McpServers::REWISH_REGISTER_FIELD_TRANSLATORS, $event);

        self::assertSame([McpServers::class, 'translate'], $event->translators[Design::class]);
    }
}
