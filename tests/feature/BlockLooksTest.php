<?php
declare(strict_types=1);

namespace wmd\designfield\tests\feature;

use CraftCms\Cms\Cms;
use CraftCms\Cms\Http\Middleware\RequireAdmin;
use CraftCms\Cms\Http\Middleware\RequireAdminChanges;
use CraftCms\Cms\Plugin\Testing\PluginTestCase;
use CraftCms\Cms\User\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use wmd\designfield\models\Group;
use wmd\designfield\services\Groups;
use wmd\designfield\services\SettingsFile;

/**
 * The Preview tab's "Customize this block": a look for one block only. It shows in the
 * panel before it is saved, and a save replaces that block's looks in the settings file
 * and nothing else.
 */
final class BlockLooksTest extends PluginTestCase
{
    private const string PAGE = '/admin/settings/plugins/design-field?tab=preview';

    private const string REFRESH = '/admin/design-field/preview/ui';

    private const string SAVE = '/admin/design-field/settings/block-looks';

    /** @var array<string,mixed> What `design-field.php` returns in a test */
    public static array $config = [];

    private string $file;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('app.key', 'base64:' . base64_encode(str_repeat('k', 32)));
    }

    protected function setUp(): void
    {
        parent::setUp();

        self::$config = [
            'groups' => [
                'tone' => ['label' => 'Tone', 'options' => ['light' => 'bg-white', 'dark' => 'bg-black'], 'default' => 'light', 'optionMeta' => ['light' => ['icon' => 'sun'], 'dark' => ['icon' => 'moon']]],
                'spacing' => ['label' => 'Spacing', 'options' => ['tight' => 'py-4', 'normal' => 'py-8', 'loose' => 'py-16'], 'default' => 'normal'],
                'border' => ['label' => 'Border', 'input' => 'toggle', 'options' => ['off' => '', 'on' => 'border'], 'default' => 'off'],
            ],
            'profiles' => ['blockCta' => ['tone', 'spacing', 'border'], 'blockTeam' => ['tone', 'spacing']],
        ];

        $this->file = sys_get_temp_dir() . '/design-field-settings-' . bin2hex(random_bytes(4)) . '/design-field.settings.json';
        $this->app->instance(SettingsFile::class, new SettingsFile($this->file));
        $this->readConfig();

        $this->actingAs(User::query()->findOrFail(1));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(dirname($this->file));

        parent::tearDown();
    }

    public function testThePanelEndsWithTheLooksEachOptionCanTakeOnThisBlock(): void
    {
        $this->save(['inputs' => ['groups' => ['spacing' => ['input' => 'slider']], 'profiles' => ['blockCta' => ['tone' => ['input' => 'select']]]]]);

        $props = self::props($this->get(self::PAGE . '&block=blockCta'));
        $controls = self::controls($props->ui->nodes);

        self::assertStringEndsWith('/design-field/settings/block-looks?block=blockCta', $props->submit->url);
        self::assertFalse($props->readOnly ?? false);

        // The saved look of this block is picked; an option without one is "As on every block".
        self::assertSame(['tone' => 'select', 'spacing' => '', 'border' => ''], (array)$props->ui->values->design->_looks);

        $tone = $controls['design._looks.tone'];
        self::assertTrue($tone->reactive, 'a pick asks for the panel again');
        self::assertSame('editable', $tone->mode);
        self::assertSame(['', 'icons', 'buttons', 'labels', 'select', 'slider', 'toggle', 'chip', 'position'], array_column($tone->props->options, 'value'));
        self::assertSame('As on every block (Buttons)', $tone->props->options[0]->label);

        // What the Looks tab set for the option on every block is what "every block" means.
        self::assertSame('As on every block (Slider)', $controls['design._looks.spacing']->props->options[0]->label);
        self::assertNotContains('toggle', array_column($controls['design._looks.spacing']->props->options, 'value'), 'three choices are no switch');

        // The panel itself shows the saved look.
        self::assertSame('select', $controls['design.tone']->props->presentation);
    }

    public function testALookShowsInThePanelBeforeItIsSaved(): void
    {
        // As the Ui page posts a pick in the panel: the panel's own values, the looks among them.
        $ui = $this->postJson(self::REFRESH . '?block=blockCta', [
            'values' => ['tone' => 'dark', 'spacing' => 'normal', 'border' => false, '_looks' => ['tone' => 'select', 'spacing' => 'slider', 'border' => '']],
            'scope' => ['design'],
        ])->assertOk()->json('ui');

        $controls = self::controls(json_decode(json_encode($ui['nodes'])));
        self::assertSame('select', $controls['design.tone']->props->presentation);
        self::assertSame('design-field:steps', $controls['design.spacing']->component, 'a slider');
        self::assertSame(['tone' => 'select', 'spacing' => 'slider', 'border' => ''], $ui['values']['design']['_looks'], 'the picks come back');
        self::assertSame('dark', $ui['values']['design']['tone'], 'and the choices with them');
        self::assertFileDoesNotExist($this->file, 'nothing was saved');

        // The request after it reads the saved looks again: the unsaved ones did not stay.
        self::assertSame(Group::INPUT_BUTTONS, $this->app->make(Groups::class)->getRegistry()->profiles['blockCta']['tone']->input);
        self::assertSame('buttons', self::controls(self::props($this->get(self::PAGE . '&block=blockCta'))->ui->nodes)['design.tone']->props->presentation);

        // A look the option cannot take there is not built with: the panel stays as it is.
        $ui = $this->postJson(self::REFRESH . '?block=blockCta', ['values' => ['design' => ['_looks' => ['spacing' => 'toggle', 'tone' => 'no-such-look']]], 'scope' => []])->assertOk()->json('ui');
        self::assertSame(['tone' => '', 'spacing' => '', 'border' => ''], $ui['values']['design']['_looks']);
    }

    public function testUnpickingASavedLookShowsTheOptionAsOnEveryBlock(): void
    {
        $this->save(['inputs' => ['profiles' => ['blockCta' => ['tone' => ['input' => 'select']]]]]);

        $ui = $this->postJson(self::REFRESH . '?block=blockCta', ['values' => ['_looks' => ['tone' => '']], 'scope' => ['design']])->assertOk()->json('ui');
        self::assertSame('buttons', self::controls(json_decode(json_encode($ui['nodes'])))['design.tone']->props->presentation);
    }

    public function testASaveReplacesTheLooksOfThatBlockOnlyAndKeepsTheRest(): void
    {
        $this->save([
            'inputs' => [
                'styles' => ['toggle' => 'select'],
                'groups' => ['spacing' => ['input' => 'slider']],
                'profiles' => ['blockCta' => ['border' => ['input' => 'select']], 'blockTeam' => ['tone' => ['input' => 'select']]],
                'meta' => ['tone' => ['dark' => ['label' => 'Night']]],
                'sections' => ['tone' => 'Colour'],
            ],
            'presets' => ['*' => ['Plain' => ['tone' => 'light']]],
        ]);

        // The tab posts its whole form: the block picker and the panel's choices are not saved.
        $this->post(self::SAVE . '?block=blockCta', [
            'block' => 'blockCta',
            'design' => ['tone' => 'dark', 'spacing' => 'loose', 'border' => true, '_looks' => ['tone' => 'icons', 'spacing' => 'select', 'border' => '']],
        ])->assertRedirectContains('tab=preview&block=blockCta');

        $saved = json_decode((string)file_get_contents($this->file), true);
        self::assertSame(
            [
                'blockCta' => ['tone' => ['input' => 'buttons', 'iconsOnly' => true], 'spacing' => ['input' => 'select', 'iconsOnly' => false]],
                'blockTeam' => ['tone' => ['input' => 'select', 'iconsOnly' => false]],
            ],
            $saved['inputs']['profiles'],
            'the block\'s looks are replaced, border\'s is gone, the other block\'s stay',
        );
        self::assertSame(['toggle' => 'select'], $saved['inputs']['styles']);
        self::assertSame(['spacing' => ['input' => 'slider', 'iconsOnly' => false]], $saved['inputs']['groups']);
        self::assertSame(['tone' => ['dark' => ['label' => 'Night']]], $saved['inputs']['meta']);
        self::assertSame(['tone' => 'Colour'], $saved['inputs']['sections']);
        self::assertSame(['*' => ['Plain' => ['tone' => 'light']]], $saved['presets']);

        // The site reads it: this block only, over the look the option has everywhere.
        $this->readConfig();
        $registry = $this->app->make(Groups::class)->getRegistry();
        self::assertTrue($registry->profiles['blockCta']['tone']->iconsOnly);
        self::assertSame(Group::INPUT_SELECT, $registry->profiles['blockCta']['spacing']->input);
        self::assertSame(Group::INPUT_SLIDER, $registry->profiles['blockTeam']['spacing']->input);

        // Everything back to "As on every block": the block has no looks of its own left.
        $this->post(self::SAVE . '?block=blockCta', ['design' => ['_looks' => ['tone' => '', 'spacing' => '', 'border' => '']]])->assertRedirect();
        self::assertSame(['blockTeam'], array_keys(json_decode((string)file_get_contents($this->file), true)['inputs']['profiles']));
    }

    public function testALookTheOptionCannotTakeAndAnUnknownBlockAreRefused(): void
    {
        $this->post(self::SAVE . '?block=blockCta', ['design' => ['_looks' => ['spacing' => 'toggle', 'tone' => 'select']]])
            ->assertSessionHasErrors('design._looks.spacing');
        self::assertFileDoesNotExist($this->file, 'nothing of a refused save is written');

        $this->post(self::SAVE . '?block=blockGone', ['design' => ['_looks' => ['tone' => 'select']]])->assertNotFound();
        $this->post(self::SAVE, ['design' => ['_looks' => ['tone' => 'select']]])->assertNotFound();
        self::assertFileDoesNotExist($this->file);
    }

    public function testWhereAdminChangesAreOffTheLooksShowButThePanelStillWorks(): void
    {
        $this->save(['inputs' => ['profiles' => ['blockCta' => ['tone' => ['input' => 'select']]]]]);
        Cms::config()->allowAdminChanges = false;

        $props = self::props($this->get(self::PAGE . '&block=blockCta'));
        $controls = self::controls($props->ui->nodes);

        // No Save and no banner: the tab is still for trying layouts and presets.
        self::assertObjectNotHasProperty('submit', $props);
        self::assertSame('readOnly', $controls['design._looks.tone']->mode);
        self::assertFalse($controls['design._looks.tone']->reactive ?? false);
        self::assertSame('editable', $controls['design.spacing']->mode);
        self::assertSame('select', $controls['design.tone']->props->presentation, 'the saved look shows');

        $this->post(self::SAVE . '?block=blockCta', ['design' => ['_looks' => ['tone' => '']]])->assertForbidden();
        self::assertArrayHasKey('blockCta', json_decode((string)file_get_contents($this->file), true)['inputs']['profiles']);
    }

    public function testTheSaveIsForAdminsWhereAdminChangesAreAllowed(): void
    {
        $middleware = Route::gatherRouteMiddleware(Route::getRoutes()->match(Request::create(self::SAVE, 'POST')));

        self::assertContains(RequireAdmin::class, $middleware);
        self::assertContains(RequireAdminChanges::class, $middleware);
    }

    // Helpers
    // =========================================================================

    /** @param array<string,mixed> $data The settings file as saved before the request */
    private function save(array $data): void
    {
        File::ensureDirectoryExists(dirname($this->file));
        File::put($this->file, json_encode($data));
        $this->readConfig();
    }

    /** A new request: the config and the settings file are read again. */
    private function readConfig(): void
    {
        $this->app->instance(Groups::class, new class() extends Groups {
            public function fileConfig(): array
            {
                return BlockLooksTest::$config;
            }
        });
    }

    /** The Inertia page props of the screen, as the Ui page receives them. */
    private static function props(TestResponse $response): object
    {
        $html = (string)$response->assertOk()->getContent();
        preg_match('#<script data-page="app" type="application/json">(.*?)</script>#s', $html, $m);

        return json_decode($m[1] ?? 'null', false, flags: JSON_THROW_ON_ERROR)->props;
    }

    /**
     * Every control of a Ui by its path, the panel's own among them.
     *
     * @param array<int,object> $nodes
     * @return array<string,object>
     */
    private static function controls(array $nodes): array
    {
        $found = [];

        foreach ($nodes as $node) {
            if (isset($node->control->path)) {
                $found[implode('.', (array)$node->control->path)] = $node->control;

                foreach ($node->control->uis ?? [] as $ui) {
                    $found += self::controls($ui->nodes);
                }
            }

            $found += self::controls($node->children ?? []);
        }

        return $found;
    }
}
