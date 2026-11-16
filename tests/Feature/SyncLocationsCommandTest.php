<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Tests for "php artisan sync:locations" without calling the real MINEDU /
 * ESCALE servers: their responses are simulated with Http::fake(), using the
 * same JSON shapes ESCALE returns (lists, or a single object when there is
 * only one province/district/school).
 */
class SyncLocationsCommandTest extends TestCase
{
    use RefreshDatabase;

    private const ESCALE = 'https://escale.minedu.gob.pe/padron/rest/';

    private function fakeEscale(): void
    {
        $responses = [
            'regiones' => ['region' => [
                ['idRegion' => '01', 'nombreRegion' => 'Amazonas', 'pointX' => '-78.0536003739', 'pointY' => '-5.0720732779', 'zoom' => '9'],
                ['idRegion' => '07', 'nombreRegion' => 'Callao', 'pointX' => '-77.1256253589', 'pointY' => '-11.940006089', 'zoom' => '9'],
            ]],
            // List of provinces.
            'provincias?idRegion=01' => ['provincia' => [
                ['idProvincia' => '0101', 'nombreProvincia' => 'Chachapoyas', 'pointX' => '-77.8686404', 'pointY' => '-6.2318123', 'zoom' => '10'],
                ['idProvincia' => '0102', 'nombreProvincia' => 'Bagua', 'pointX' => '-78.5316236', 'pointY' => '-5.6386925', 'zoom' => '10'],
            ]],
            // A single province comes as an object, not as a list.
            'provincias?idRegion=07' => ['provincia' => [
                'idProvincia' => '0701', 'nombreProvincia' => 'Prov. Const. del Callao', 'pointX' => '-77.1256253589', 'pointY' => '-11.940006089', 'zoom' => '10',
            ]],
            'distritos?idProvincia=0101' => ['distrito' => [
                ['idDistrito' => '010101', 'nombreDistrito' => 'Chachapoyas'],
                ['idDistrito' => '010102', 'nombreDistrito' => 'Asunción'],
            ]],
            'distritos?idProvincia=0102' => ['distrito' => ['idDistrito' => '010201', 'nombreDistrito' => 'Bagua']],
            'distritos?idProvincia=0701' => ['distrito' => [['idDistrito' => '070101', 'nombreDistrito' => 'Callao']]],
            'instituciones?estados=1&ubigeo=010101' => ['items' => [
                ['codMod' => '0559526', 'cenEdu' => '18095', 'cenPob' => 'CHUELTA', 'dirCen' => 'CARRETERA CHUELTA S/N',
                    'director' => 'GUIVIN LOPEZ MERCEDES', 'dis_crecer' => 'Segunda etapa', 'dis_juntos' => 'Segunda etapa'],
                // ESCALE omits optional fields for some schools.
                ['codMod' => '0559527', 'cenEdu' => '18096', 'cenPob' => 'CHACHAPOYAS', 'dirCen' => 'JR. AMAZONAS 100'],
            ]],
            // A single school comes as an object, not as a list.
            'instituciones?estados=1&ubigeo=010102' => ['items' => [
                'codMod' => '0700001', 'cenEdu' => 'ASUNCION', 'cenPob' => 'ASUNCION', 'dirCen' => 'PLAZA DE ARMAS', 'director' => 'ROJAS ANA',
            ]],
            // District without schools: ESCALE returns no "items" key.
            'instituciones?estados=1&ubigeo=010201' => ['@uri' => self::ESCALE.'instituciones'],
        ];

        Http::fake(function (Request $request) use ($responses) {
            $key = substr($request->url(), strlen(self::ESCALE));

            if ($key === 'instituciones?estados=1&ubigeo=070101') {
                return Http::response('Service Unavailable', 503);
            }

            return isset($responses[$key])
                ? Http::response($responses[$key], 200)
                : Http::response('Not Found', 404);
        });
    }

    public function test_it_stores_regions_provinces_districts_and_schools(): void
    {
        $this->fakeEscale();

        $this->artisan('sync:locations')
            ->expectsOutput('Iniciando sincronización de ubicaciones...')
            ->expectsOutput('Sincronización completa.')
            ->assertExitCode(0);

        $this->assertDatabaseCount('regions', 2);
        $this->assertDatabaseCount('provinces', 3);
        $this->assertDatabaseCount('districts', 4);
        $this->assertDatabaseCount('schools', 3);

        $this->assertDatabaseHas('regions', [
            'idRegion' => '07', 'nombreRegion' => 'Callao', 'pointX' => '-77.1256254', 'pointY' => '-11.9400061', 'zoom' => 9,
        ]);
        // Single-object responses (province 0701, district 010201, school 0700001) are stored too.
        $this->assertDatabaseHas('provinces', ['idProvincia' => '0701', 'nombreProvincia' => 'Prov. Const. del Callao']);
        $this->assertDatabaseHas('districts', ['idDistrito' => '010201', 'nombreDistrito' => 'Bagua']);
        $this->assertDatabaseHas('schools', [
            'codMod' => '0700001', 'idDistrito' => '010102', 'nombreCenEdu' => 'ASUNCION', 'director' => 'ROJAS ANA',
            'dis_crecer' => null, 'dis_juntos' => null,
        ]);
        // ESCALE field "cenEdu" is stored as "nombreCenEdu" and the ubigeo as "idDistrito".
        $this->assertDatabaseHas('schools', [
            'codMod' => '0559526', 'idDistrito' => '010101', 'nombreCenEdu' => '18095', 'cenPob' => 'CHUELTA',
            'dirCen' => 'CARRETERA CHUELTA S/N', 'director' => 'GUIVIN LOPEZ MERCEDES',
            'dis_crecer' => 'Segunda etapa', 'dis_juntos' => 'Segunda etapa',
        ]);
        $this->assertDatabaseHas('schools', ['codMod' => '0559527', 'director' => null, 'dis_crecer' => null]);
    }

    public function test_it_reports_missing_or_failed_school_responses_and_continues(): void
    {
        $this->fakeEscale();

        $this->artisan('sync:locations')
            ->expectsOutput('No se encontraron registros de escuelas')
            ->expectsOutput('Error al obtener escuelas para el distrito 070101')
            ->expectsOutput('Sincronización completa.')
            ->assertExitCode(0);

        // The Callao district is still stored even though its schools request failed.
        $this->assertDatabaseHas('districts', ['idDistrito' => '070101']);
        $this->assertDatabaseMissing('schools', ['idDistrito' => '070101']);
    }

    public function test_it_sends_the_expected_requests_to_escale(): void
    {
        $this->fakeEscale();

        $this->artisan('sync:locations')->assertExitCode(0);

        foreach ([
            'regiones',
            'provincias?idRegion=07',
            'distritos?idProvincia=0102',
            'instituciones?estados=1&ubigeo=010101',
        ] as $path) {
            Http::assertSent(fn (Request $request) => $request->method() === 'GET'
                && $request->url() === self::ESCALE.$path
                && $request->hasHeader('Accept', 'application/json'));
        }
    }

    public function test_running_it_twice_does_not_duplicate_records(): void
    {
        $this->fakeEscale();

        $this->artisan('sync:locations')->assertExitCode(0);
        $this->artisan('sync:locations')->assertExitCode(0);

        $this->assertDatabaseCount('regions', 2);
        $this->assertDatabaseCount('provinces', 3);
        $this->assertDatabaseCount('districts', 4);
        $this->assertDatabaseCount('schools', 3);
    }

    public function test_it_stops_gracefully_when_regions_cannot_be_downloaded(): void
    {
        Http::fake(['*' => Http::response('Service Unavailable', 503)]);

        $this->artisan('sync:locations')
            ->expectsOutput('Error al obtener regiones')
            ->expectsOutput('Sincronización completa.')
            ->assertExitCode(0);

        $this->assertDatabaseCount('regions', 0);
    }
}
