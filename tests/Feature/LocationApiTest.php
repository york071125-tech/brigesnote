<?php

namespace Tests\Feature;

use App\Models\District;
use App\Models\Province;
use App\Models\Region;
use App\Models\School;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Contract tests for the public REST API consumed by external applications.
 * They pin the URLs, parameters, HTTP status codes, field names and value
 * types of the JSON responses so the PHP 8.3 / Laravel 13 migration (and any
 * future change) cannot alter them unnoticed.
 */
class LocationApiTest extends TestCase
{
    use RefreshDatabase;

    private const REGION_FIELDS = ['idRegion', 'nombreRegion', 'pointX', 'pointY', 'zoom', 'created_at', 'updated_at'];
    private const PROVINCE_FIELDS = ['idProvincia', 'nombreProvincia', 'pointX', 'pointY', 'created_at', 'updated_at'];
    private const DISTRICT_FIELDS = ['idDistrito', 'nombreDistrito', 'created_at', 'updated_at'];
    private const SCHOOL_FIELDS = [
        'codMod', 'idDistrito', 'nombreCenEdu', 'cenPob', 'dirCen', 'director',
        'dis_crecer', 'dis_juntos', 'created_at', 'updated_at',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        Region::create(['idRegion' => '01', 'nombreRegion' => 'Amazonas', 'pointX' => '-78.0536004', 'pointY' => '-5.0720733', 'zoom' => 9]);
        Region::create(['idRegion' => '07', 'nombreRegion' => 'Callao', 'pointX' => '-77.1256254', 'pointY' => '-11.9400061', 'zoom' => 9]);

        Province::create(['idProvincia' => '0101', 'nombreProvincia' => 'Chachapoyas', 'pointX' => '-77.8686404', 'pointY' => '-6.2318123']);
        Province::create(['idProvincia' => '0102', 'nombreProvincia' => 'Bagua', 'pointX' => '-78.5316236', 'pointY' => '-5.6386925']);
        Province::create(['idProvincia' => '0701', 'nombreProvincia' => 'Prov. Const. del Callao', 'pointX' => '-77.1256254', 'pointY' => '-11.9400061']);

        District::create(['idDistrito' => '010101', 'nombreDistrito' => 'Chachapoyas']);
        District::create(['idDistrito' => '010102', 'nombreDistrito' => 'Asunción']);
        District::create(['idDistrito' => '070101', 'nombreDistrito' => 'Callao']);

        School::create([
            'codMod' => '0207894', 'idDistrito' => '070101', 'nombreCenEdu' => '5019 AUGUSTO CAZORLA',
            'cenPob' => 'CALLAO', 'dirCen' => 'AVENIDA REPUBLICA DE PANAMA 753', 'director' => 'OLIVAS SANTOME ALBERTO JESUS',
            'dis_crecer' => 'Primera etapa', 'dis_juntos' => 'Segunda etapa',
        ]);
        School::create([
            'codMod' => '0559526', 'idDistrito' => '010101', 'nombreCenEdu' => '18095',
            'cenPob' => 'CHUELTA', 'dirCen' => 'CARRETERA CHUELTA S/N', 'director' => null,
            'dis_crecer' => null, 'dis_juntos' => null,
        ]);
        School::create([
            'codMod' => '0559527', 'idDistrito' => '010101', 'nombreCenEdu' => '18096',
            'cenPob' => 'CHACHAPOYAS', 'dirCen' => 'JR. AMAZONAS 100', 'director' => 'PEREZ PEDRO',
            'dis_crecer' => 'No aplica', 'dis_juntos' => 'No aplica',
        ]);
    }

    public function test_the_four_api_endpoints_are_registered(): void
    {
        $routes = collect(Route::getRoutes()->getRoutes())
            ->mapWithKeys(fn ($route) => [$route->uri() => [$route->methods(), $route->getActionName()]]);

        $expected = [
            'api/regiones' => 'getRegions',
            'api/provincias/{idRegion}' => 'getProvinces',
            'api/distritos/{idProvince}' => 'getDistricts',
            'api/colegios/{ubigeo}' => 'getSchools',
        ];

        foreach ($expected as $uri => $method) {
            $this->assertArrayHasKey($uri, $routes->all(), "Route $uri is not registered");
            $this->assertContains('GET', $routes[$uri][0]);
            $this->assertSame("App\\Http\\Controllers\\LocationController@$method", $routes[$uri][1]);
        }
    }

    public function test_regiones_returns_every_region(): void
    {
        $response = $this->getJson('/api/regiones');

        $response->assertOk()
            ->assertExactJsonStructure(['status', 'data' => ['*' => self::REGION_FIELDS]])
            ->assertJsonPath('status', 'success')
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.idRegion', '01')
            ->assertJsonPath('data.0.nombreRegion', 'Amazonas')
            ->assertJsonPath('data.1.idRegion', '07');

        // Value types received by the client applications (same as with PHP 7.4 / Laravel 7).
        $this->assertSame('-78.0536004', $response->json('data.0.pointX'));
        $this->assertSame('-5.0720733', $response->json('data.0.pointY'));
        $this->assertSame(9, $response->json('data.0.zoom'));
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z$/', $response->json('data.0.created_at'));
    }

    public function test_provincias_filters_by_the_first_two_digits_of_the_region(): void
    {
        $this->getJson('/api/provincias/01')
            ->assertOk()
            ->assertExactJsonStructure(['status', 'data' => ['*' => self::PROVINCE_FIELDS]])
            ->assertJsonPath('status', 'success')
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.idProvincia', '0101')
            ->assertJsonPath('data.0.pointX', '-77.8686404')
            ->assertJsonPath('data.1.idProvincia', '0102');

        $this->getJson('/api/provincias/07')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.nombreProvincia', 'Prov. Const. del Callao');
    }

    public function test_provincias_returns_404_when_the_region_has_no_provinces(): void
    {
        $this->getJson('/api/provincias/99')
            ->assertNotFound()
            ->assertExactJson([
                'status' => 'error',
                'message' => 'No se encontraron provincias para esta región.',
            ]);
    }

    public function test_distritos_filters_by_the_first_four_digits_of_the_province(): void
    {
        $this->getJson('/api/distritos/0101')
            ->assertOk()
            ->assertExactJsonStructure(['status', 'data' => ['*' => self::DISTRICT_FIELDS]])
            ->assertJsonPath('status', 'success')
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.idDistrito', '010101')
            ->assertJsonPath('data.1.nombreDistrito', 'Asunción');
    }

    public function test_distritos_returns_404_when_the_province_has_no_districts(): void
    {
        $this->getJson('/api/distritos/9999')
            ->assertNotFound()
            ->assertExactJson([
                'status' => 'error',
                'message' => 'No se encontraron distritos para esta provincia.',
            ]);
    }

    public function test_colegios_returns_the_schools_of_the_ubigeo(): void
    {
        $this->getJson('/api/colegios/010101')
            ->assertOk()
            ->assertExactJsonStructure(['status', 'data' => ['*' => self::SCHOOL_FIELDS]])
            ->assertJsonPath('status', 'success')
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.codMod', '0559526')
            ->assertJsonPath('data.0.idDistrito', '010101')
            ->assertJsonPath('data.0.director', null)
            ->assertJsonPath('data.1.nombreCenEdu', '18096');

        $this->getJson('/api/colegios/070101')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.dis_crecer', 'Primera etapa')
            ->assertJsonPath('data.0.dis_juntos', 'Segunda etapa');
    }

    public function test_colegios_returns_404_when_the_ubigeo_has_no_schools(): void
    {
        $this->getJson('/api/colegios/999999')
            ->assertNotFound()
            ->assertExactJson([
                'status' => 'error',
                'message' => 'No se encontraron escuelas para este distrito.',
            ]);
    }

    public function test_api_keeps_cors_and_rate_limit_headers(): void
    {
        $this->getJson('/api/regiones', ['Origin' => 'https://admision.example.org'])
            ->assertOk()
            ->assertHeader('Access-Control-Allow-Origin', '*')
            ->assertHeader('X-RateLimit-Limit', '60');
    }
}
