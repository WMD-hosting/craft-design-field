<?php
declare(strict_types=1);

namespace wmd\designfield\tests\feature;

use CraftCms\Cms\Cms;
use CraftCms\Cms\Entry\Data\EntryType;
use CraftCms\Cms\Entry\Elements\Entry;
use CraftCms\Cms\Field\Contracts\InputSchemaFieldInterface;
use CraftCms\Cms\Field\Matrix;
use CraftCms\Cms\FieldLayout\FieldLayoutTab;
use CraftCms\Cms\FieldLayout\LayoutElements\CustomField;
use CraftCms\Cms\Mcp\Capabilities\Elements as ElementTools;
use CraftCms\Cms\Plugin\Testing\PluginTestCase;
use CraftCms\Cms\Section\Data\Section;
use CraftCms\Cms\Section\Data\SectionSiteSettings;
use CraftCms\Cms\Section\Enums\SectionType;
use CraftCms\Cms\Support\Facades\Elements;
use CraftCms\Cms\Support\Facades\EntryTypes;
use CraftCms\Cms\Support\Facades\Fields;
use CraftCms\Cms\Support\Facades\Sections;
use CraftCms\Cms\Support\Facades\Sites;
use CraftCms\Cms\User\Models\User;
use GraphQL\Type\Definition\StringType;
use Mcp\Exception\ToolCallException;
use wmd\designfield\fields\Design;
use wmd\designfield\services\Groups;

/**
 * An agent reads and writes Design values through Craft's own MCP element tools
 * (`elements_field_schema`, `elements_get`, `elements_update`), called in-process
 * on a page whose `pageBuilder` Matrix field holds the blocks.
 */
final class McpSchemaTest extends PluginTestCase
{
    private Entry $page;

    /** @var array<string,int> Block type handle => ID of the page's block of that type */
    private array $blocks = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(Groups::class, new class() extends Groups {
            public function config(): array
            {
                return [
                    'groups' => [
                        'tone' => ['label' => 'Tone', 'options' => ['light' => 'bg-white', 'dark' => 'bg-black'], 'default' => 'light'],
                        'spacing' => ['label' => 'Spacing', 'options' => ['tight' => 'py-4', 'normal' => 'py-8', 'loose' => 'py-16'], 'default' => 'normal'],
                        'border' => ['label' => 'Border', 'input' => 'toggle', 'options' => ['off' => '', 'on' => 'border'], 'default' => 'off'],
                        'shade' => ['label' => 'Shade', 'options' => ['soft' => 'opacity-50', 'full' => 'opacity-100'], 'showIf' => ['tone' => ['dark']]],
                        'columns' => ['label' => 'Columns', 'options' => ['2' => 'grid-cols-2', '3' => 'grid-cols-3'], 'default' => '2'],
                        'glow' => ['label' => 'Glow', 'options' => ['none' => '', 'soft' => 'shadow'], 'default' => 'none'],
                    ],
                    'profiles' => [
                        'blockCta' => ['tone', 'spacing', 'border', 'shade'],
                        // The same type has other options inside the page builder than on its own.
                        'blockTeam' => ['tone', 'columns'],
                        'pageBuilder:blockTeam' => ['tone', 'spacing'],
                    ],
                ] + parent::config();
            }
        });

        // The tools act as the signed-in Craft user of the request.
        $this->actingAs(User::query()->where('admin', true)->firstOrFail(), Cms::config()->authGuard);
        $this->app->make('request')->setUserResolver(static fn(?string $guard = null) => auth()->guard($guard)->user());

        $this->createPage();
    }

    public function testTheSchemaListsEveryOptionOfTheProfileWithItsKeys(): void
    {
        $field = new Design(['handle' => 'design', 'profile' => 'blockCta']);

        self::assertInstanceOf(InputSchemaFieldInterface::class, $field);

        $schema = $field->getInputSchema();

        self::assertSame('object', $schema['type']);
        self::assertFalse($schema['additionalProperties']);
        self::assertSame(['tone', 'spacing', 'border', 'shade'], array_keys($schema['properties']));
        self::assertSame(
            ['type' => 'string', 'title' => 'Spacing', 'enum' => ['tight', 'normal', 'loose'], 'default' => 'normal'],
            $schema['properties']['spacing'],
        );

        // A switch is its two keys, off first: the form the value is read in.
        self::assertSame(['off', 'on'], $schema['properties']['border']['enum']);
        self::assertStringContainsString('`on` is on', $schema['properties']['border']['description']);
        self::assertStringContainsString('Only applies when `tone` is `dark`', $schema['properties']['shade']['description']);

        // Keys that are numbers are strings, as they are read.
        self::assertSame(['2', '3'], (new Design(['handle' => 'design', 'profile' => 'blockTeam']))->getInputSchema()['properties']['columns']['enum']);

        // What the schema allows is what the field takes, and it comes back the same.
        $value = $field->normalizeValue(['spacing' => 'tight'], null);
        self::assertSame([], $value->invalid());
        self::assertSame('tight', $field->serializeValue($value, null)['spacing']);
    }

    public function testAFieldWithoutAProfileSettingDescribesTheEntryTypeItSitsIn(): void
    {
        $schema = $this->tools()->fieldSchema('entry', $this->blocks['blockCta'])['schema']['properties']['design'];

        self::assertSame(['object', 'null'], $schema['type']);
        self::assertSame(['tone', 'spacing', 'border', 'shade'], array_keys($schema['properties']));
        self::assertFalse($schema['additionalProperties']);
    }

    public function testATypeWithAProfilePerOwnerFieldAllowsWhatAnyOfThemAllows(): void
    {
        // Core asks for the schema without the element, so the owner field is not known.
        $schema = $this->tools()->fieldSchema('entry', $this->blocks['blockTeam'])['schema']['properties']['design'];
        self::assertEqualsCanonicalizing(['tone', 'spacing', 'columns'], array_keys($schema['properties']));

        // Reading knows the element: inside the page builder the block has tone and spacing.
        self::assertSame(['tone' => 'light', 'spacing' => 'normal'], $this->design('blockTeam'));
    }

    public function testAnAgentReadsAValueChangesItAndReadsItBack(): void
    {
        $read = $this->design('blockCta');
        self::assertSame(['tone' => 'dark', 'spacing' => 'normal', 'border' => 'off', 'shade' => 'soft'], $read);

        $this->tools()->update('entry', $this->blocks['blockCta'], fields: ['design' => ['spacing' => 'tight', 'border' => 'on'] + $read]);

        self::assertSame(['tone' => 'dark', 'spacing' => 'tight', 'border' => 'on', 'shade' => 'soft'], $this->design('blockCta'));
        self::assertSame('py-4', (string)Entry::find()->id($this->blocks['blockCta'])->one()->getFieldValue('design')->spacing);
    }

    public function testWhatIsReadCanAlwaysBeWrittenBack(): void
    {
        // The block was saved with `glow` while it still had that option.
        $block = Entry::find()->id($this->blocks['blockCta'])->one();
        $block->setFieldValue('design', ['tone' => 'dark', 'glow' => 'soft']);
        self::assertTrue(Elements::saveElement($block));
        self::assertSame('soft', Entry::find()->id($block->id)->one()->getFieldValue('design')->toArray()['glow']);

        $read = $this->design('blockCta');
        self::assertArrayNotHasKey('glow', $read);

        $this->tools()->update('entry', $block->id, fields: ['design' => $read]);
        self::assertSame($read, $this->design('blockCta'));
    }

    public function testAnUnknownOptionIsRefusedByName(): void
    {
        $this->assertRefused(['design' => ['tone' => 'light', 'glow' => 'soft']], '/design', '`glow`');
    }

    public function testAKeyThatIsNoChoiceOfItsOptionIsRefusedByName(): void
    {
        $this->assertRefused(['design' => ['tone' => 'light', 'spacing' => 'huge']], '/design/spacing', '"tight", "normal", "loose"');
    }

    public function testAValueThatIsNoMapIsRefused(): void
    {
        $this->assertRefused(['design' => '{"tone":"light"}'], '/design', 'object');
    }

    public function testBlocksAreReadAndWrittenThroughThePagesBuilderField(): void
    {
        $nested = $this->tools()->fieldSchema('entry', $this->page->id)['schema']['properties']['pageBuilder']['properties']['entries']['items']['properties']['blockCta']['properties']['design'];
        self::assertSame(['object', 'null'], $nested['type']);
        self::assertSame(['tight', 'normal', 'loose'], $nested['properties']['spacing']['enum']);
        self::assertFalse($nested['additionalProperties']);

        $read = $this->pageBlocks();
        self::assertSame(['tone' => 'dark', 'spacing' => 'normal', 'border' => 'off', 'shade' => 'soft'], $read[$this->blocks['blockCta']]);

        // One call changes a block and adds another after the two that are there.
        $this->tools()->update('entry', $this->page->id, fields: ['pageBuilder' => ['entries' => [
            ['blockCta' => ['id' => $this->blocks['blockCta'], 'design' => ['spacing' => 'tight'] + $read[$this->blocks['blockCta']]]],
            ['blockCta' => ['design' => ['tone' => 'light', 'border' => 'on']]],
        ]]]);

        $after = $this->pageBlocks();
        self::assertCount(3, $after);
        self::assertSame('tight', $after[$this->blocks['blockCta']]['spacing']);
        self::assertSame(['tone' => 'light', 'spacing' => 'normal', 'border' => 'on', 'shade' => 'soft'], end($after));

        try {
            $this->tools()->update('entry', $this->page->id, fields: ['pageBuilder' => ['entries' => [
                ['blockCta' => ['id' => $this->blocks['blockCta'], 'design' => ['tone' => 'light', 'glow' => 'soft']]],
            ]]]);
            self::fail('An option outside the profile was accepted.');
        } catch (ToolCallException $e) {
            self::assertStringContainsString('/pageBuilder/entries/0/blockCta/design', $e->getMessage());
            self::assertStringContainsString('`glow`', $e->getMessage());
        }

        self::assertSame($after, $this->pageBlocks());
    }

    public function testTheGraphqlApiStillTakesAJsonString(): void
    {
        $field = new Design(['handle' => 'design', 'profile' => 'blockCta']);

        self::assertInstanceOf(StringType::class, $field->getContentGqlMutationArgumentType()['type']);
        self::assertSame('dark', $field->normalizeValue('{"tone":"dark"}', null)->toArray()['tone']);
    }

    private function tools(): ElementTools
    {
        return $this->app->make(ElementTools::class);
    }

    /**
     * The Design value of one of the page's blocks, as `elements_get` returns it.
     *
     * @return array<string,string>
     */
    private function design(string $type): array
    {
        return $this->tools()->get('entry', $this->blocks[$type])['element']['design'];
    }

    /**
     * The Design value of every block, as `elements_get` of the page returns them.
     *
     * @return array<int,array<string,string>> Block ID => value, in page order
     */
    private function pageBlocks(): array
    {
        $blocks = $this->tools()->get('entry', $this->page->id, fields: ['pageBuilder'])['element']['pageBuilder'];

        return array_column($blocks, 'design', 'id');
    }

    /**
     * A write to the CTA block that the tools must refuse, leaving the block as it was.
     *
     * @param array<string,mixed> $fields
     */
    private function assertRefused(array $fields, string ...$named): void
    {
        $before = $this->design('blockCta');

        try {
            $this->tools()->update('entry', $this->blocks['blockCta'], fields: $fields);
            self::fail('The write was accepted.');
        } catch (ToolCallException $e) {
            foreach ($named as $part) {
                self::assertStringContainsString($part, $e->getMessage());
            }
        }

        self::assertSame($before, $this->design('blockCta'));
    }

    /**
     * A page with a CTA block (dark) and a team block, both with the one Design
     * field, which has no profile setting: each block's type picks its options.
     */
    private function createPage(): void
    {
        $design = new Design(['name' => 'Design', 'handle' => 'design']);
        self::assertTrue(Fields::saveField($design));

        $blockTypes = [];

        foreach (['blockCta', 'blockTeam'] as $handle) {
            $type = new EntryType(['name' => $handle, 'handle' => $handle, 'hasTitleField' => false]);
            $type->getFieldLayout()->tab('Content', static fn(FieldLayoutTab $tab) => $tab->add(CustomField::make($design)));
            self::assertTrue(EntryTypes::saveEntryType($type));
            $blockTypes[] = $type;
        }

        $builder = new Matrix(['name' => 'Page builder', 'handle' => 'pageBuilder']);
        $builder->setEntryTypes($blockTypes);
        self::assertTrue(Fields::saveField($builder));

        $pageType = new EntryType(['name' => 'Page', 'handle' => 'page']);
        $pageType->getFieldLayout()->tab('Content', static fn(FieldLayoutTab $tab) => $tab->add(CustomField::make($builder)));
        self::assertTrue(EntryTypes::saveEntryType($pageType));

        $section = new Section([
            'name' => 'Pages',
            'handle' => 'pages',
            'type' => SectionType::Channel,
            'siteSettings' => [new SectionSiteSettings(['siteId' => Sites::getPrimarySite()->id])],
            'entryTypes' => [$pageType],
        ]);
        self::assertTrue(Sections::saveSection($section));

        $page = new Entry();
        $page->sectionId = $section->id;
        $page->typeId = $pageType->id;
        $page->title = 'Home';
        $page->setFieldValue('pageBuilder', [
            'new1' => ['type' => 'blockCta', 'fields' => ['design' => ['tone' => 'dark']]],
            'new2' => ['type' => 'blockTeam'],
        ]);
        self::assertTrue(Elements::saveElement($page), implode(' ', $page->errors()->all()));

        $this->page = $page;

        foreach (Entry::find()->ownerId($page->id)->all() as $block) {
            $this->blocks[$block->getType()->handle] = $block->id;
        }
    }
}
