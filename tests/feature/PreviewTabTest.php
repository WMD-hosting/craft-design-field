<?php
declare(strict_types=1);

namespace wmd\designfield\tests\feature;

use CraftCms\Cms\Http\Middleware\RequireAdmin;
use CraftCms\Cms\Plugin\Testing\PluginTestCase;
use CraftCms\Cms\Support\Facades\Twig;
use CraftCms\Cms\View\TemplateMode;
use CraftCms\Cms\User\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Twig\Source;
use wmd\designfield\services\Groups;

/**
 * The settings page's Preview tab: the real Design panel of any block, and with
 * `blockPreviewUrl` the block itself beside it. Nothing on it saves.
 */
final class PreviewTabTest extends PluginTestCase
{
    private const string URL = '/admin/settings/plugins/design-field?tab=preview';

    private const string REFRESH = '/admin/design-field/preview/ui';

    /** @var array<string,mixed> What the config file holds in a test */
    public static array $config = [];

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
                'tone' => ['label' => 'Tone', 'options' => ['light' => 'bg-white', 'dark' => 'bg-black'], 'default' => 'light'],
                'border' => ['label' => 'Border', 'input' => 'toggle', 'options' => ['off' => '', 'on' => 'border'], 'default' => 'off'],
                'shade' => ['label' => 'Shade', 'options' => ['soft' => 'opacity-50', 'full' => 'opacity-100'], 'showIf' => ['tone' => ['dark']]],
            ],
            'profiles' => ['blockCta' => ['tone', 'border', 'shade'], 'blockTeam' => ['tone'], 'pageBuilder:blockTeam' => ['tone', 'border'], '*' => ['tone']],
            'presets' => ['blockCta' => ['Night' => ['tone' => 'dark', 'border' => 'on']]],
        ];

        $this->app->instance(Groups::class, new class() extends Groups {
            public function config(): array
            {
                return PreviewTabTest::$config + parent::config();
            }
        });

        $this->actingAs(User::query()->findOrFail(1));
    }

    public function testItShowsTheBlockWithTheMostOptionsAndListsEveryBlock(): void
    {
        $props = self::props($this->get(self::URL));
        $nodes = self::nodes($props);
        $picker = $nodes['block']->control;

        self::assertSame('design-field:block-picker', $picker->component);
        self::assertSame('blockCta', $props->ui->values->block);
        self::assertSame(['blockCta', 'blockTeam', 'blockTeam (in pageBuilder)', 'Every other block (*)'], array_column($picker->props->options, 'label'));
        self::assertSame('pageBuilder:blockTeam', $picker->props->options[2]->value);
        self::assertStringContainsString('block=' . rawurlencode('pageBuilder:blockTeam'), $picker->props->options[2]->url);

        // The panel refreshes itself for the block in the page's address, and nothing is submitted.
        self::assertStringEndsWith('/design-field/preview/ui?block=blockCta', $props->refreshUrl);
        self::assertObjectNotHasProperty('submit', $props);

        // The panel is the field's own, presets included; without `blockPreviewUrl` no frame.
        $panel = $nodes['design']->control;
        self::assertSame('design-field:presets', $panel->component);
        self::assertSame(['Night'], array_column($panel->props->presets, 'label'));
        self::assertStringContainsString('blockPreviewUrl', $nodes['preview-intro']->props->html);

        // Handles and keys for writing presets, the default marked.
        $keys = array_column($nodes['preview-keys']->props->rows, null, 'id');
        self::assertSame(['tone', 'border', 'shade'], array_keys($keys));
        self::assertSame('<code>light</code> ★, <code>dark</code>', $keys['tone']->keys->html);
    }

    public function testItShowsTheBlockAskedForAndFallsBackForAnUnknownOne(): void
    {
        self::assertSame('blockTeam', self::props($this->get(self::URL . '&block=blockTeam'))->ui->values->block);
        self::assertSame('blockCta', self::props($this->get(self::URL . '&block=blockGone'))->ui->values->block);
    }

    public function testWithAPreviewUrlTheBlockItselfSitsBesideThePanel(): void
    {
        self::$config['blockPreviewUrl'] = '/_design-field/block?type={type}&design={design}';

        $panel = self::nodes(self::props($this->get(self::URL)))['design']->control;

        self::assertSame('design-field:preview', $panel->component);
        self::assertSame('/_design-field/block?type=blockCta&design={design}', $panel->props->frame->url);
        self::assertSame(['off', 'on'], $panel->props->frame->toggles->border, 'a switch is sent as its key');
        self::assertSame(['Night'], array_column($panel->props->presets, 'label'));

        // A block inside one Matrix field is shown by its type; `*` names no one block.
        $nested = self::nodes(self::props($this->get(self::URL . '&block=' . rawurlencode('pageBuilder:blockTeam'))))['design']->control;
        self::assertSame('/_design-field/block?type=blockTeam&design={design}', $nested->props->frame->url);
        self::assertSame('design-field:preview', $nested->component);
        self::assertNotSame('design-field:preview', self::nodes(self::props($this->get(self::URL . '&block=' . rawurlencode('*'))))['design']->control->component);
    }

    public function testAConfigurationThatDoesNotBuildSaysSo(): void
    {
        self::$config['profiles']['blockCta'] = ['tone', 'notAGroup'];

        self::assertSame('danger', self::nodes(self::props($this->get(self::URL)))['preview-error']->props->variant);
        $this->postJson(self::REFRESH . '?block=blockCta', ['values' => [], 'scope' => []])->assertStatus(422);
    }

    public function testThePanelRefreshesForTheChoicesItHolds(): void
    {
        $elements = DB::table('elements')->count();

        // As the Ui page posts it: the panel's own values, under the panel's path.
        $response = $this->postJson(self::REFRESH . '?block=blockCta', [
            'values' => ['tone' => 'dark', 'border' => true, 'shade' => 'full'],
            'scope' => ['design'],
        ])->assertOk();

        $ui = $response->json('ui');
        self::assertSame(['design'], $ui['scope']);
        self::assertSame(['tone' => 'dark', 'border' => true, 'shade' => 'full'], $ui['values']['design'], 'the choices posted come back');
        self::assertSame(['tone' => false, 'border' => false, 'shade' => false], self::hidden($ui['nodes']));
        self::assertSame($elements, DB::table('elements')->count(), 'nothing was saved');

        // An option that waits for another one follows it: Shade shows for a dark tone only.
        $light = $this->postJson(self::REFRESH . '?block=blockCta', ['values' => ['tone' => 'light'], 'scope' => ['design']])->json('ui');
        self::assertSame('light', $light['values']['design']['tone']);
        self::assertTrue(self::hidden($light['nodes'])['shade']);

        // The whole tab can be asked for too.
        $this->postJson(self::REFRESH . '?block=blockTeam', ['values' => ['design' => ['tone' => 'dark']], 'scope' => []])
            ->assertOk()
            ->assertJsonPath('ui.values.block', 'blockTeam');
    }

    public function testTheExamplePageForThePreviewUrlIsValidTwigOnCraft6(): void
    {
        $twig = TemplateMode::with(TemplateMode::Site, static fn() => Twig::create());
        $source = (string)file_get_contents(dirname(__DIR__, 2) . '/examples/block-preview.twig');

        // Parsing fails on a tag, filter or function Craft 6 does not have.
        $twig->parse($twig->tokenize(new Source($source, 'block-preview.twig')));
        self::assertArrayHasKey('Request', $twig->getGlobals(), 'the page reads the query with Request.query()');
        self::assertArrayHasKey('craft', $twig->getGlobals());
    }

    public function testOnlyAdminsRefreshThePanel(): void
    {
        // The route says so itself: `can:accessCp` alone would let any control panel user in.
        $route = Route::getRoutes()->match(Request::create(self::REFRESH, 'POST'));
        self::assertContains(RequireAdmin::class, Route::gatherRouteMiddleware($route));

        DB::table('users')->where('id', 1)->update(['admin' => false]);
        $this->app['auth']->forgetGuards();
        $this->actingAs(User::query()->findOrFail(1));

        $this->postJson(self::REFRESH . '?block=blockCta', ['values' => [], 'scope' => []])->assertForbidden();
        $this->get(self::URL)->assertForbidden();
    }

    // Helpers
    // =========================================================================

    /** The Inertia page props of the screen, as the Ui page receives them. */
    private static function props(TestResponse $response): object
    {
        $html = (string)$response->assertOk()->getContent();
        preg_match('#<script data-page="app" type="application/json">(.*?)</script>#s', $html, $m);

        return json_decode($m[1] ?? 'null', false, flags: JSON_THROW_ON_ERROR)->props;
    }

    /**
     * Whether each option of a refreshed panel is hidden, by handle.
     *
     * @param list<array<string,mixed>> $nodes
     * @return array<string,bool>
     */
    private static function hidden(array $nodes): array
    {
        $found = [];

        foreach ($nodes as $node) {
            if (isset($node['control']['path'])) {
                $path = (array)$node['control']['path'];
                $found[(string)end($path)] = (bool)($node['props']['hidden'] ?? false);
            }
        }

        return $found;
    }

    /** @return array<string,object> The screen's nodes by uid, a field by its control's path */
    private static function nodes(object $props): array
    {
        $nodes = [];

        foreach ($props->ui->nodes as $node) {
            $key = $node->uid ?? (isset($node->control->path) ? implode('.', (array)$node->control->path) : null);

            if ($key !== null) {
                $nodes[$key] = $node;
            }
        }

        return $nodes;
    }
}
