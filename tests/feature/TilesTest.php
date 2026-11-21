<?php
declare(strict_types=1);

namespace wmd\designfield\tests\feature;

use CraftCms\Cms\Cms;
use CraftCms\Cms\Http\Middleware\RequireAdmin;
use CraftCms\Cms\Http\Middleware\RequireAdminChanges;
use CraftCms\Cms\Plugin\Testing\PluginTestCase;
use CraftCms\Cms\User\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use wmd\designfield\services\Groups;

/**
 * Make tile pictures (the settings page's Preview tab): a layout's picture, drawn in
 * the browser, is saved to `tileImages` under the web root, where admin changes are allowed.
 */
final class TilesTest extends PluginTestCase
{
    private const string SAVE = '/admin/design-field/tiles/save';

    private const string TAB = '/admin/settings/plugins/design-field?tab=preview&block=blockHero';

    /** @var array<string,mixed> What the config file holds in a test */
    public static array $config = [];

    private string $webRoot;

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
                'variant' => ['label' => 'Layout', 'options' => ['_default' => ['label' => 'Split'], 'wide' => ['label' => 'Wide']]],
            ],
            'profiles' => ['blockHero' => ['variant', 'tone'], 'pageBuilder:blockHero' => ['variant'], 'blockCta' => ['tone']],
            'blockPreviewUrl' => '/_design-field/block?type={type}&design={design}',
        ];

        $this->readConfig();

        $this->webRoot = sys_get_temp_dir() . '/design-field-tiles-' . bin2hex(random_bytes(4));
        File::ensureDirectoryExists($this->webRoot);
        $this->app->usePublicPath($this->webRoot);

        $this->actingAs(User::query()->findOrFail(1));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->webRoot);

        parent::tearDown();
    }

    public function testThePreviewTabOffersItForABlockWithLayouts(): void
    {
        $tiles = $this->panel(self::TAB)->props->tiles;

        self::assertStringEndsWith('/design-field/tiles/save', $tiles->saveUrl);
        self::assertSame('blockHero', $tiles->block);
        self::assertSame([['key' => '_default', 'label' => 'Split', 'picture' => null], ['key' => 'wide', 'label' => 'Wide', 'picture' => null]], json_decode(json_encode($tiles->layouts), true));

        // Not for a block without a layout list, and not where pictures are off.
        self::assertObjectNotHasProperty('tiles', $this->panel(str_replace('blockHero', 'blockCta', self::TAB))->props);
        self::$config['tileImages'] = '';
        $this->readConfig();
        self::assertObjectNotHasProperty('tiles', $this->panel(self::TAB)->props);
    }

    public function testItIsNotOfferedWhereAdminChangesAreOff(): void
    {
        Cms::config()->allowAdminChanges = false;

        $panel = $this->panel(self::TAB);

        self::assertSame('design-field:preview', $panel->component, 'the preview itself stays');
        self::assertObjectNotHasProperty('tiles', $panel->props);
        $this->postJson(self::SAVE, ['block' => 'blockHero', 'key' => 'wide', 'image' => self::jpeg()])->assertForbidden();
        self::assertFileDoesNotExist($this->webRoot . '/design-field/blockHero/wide.jpg');
    }

    public function testAPictureIsWrittenToTheTilePathAndThePanelShowsIt(): void
    {
        $jpeg = self::jpeg();
        $response = $this->postJson(self::SAVE, ['block' => 'blockHero', 'key' => 'wide', 'image' => $jpeg])->assertOk();

        $file = $this->webRoot . '/design-field/blockHero/wide.jpg';
        self::assertFileExists($file);
        self::assertSame(base64_decode(substr($jpeg, 23)), file_get_contents($file));
        self::assertStringStartsWith('/design-field/blockHero/wide.jpg?v=', $response->json('url'));

        // The next request reads the folder again: the layout has its picture.
        $this->readConfig();
        $layouts = array_column($this->panel(self::TAB)->props->tiles->layouts, 'picture', 'key');
        self::assertStringStartsWith('/design-field/blockHero/wide.jpg?v=', $layouts['wide']);
        self::assertNull($layouts['_default']);

        // The same picture serves the block inside a Matrix field: tiles are per entry type.
        $this->postJson(self::SAVE, ['block' => 'pageBuilder:blockHero', 'key' => '_default', 'image' => $jpeg])->assertOk();
        self::assertFileExists($this->webRoot . '/design-field/blockHero/_default.jpg');
    }

    public function testOnlyALayoutOfTheBlockAndA480PxJpegAreTaken(): void
    {
        $refused = [
            'a block that is not there' => ['block' => 'blockGone', 'key' => 'wide', 'image' => self::jpeg()],
            'a block without layouts' => ['block' => 'blockCta', 'key' => 'wide', 'image' => self::jpeg()],
            'a layout the block does not have' => ['block' => 'blockHero', 'key' => 'tall', 'image' => self::jpeg()],
            'a path instead of a key' => ['block' => 'blockHero', 'key' => '../../evil', 'image' => self::jpeg()],
            'another width' => ['block' => 'blockHero', 'key' => 'wide', 'image' => self::jpeg(400)],
            'a PNG' => ['block' => 'blockHero', 'key' => 'wide', 'image' => self::png()],
            'a PNG called a JPEG' => ['block' => 'blockHero', 'key' => 'wide', 'image' => 'data:image/jpeg;base64,' . substr(self::png(), 22)],
            'not a picture' => ['block' => 'blockHero', 'key' => 'wide', 'image' => 'data:image/jpeg;base64,' . base64_encode('<?php echo 1;')],
            'more than 2 MB' => ['block' => 'blockHero', 'key' => 'wide', 'image' => 'data:image/jpeg;base64,' . base64_encode(base64_decode(substr(self::jpeg(), 23)) . str_repeat('x', 2_000_000))],
        ];

        foreach ($refused as $what => $body) {
            self::assertSame(422, $this->postJson(self::SAVE, $body)->getStatusCode(), $what);
        }

        self::assertSame([], File::allFiles($this->webRoot), 'nothing was written');
    }

    public function testOnlyAdminsSaveAPicture(): void
    {
        $middleware = Route::gatherRouteMiddleware(Route::getRoutes()->match(Request::create(self::SAVE, 'POST')));
        self::assertContains(RequireAdmin::class, $middleware);
        self::assertContains(RequireAdminChanges::class, $middleware);

        DB::table('users')->where('id', 1)->update(['admin' => false]);
        $this->app['auth']->forgetGuards();
        $this->actingAs(User::query()->findOrFail(1));

        $this->postJson(self::SAVE, ['block' => 'blockHero', 'key' => 'wide', 'image' => self::jpeg()])->assertForbidden();
        self::assertSame([], File::allFiles($this->webRoot));
    }

    // Helpers
    // =========================================================================

    /** The configuration as a new request reads it: the registry is built once per request. */
    private function readConfig(): void
    {
        $this->app->instance(Groups::class, new class() extends Groups {
            public function config(): array
            {
                return TilesTest::$config + parent::config();
            }
        });
    }

    /** The Preview tab's panel control, as the Ui page receives it. */
    private function panel(string $url): object
    {
        $html = (string)$this->get($url)->assertOk()->getContent();
        preg_match('#<script data-page="app" type="application/json">(.*?)</script>#s', $html, $m);

        foreach (json_decode($m[1] ?? 'null', false, flags: JSON_THROW_ON_ERROR)->props->ui->nodes as $node) {
            if (($node->control->path ?? null) === ['design']) {
                return $node->control;
            }
        }

        self::fail('The tab has no panel.');
    }

    private static function jpeg(int $width = 480): string
    {
        $image = imagecreatetruecolor($width, 200);
        ob_start();
        imagejpeg($image, null, 80);

        return 'data:image/jpeg;base64,' . base64_encode((string)ob_get_clean());
    }

    private static function png(): string
    {
        $image = imagecreatetruecolor(480, 200);
        ob_start();
        imagepng($image);

        return 'data:image/png;base64,' . base64_encode((string)ob_get_clean());
    }
}
