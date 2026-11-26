<?php
declare(strict_types=1);

namespace wmd\designfield\tests\feature;

use CraftCms\Cms\Database\Table;
use CraftCms\Cms\Entry\Data\EntryType;
use CraftCms\Cms\Entry\Elements\Entry;
use CraftCms\Cms\FieldLayout\FieldLayoutTab;
use CraftCms\Cms\FieldLayout\LayoutElements\CustomField;
use CraftCms\Cms\Plugin\Testing\PluginTestCase;
use CraftCms\Cms\Section\Data\Section;
use CraftCms\Cms\Section\Data\SectionSiteSettings;
use CraftCms\Cms\Section\Enums\SectionType;
use CraftCms\Cms\Support\Facades\Elements;
use CraftCms\Cms\Support\Facades\EntryTypes;
use CraftCms\Cms\Support\Facades\Fields;
use CraftCms\Cms\Support\Facades\Sections;
use CraftCms\Cms\Support\Facades\Sites;
use Illuminate\Support\Facades\DB;
use wmd\designfield\fields\Design;
use wmd\designfield\services\Groups;

/**
 * A save with something that is no map of option => key is refused with the reason,
 * and the element keeps what it had. It is how a GraphQL mutation or PHP code hands
 * the value over: as a JSON string or an array, with no schema in front of it.
 */
final class RefusedInputTest extends PluginTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(Groups::class, new class() extends Groups {
            public function config(): array
            {
                return [
                    'groups' => [
                        'tone' => ['label' => 'Tone', 'options' => ['light' => 'bg-white', 'dark' => 'bg-black'], 'default' => 'light'],
                        'spacing' => ['label' => 'Spacing', 'options' => ['tight' => 'py-4', 'normal' => 'py-8'], 'default' => 'normal'],
                    ],
                    'profiles' => ['blockCta' => ['tone', 'spacing']],
                ] + parent::config();
            }
        });
    }

    public function testAListOrAChoiceThatIsNoKeyDoesNotResetTheBlock(): void
    {
        $block = $this->createBlock(['tone' => 'dark', 'spacing' => 'tight']);
        $stored = $this->storedContent($block->id);

        foreach (['["dark"]', '{"tone":["light"]}', ['light'], ['tone' => ['key' => 'light']]] as $sent) {
            $loaded = Entry::find()->id($block->id)->status(null)->one();
            $loaded->setFieldValue('design', $sent);

            self::assertFalse(Elements::saveElement($loaded), 'saved: ' . json_encode($sent));
            self::assertStringContainsString('is not a design value', implode(' ', $loaded->errors()->all()));
            self::assertStringContainsString(is_string($sent) ? $sent : json_encode($sent), implode(' ', $loaded->errors()->all()));
            self::assertSame($stored, $this->storedContent($block->id));
        }

        // The map itself still saves.
        $loaded = Entry::find()->id($block->id)->status(null)->one();
        $loaded->setFieldValue('design', '{"tone":"light","spacing":"tight"}');
        self::assertTrue(Elements::saveElement($loaded), implode(' ', $loaded->errors()->all()));
        self::assertSame(['tone' => 'light', 'spacing' => 'tight'], Entry::find()->id($block->id)->status(null)->one()->getFieldValue('design')->keys());
    }

    private function storedContent(int $id): string
    {
        return (string)DB::table(Table::ELEMENTS_SITES)->where('elementId', $id)->value('content');
    }

    /**
     * @param array<string,string> $design
     */
    private function createBlock(array $design): Entry
    {
        $field = new Design(['name' => 'Design', 'handle' => 'design']);
        self::assertTrue(Fields::saveField($field));

        $type = new EntryType(['name' => 'CTA', 'handle' => 'blockCta']);
        $type->getFieldLayout()->tab('Content', static fn(FieldLayoutTab $tab) => $tab->add(CustomField::make($field)));
        self::assertTrue(EntryTypes::saveEntryType($type));

        $section = new Section([
            'name' => 'Blocks',
            'handle' => 'blocks',
            'type' => SectionType::Channel,
            'siteSettings' => [new SectionSiteSettings(['siteId' => Sites::getPrimarySite()->id])],
            'entryTypes' => [$type],
        ]);
        self::assertTrue(Sections::saveSection($section));

        $block = new Entry();
        $block->sectionId = $section->id;
        $block->typeId = $type->id;
        $block->title = 'Call';
        $block->setFieldValue('design', $design);
        self::assertTrue(Elements::saveElement($block), implode(' ', $block->errors()->all()));

        return $block;
    }
}
