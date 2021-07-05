<?php

namespace Knuckles\Scribe\Tests;

use Illuminate\Support\Facades\Route as RouteFacade;
use Knuckles\Scribe\Tests\Fixtures\TestController;
use Knuckles\Scribe\Tests\Fixtures\TestGroupController;
use Knuckles\Scribe\Tests\Fixtures\TestIgnoreThisController;
use Knuckles\Scribe\Tests\Fixtures\TestPartialResourceController;
use Knuckles\Scribe\Tests\Fixtures\TestResourceController;
use Knuckles\Scribe\Tests\Fixtures\TestUser;
use Knuckles\Scribe\Tools\Utils;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Yaml\Yaml;

class GenerateDocumentationTest extends BaseLaravelTest
{
    use TestHelpers;

    protected function setUp(): void
    {
        parent::setUp();

        config(['scribe.database_connections_to_transact' => []]);

        $factory = app(\Illuminate\Database\Eloquent\Factory::class);
        $factory->define(TestUser::class, function () {
            return [
                'id' => 4,
                'first_name' => 'Tested',
                'last_name' => 'Again',
                'email' => 'a@b.com',
            ];
        });
    }

    public function tearDown(): void
    {
        Utils::deleteDirectoryAndContents('public/docs');
        Utils::deleteDirectoryAndContents('.scribe');
    }

    /** @test */
    public function can_process_traditional_laravel_route_syntax()
    {
        RouteFacade::get('/api/test', [TestController::class, 'withEndpointDescription']);

        config(['scribe.routes.0.match.prefixes' => ['api/*']]);
        $output = $this->artisan('scribe:generate');

        $this->assertStringContainsString('Processed route: [GET] api/test', $output);
    }

    /** @test */
    public function can_process_traditional_laravel_head_routes()
    {
        RouteFacade::addRoute('HEAD', '/api/test', [TestController::class, 'withEndpointDescription']);

        config(['scribe.routes.0.match.prefixes' => ['api/*']]);
        $output = $this->artisan('scribe:generate');

        $this->assertStringContainsString('Processed route: [HEAD] api/test', $output);
    }

    /**
     * @test
     * @see https://github.com/knuckleswtf/scribe/issues/53
     */
    public function can_process_closure_routes()
    {
        RouteFacade::get('/api/closure', function () {
            return 'hi';
        });

        config(['scribe.routes.0.match.prefixes' => ['api/*']]);
        $output = $this->artisan('scribe:generate');

        $this->assertStringContainsString('Processed route: [GET] api/closure', $output);
    }

    /**
     * @group dingo
     * @test
     */
    public function can_process_routes_on_dingo()
    {
        $api = app(\Dingo\Api\Routing\Router::class);
        $api->version('v1', function ($api) {
            $api->get('/closure', function () {
                return 'foo';
            });
            $api->get('/test', [TestController::class, 'withEndpointDescription']);
        });

        config(['scribe.routes.0.match.prefixes' => ['*']]);
        config(['scribe.routes.0.match.versions' => ['v1']]);
        $output = $this->artisan('scribe:generate');

        $this->assertStringContainsString('Processed route: [GET] closure', $output);
        $this->assertStringContainsString('Processed route: [GET] test', $output);
    }

    /** @test */
    public function can_process_callable_tuple_syntax()
    {
        RouteFacade::get('/api/array/test', [TestController::class, 'withEndpointDescription']);

        config(['scribe.routes.0.match.prefixes' => ['api/*']]);
        $output = $this->artisan('scribe:generate');

        $this->assertStringContainsString('Processed route: [GET] api/array/test', $output);
    }

    /** @test */
    public function can_skip_methods_and_classes_with_hidefromapidocumentation_tag()
    {
        RouteFacade::get('/api/skip', [TestController::class, 'skip']);
        RouteFacade::get('/api/skipClass', TestIgnoreThisController::class . '@dummy');
        RouteFacade::get('/api/test', [TestController::class, 'withEndpointDescription']);

        config(['scribe.routes.0.match.prefixes' => ['api/*']]);
        $output = $this->artisan('scribe:generate');

        $this->assertStringContainsString('Skipping route: [GET] api/skip', $output);
        $this->assertStringContainsString('Skipping route: [GET] api/skipClass', $output);
        $this->assertStringContainsString('Processed route: [GET] api/test', $output);
    }

    /** @test */
    public function can_skip_nonexistent_response_files()
    {
        RouteFacade::get('/api/non-existent', [TestController::class, 'withNonExistentResponseFile']);

        config(['scribe.routes.0.match.prefixes' => ['api/*']]);
        $output = $this->artisan('scribe:generate');

        $this->assertStringContainsString('@responseFile i-do-not-exist.json does not exist', $output);
    }

    /** @test */
    public function can_parse_resource_routes()
    {
        RouteFacade::resource('/api/users', TestResourceController::class)
            ->only(['index', 'store']);

        config(['scribe.routes.0.match.prefixes' => ['api/*']]);
        config([
            'scribe.routes.0.apply.headers' => [
                'Accept' => 'application/json',
            ],
        ]);

        $output = $this->artisan('scribe:generate');

        $this->assertStringContainsString('Processed route: [GET] api/users', $output);
        $this->assertStringContainsString('Processed route: [POST] api/users', $output);

        $this->assertStringNotContainsString('Processed route: [PUT,PATCH] api/users/{user}', $output);
        $this->assertStringNotContainsString('Processed route: [DELETE] api/users/{user}', $output);

        RouteFacade::apiResource('/api/users', TestResourceController::class)
            ->only(['index', 'store']);
        $output = $this->artisan('scribe:generate');

        $this->assertStringContainsString('Processed route: [GET] api/users', $output);
        $this->assertStringContainsString('Processed route: [POST] api/users', $output);

        $this->assertStringNotContainsString('Processed route: [PUT,PATCH] api/users/{user}', $output);
        $this->assertStringNotContainsString('Processed route: [DELETE] api/users/{user}', $output);
    }

    /** @test */
    public function supports_partial_resource_controller()
    {
        RouteFacade::resource('/api/users', TestPartialResourceController::class);

        config(['scribe.routes.0.prefixes' => ['api/*']]);

        $output = $this->artisan('scribe:generate');

        $this->assertStringContainsString('Processed route: [GET] api/users', $output);
        $this->assertStringContainsString('Processed route: [PUT,PATCH] api/users/{user}', $output);

    }

    /** @test */
    public function generated_postman_collection_file_is_correct()
    {
        RouteFacade::post('/api/withBodyParametersAsArray', [TestController::class, 'withBodyParametersAsArray']);
        RouteFacade::post('/api/withFormDataParams', [TestController::class, 'withFormDataParams']);
        RouteFacade::post('/api/withBodyParameters', [TestController::class, 'withBodyParameters']);
        RouteFacade::get('/api/withQueryParameters', [TestController::class, 'withQueryParameters']);
        RouteFacade::get('/api/withAuthTag', [TestController::class, 'withAuthenticatedTag']);
        RouteFacade::get('/api/echoesUrlParameters/{param}/{param2}/{param3?}/{param4?}', [TestController::class, 'echoesUrlParameters']);
        // We want to have the same values for params each time
        config(['scribe.faker_seed' => 1234]);
        config(['scribe.title' => 'GREAT API!']);
        config(['scribe.auth.enabled' => true]);
        config(['scribe.routes.0.match.prefixes' => ['api/*']]);
        config(['scribe.postman.overrides' => [
            'info.version' => '3.9.9',
        ]]);
        config([
            'scribe.routes.0.apply.headers' => [
                'Custom-Header' => 'NotSoCustom',
            ],
        ]);
        config(['scribe.postman.enabled' => true]);
        config(['scribe.openapi.enabled' => false]);

        $this->artisan('scribe:generate');

        $generatedCollection = json_decode(file_get_contents(__DIR__ . '/../public/docs/collection.json'), true);
        // The Postman ID varies from call to call; erase it to make the test data reproducible.
        $generatedCollection['info']['_postman_id'] = '';
        $fixtureCollection = json_decode(file_get_contents(__DIR__ . '/Fixtures/collection.json'), true);

        $this->assertEquals($fixtureCollection, $generatedCollection);
    }

    /** @test */
    public function generated_openapi_spec_file_is_correct()
    {
        RouteFacade::post('/api/withBodyParametersAsArray', [TestController::class, 'withBodyParametersAsArray']);
        RouteFacade::post('/api/withFormDataParams', [TestController::class, 'withFormDataParams']);
        RouteFacade::get('/api/withResponseTag', [TestController::class, 'withResponseTag']);
        RouteFacade::get('/api/withQueryParameters', [TestController::class, 'withQueryParameters']);
        RouteFacade::get('/api/withAuthTag', [TestController::class, 'withAuthenticatedTag']);
        RouteFacade::get('/api/echoesUrlParameters/{param}/{param2}/{param3?}/{param4?}', [TestController::class, 'echoesUrlParameters']);

        // We want to have the same values for params each time
        config(['scribe.faker_seed' => 1234]);
        config(['scribe.postman.enabled' => false]);
        config(['scribe.openapi.enabled' => true]);
        config(['scribe.openapi.overrides' => [
            'info.version' => '3.9.9',
        ]]);
        config(['scribe.routes.0.match.prefixes' => ['api/*']]);
        config([
            'scribe.routes.0.apply.headers' => [
                'Custom-Header' => 'NotSoCustom',
            ],
        ]);

        $this->artisan('scribe:generate');

        $generatedSpec = Yaml::parseFile(__DIR__ . '/../public/docs/openapi.yaml');
        $fixtureSpec = Yaml::parseFile(__DIR__ . '/Fixtures/openapi.yaml');
        $this->assertEquals($fixtureSpec, $generatedSpec);
    }

    /** @test */
    public function can_append_custom_http_headers()
    {
        RouteFacade::get('/api/headers', [TestController::class, 'checkCustomHeaders']);

        config(['scribe.routes.0.match.prefixes' => ['api/*']]);
        config([
            'scribe.routes.0.apply.headers' => [
                'Authorization' => 'customAuthToken',
                'Custom-Header' => 'NotSoCustom',
            ],
        ]);
        $this->artisan('scribe:generate');

        $endpointDetails = Yaml::parseFile(__DIR__ . '/../.scribe/endpoints/0.yaml')['endpoints'][0];
        $this->assertEquals("customAuthToken", $endpointDetails['headers']["Authorization"]);
        $this->assertEquals("NotSoCustom", $endpointDetails['headers']["Custom-Header"]);
    }

    /** @test */
    public function can_parse_utf8_response()
    {
        RouteFacade::get('/api/utf8', [TestController::class, 'withUtf8ResponseTag']);

        config(['scribe.routes.0.prefixes' => ['api/*']]);
        $this->artisan('scribe:generate');

        $generatedHtml = file_get_contents('public/docs/index.html');
        $this->assertStringContainsString('Лорем ипсум долор сит амет', $generatedHtml);
    }

    /** @test */
    public function sorts_group_naturally()
    {
        RouteFacade::get('/api/action1', TestGroupController::class . '@action1');
        RouteFacade::get('/api/action1b', TestGroupController::class . '@action1b');
        RouteFacade::get('/api/action2', TestGroupController::class . '@action2');
        RouteFacade::get('/api/action10', TestGroupController::class . '@action10');

        config(['scribe.routes.0.prefixes' => ['api/*']]);
        $this->artisan('scribe:generate');

        $this->assertFileExists(__DIR__ . '/../.scribe/endpoints/0.yaml');
        $this->assertFileExists(__DIR__ . '/../.scribe/endpoints/1.yaml');
        $this->assertFileExists(__DIR__ . '/../.scribe/endpoints/2.yaml');
        $this->assertEquals('1. Group 1', Yaml::parseFile(__DIR__ . '/../.scribe/endpoints/0.yaml')['name']);
        $this->assertEquals('2. Group 2', Yaml::parseFile(__DIR__ . '/../.scribe/endpoints/1.yaml')['name']);
        $this->assertEquals('10. Group 10', Yaml::parseFile(__DIR__ . '/../.scribe/endpoints/2.yaml')['name']);
    }

    /** @test */
    public function can_customise_static_output_path()
    {
        RouteFacade::get('/api/action1', TestGroupController::class . '@action1');

        config(['scribe.routes.0.prefixes' => ['*']]);
        config(['scribe.static.output_path' => 'static/docs']);
        $this->artisan('scribe:generate');

        $this->assertFileExists('static/docs/index.html');

        Utils::deleteDirectoryAndContents('static/');
    }

    /** @test */
    public function will_not_overwrite_manually_modified_content_unless_force_flag_is_set()
    {
        RouteFacade::get('/api/action1', [TestGroupController::class, 'action1']);
        RouteFacade::get('/api/action1b', [TestGroupController::class, 'action1b']);
        config(['scribe.routes.0.prefixes' => ['api/*']]);

        $this->artisan('scribe:generate');

        $authFilePath = '.scribe/auth.md';
        $group1FilePath = '.scribe/endpoints/0.yaml';

        $group = Yaml::parseFile($group1FilePath);
        $this->assertEquals('api/action1', $group['endpoints'][0]['uri']);
        $this->assertEquals([], $group['endpoints'][0]['urlParameters']);
        $extraParam = [
            'name' => 'a_param',
            'description' => 'A URL param.',
            'required' => true,
            'example' => 6,
            'type' => 'integer',
        ];
        $group['endpoints'][0]['urlParameters']['a_param'] = $extraParam;
        file_put_contents($group1FilePath, Yaml::dump(
            $group, 20, 2,
            Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE | Yaml::DUMP_OBJECT_AS_MAP
        ));
        file_put_contents($authFilePath, 'Some other useful stuff.', FILE_APPEND);

        $this->artisan('scribe:generate');

        $group = Yaml::parseFile($group1FilePath);
        $this->assertEquals('api/action1', $group['endpoints'][0]['uri']);
        $this->assertEquals(['a_param' => $extraParam], $group['endpoints'][0]['urlParameters']);
        $this->assertStringContainsString('Some other useful stuff.', file_get_contents($authFilePath));

        $this->artisan('scribe:generate', ['--force' => true]);

        $group = Yaml::parseFile($group1FilePath);
        $this->assertEquals('api/action1', $group['endpoints'][0]['uri']);
        $this->assertEquals([], $group['endpoints'][0]['urlParameters']);
        $this->assertStringNotContainsString('Some other useful stuff.', file_get_contents($authFilePath));
    }

    /** @test */
    public function will_not_extract_if_noExtraction_flag_is_set()
    {
        config(['scribe.routes.0.exclude' => ['*']]);
        Utils::copyDirectory(__DIR__.'/Fixtures/.scribe', '.scribe');

        $output = $this->artisan('scribe:generate', ['--no-extraction' => true]);

        $this->assertStringNotContainsString("Processing route", $output);

        $crawler = new Crawler(file_get_contents('public/docs/index.html'));
        [$intro, $auth] = $crawler->filter('h1 + p')->getIterator();
        $this->assertEquals('Heyaa introduction!👋', trim($intro->firstChild->textContent));
        $this->assertEquals('This is just a test.', trim($auth->firstChild->textContent));
        $endpoints = $crawler->filter('h1')->getNode(2);
        $this->assertEquals('General', trim($endpoints->textContent));
        $expectedEndpoint = $crawler->filter('h2');
        $this->assertCount(1, $expectedEndpoint);
        $this->assertEquals("Healthcheck", $expectedEndpoint->text());
    }

    /** @test */
    public function merges_user_defined_endpoints()
    {
        RouteFacade::get('/api/action1', [TestGroupController::class, 'action1']);
        RouteFacade::get('/api/action2', [TestGroupController::class, 'action2']);
        config(['scribe.routes.0.prefixes' => ['api/*']]);
        if (!is_dir('.scribe/endpoints'))
            mkdir('.scribe/endpoints', 0777, true);
        copy(__DIR__ . '/Fixtures/custom.0.yaml', '.scribe/endpoints/custom.0.yaml');

        $this->artisan('scribe:generate');

        $crawler = new Crawler(file_get_contents('public/docs/index.html'));
        $headings = $crawler->filter('h1')->getIterator();
        // There should only be four headings — intro, auth and two groups
        $this->assertCount(4, $headings);
        [$_, $_, $group1, $group2] = $headings;
        $this->assertEquals('1. Group 1', trim($group1->textContent));
        $this->assertEquals('2. Group 2', trim($group2->textContent));
        $expectedEndpoints = $crawler->filter('h2');
        $this->assertEquals(3, $expectedEndpoints->count());
        // Enforce the order of the endpoints
        // Ideally, we should also check the groups they're under
        $this->assertEquals("Some endpoint.", $expectedEndpoints->getNode(0)->textContent);
        $this->assertEquals("User defined", $expectedEndpoints->getNode(1)->textContent);
        $this->assertEquals("GET api/action2", $expectedEndpoints->getNode(2)->textContent);
    }

    /** @test */
    public function respects_endpoints_and_group_sort_order()
    {
        RouteFacade::get('/api/action1', [TestGroupController::class, 'action1']);
        RouteFacade::get('/api/action1b', [TestGroupController::class, 'action1b']);
        RouteFacade::get('/api/action2', [TestGroupController::class, 'action2']);
        config(['scribe.routes.0.prefixes' => ['api/*']]);

        $this->artisan('scribe:generate');

        // First: verify the current order of the groups and endpoints
        $crawler = new Crawler(file_get_contents('public/docs/index.html'));
        $h1s = $crawler->filter('h1');
        $this->assertEquals('1. Group 1', trim($h1s->getNode(2)->textContent));
        $this->assertEquals('2. Group 2', trim($h1s->getNode(3)->textContent));
        $expectedEndpoints = $crawler->filter('h2');
        $this->assertEquals("Some endpoint.", $expectedEndpoints->getNode(0)->textContent);
        $this->assertEquals("Another endpoint.", $expectedEndpoints->getNode(1)->textContent);
        $this->assertEquals("GET api/action2", $expectedEndpoints->getNode(2)->textContent);

        // Now swap the endpoints
        $group = Yaml::parseFile('.scribe/endpoints/0.yaml');
        $this->assertEquals('api/action1', $group['endpoints'][0]['uri']);
        $this->assertEquals('api/action1b', $group['endpoints'][1]['uri']);
        $action1 = $group['endpoints'][0];
        $group['endpoints'][0] = $group['endpoints'][1];
        $group['endpoints'][1] = $action1;
        file_put_contents('.scribe/endpoints/0.yaml', Yaml::dump(
            $group, 20, 2,
            Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE | Yaml::DUMP_OBJECT_AS_MAP
        ));
        // And then the groups
        rename('.scribe/endpoints/0.yaml', '.scribe/endpoints/temp.yaml');
        rename('.scribe/endpoints/1.yaml', '.scribe/endpoints/0.yaml');
        rename('.scribe/endpoints/temp.yaml', '.scribe/endpoints/1.yaml');

        $this->artisan('scribe:generate');

        $crawler = new Crawler(file_get_contents('public/docs/index.html'));
        $h1s = $crawler->filter('h1');
        $this->assertEquals('2. Group 2', trim($h1s->getNode(2)->textContent));
        $this->assertEquals('1. Group 1', trim($h1s->getNode(3)->textContent));
        $expectedEndpoints = $crawler->filter('h2');
        $this->assertEquals("GET api/action2", $expectedEndpoints->getNode(0)->textContent);
        $this->assertEquals("Another endpoint.", $expectedEndpoints->getNode(1)->textContent);
        $this->assertEquals("Some endpoint.", $expectedEndpoints->getNode(2)->textContent);
    }
}
