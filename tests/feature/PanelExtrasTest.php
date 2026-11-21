<?php
declare(strict_types=1);

namespace wmd\designfield\tests\feature;

use CraftCms\Cms\Field\FieldContext;
use CraftCms\Cms\Plugin\Testing\PluginTestCase;
use CraftCms\Cms\Support\Facades\HtmlStack;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use wmd\designfield\events\DefinePanelGroups;
use wmd\designfield\fields\Design;
use wmd\designfield\jobs\CountUsage;
use wmd\designfield\services\Groups;
use wmd\designfield\services\Usage;
use wmd\designfield\variables\DesignFieldVariable;

/**
 * What a site adds around the panel: the starter stylesheet on its pages, a listener that
 * trims the panel for one element, and "Most used" at the top of a long dropdown.
 */
final class PanelExtrasTest extends PluginTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(Groups::class, new class() extends Groups {
            public function config(): array
            {
                $fields = [];
                foreach (range(1, 12) as $n) {
                    $fields["field$n"] = ['label' => "Field $n", 'value' => "f-$n"];
                }

                return [
                    'groups' => [
                        'tone' => ['label' => 'Tone', 'auto' => 'Block default', 'options' => ['light' => 'bg-white', 'dark' => 'bg-black']],
                        'related' => ['label' => 'Related', 'input' => 'toggle', 'default' => 'off', 'options' => ['off' => 'Off', 'on' => 'On']],
                        'sourceField' => ['label' => 'Source', 'input' => 'select', 'default' => 'field1', 'options' => $fields],
                    ],
                    'profiles' => ['list' => ['tone', 'related', 'sourceField']],
                ] + parent::config();
            }
        });
    }

    public function testTheStarterStylesheetGoesIntoTheHeadOnce(): void
    {
        $variable = $this->app->make(DesignFieldVariable::class);
        $variable->starterCss();
        $variable->starterCss();

        $head = HtmlStack::headHtml();

        self::assertSame(1, substr_count($head, '<style'), 'however often the layout calls it');
        self::assertStringContainsString(trim((string)file_get_contents(DesignFieldVariable::STARTER_CSS)), $head);
    }

    public function testWithoutAListenerThePanelShowsEveryOption(): void
    {
        $nodes = $this->nodes(['tone' => 'dark']);

        self::assertSame([true, true, true], array_map(static fn($node) => $node->isVisible(), $nodes));
        self::assertCount(12, $nodes[2]->getControl()->props([])['options']);
    }

    public function testAListenerHidesChoicesAndLeavesAnOptionOutOfOnePanel(): void
    {
        Event::listen(DefinePanelGroups::class, static function(DefinePanelGroups $event): void {
            self::assertSame('dark', $event->keys['tone']);
            $event->groups['sourceField'] = $event->groups['sourceField']->withHidden(['field3', 'field4']);
            $event->groups['notThere'] = $event->groups['tone'];
            $event->hiddenGroups[] = 'related';
            $event->hiddenGroups[] = 'notThere';
        });

        $field = new Design(['handle' => 'design', 'profile' => 'list']);
        $value = $field->normalizeValue(['tone' => 'dark', 'related' => 'on', 'sourceField' => 'field4'], null);
        $nodes = $field->uiControl(new FieldContext('design', $value))->nestedUis([])[0]['ui']->nodes();

        self::assertSame(['tone', 'related', 'sourceField'], array_map(static fn($node) => $node->getControl()->path(), $nodes), 'handles are fixed');
        self::assertFalse($nodes[1]->isVisible(), 'left out of the panel');
        self::assertTrue($nodes[1]->getControl()->getValue(), 'still in the form, so its value is kept');

        $keys = array_column($nodes[2]->getControl()->props([])['options'], 'value');
        self::assertNotContains('field3', $keys);
        self::assertContains('field4', $keys, 'a hidden choice shows while it is the chosen one');

        // Only the panel changed: the hidden choice is still a valid value.
        self::assertSame('field3', $field->normalizeValue(['sourceField' => 'field3'], null)->toArray()['sourceField']);
    }

    public function testALongDropdownStartsWithTheMostUsedChoices(): void
    {
        Cache::put(Usage::COUNTS_KEY, ['blockList' => ['sourceField' => ['field7' => 9, 'field2' => 4, 'field11' => 4, 'field5' => 1], 'tone' => ['dark' => 50]]], 60);

        $options = $this->nodes([])[2]->getControl()->props([])['options'];

        self::assertSame(['field7', 'field2', 'field11'], array_slice(array_column($options, 'value'), 0, 3), 'by use, ties in list order');
        self::assertSame(['Most used', 'Most used', 'Most used', 'All choices'], array_slice(array_column($options, 'group'), 0, 4));
        self::assertCount(12, $options, 'every choice is listed once');
        self::assertArrayNotHasKey('group', $this->nodes([])[0]->getControl()->props([])['options'][0] ?? [], 'short lists stay as they are');
    }

    public function testWithoutCountsTheListKeepsItsOrderAndAJobCountsInTheBackground(): void
    {
        Queue::fake();

        $options = $this->nodes([])[2]->getControl()->props([])['options'];
        $this->nodes([]);

        self::assertSame('field1', $options[0]['value']);
        self::assertArrayNotHasKey('group', $options[0]);
        Queue::assertPushed(CountUsage::class, 1);
    }

    public function testTheJobKeepsTheCountsOfAReport(): void
    {
        $usage = $this->app->make(Usage::class);
        $usage->storeCounts(['blockList' => ['entries' => 3, 'groups' => ['tone' => ['counts' => ['dark' => '2', 'light' => 1]]]]]);

        self::assertSame(['blockList' => ['tone' => ['dark' => 2, 'light' => 1]]], $usage->counts());
    }

    /**
     * @param array<string,string> $keys
     * @return list<\CraftCms\Cms\Ui\Nodes\Field>
     */
    private function nodes(array $keys): array
    {
        $field = new Design(['handle' => 'design', 'profile' => 'list']);

        return $field->uiControl(new FieldContext('design', $field->normalizeValue($keys, null)))->nestedUis([])[0]['ui']->nodes();
    }
}
