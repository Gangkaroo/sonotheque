<?php

declare(strict_types=1);

namespace Tests\Unit;

use InvalidArgumentException;
use Sonotheque\Packaging\PackagedConfiguration;
use Tests\TestCase;

require_once __DIR__.'/../../../scripts/lib/PackagedConfiguration.php';

final class PackagedConfigurationTest extends TestCase
{
    private string $repositoryRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repositoryRoot = sys_get_temp_dir().'/sonotheque-packaged-config-'.bin2hex(random_bytes(6));
        mkdir($this->repositoryRoot, 0777, true);
        file_put_contents($this->repositoryRoot.'/.env.packaged.example', implode(PHP_EOL, [
            'APP_KEY=',
            'POSTGRES_PASSWORD=change-this-local-password',
            'SONOTHEQUE_ROOT_1=./packaged/music-root-1',
            '',
        ]));
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->repositoryRoot);

        parent::tearDown();
    }

    public function test_it_initializes_secrets_without_replacing_them_later(): void
    {
        $configuration = new PackagedConfiguration($this->repositoryRoot);
        $configuration->initialize('/music/first');

        $appKey = $configuration->environmentValue('APP_KEY');
        $password = $configuration->environmentValue('POSTGRES_PASSWORD');

        $this->assertStringStartsWith('base64:', (string) $appKey);
        $this->assertStringStartsWith('sonotheque_', (string) $password);
        $this->assertSame('/music/first', $configuration->environmentValue('SONOTHEQUE_ROOT_1'));

        $configuration->initialize('/music/second');

        $this->assertSame($appKey, $configuration->environmentValue('APP_KEY'));
        $this->assertSame($password, $configuration->environmentValue('POSTGRES_PASSWORD'));
        $this->assertSame('/music/second', $configuration->environmentValue('SONOTHEQUE_ROOT_1'));
    }

    public function test_it_generates_root_metadata_and_compose_mounts(): void
    {
        $configuration = new PackagedConfiguration($this->repositoryRoot);
        $configuration->configureRoots(['/music/Archive', "/music/Recent's"]);

        $roots = json_decode(
            (string) file_get_contents($this->repositoryRoot.'/packaged-roots.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $override = (string) file_get_contents($this->repositoryRoot.'/compose.packaged.override.yaml');

        $this->assertSame('/music/root-1', $roots['roots'][0]['containerPath']);
        $this->assertSame("/music/Recent's", $roots['roots'][1]['hostPath']);
        $this->assertStringContainsString("source: '/music/Recent''s'", $override);
        $this->assertStringContainsString('target: /music/root-2', $override);
        $this->assertStringContainsString('queue-analysis-ai:', $override);
        $this->assertStringContainsString('read_only: true', $override);
    }

    public function test_it_rejects_overlapping_roots_case_insensitively(): void
    {
        $configuration = new PackagedConfiguration($this->repositoryRoot);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Music roots must not overlap');

        $configuration->configureRoots(['C:\\Music', 'c:\\music\\Archive'], true);
    }

    public function test_it_configures_local_and_lan_network_modes(): void
    {
        $configuration = new PackagedConfiguration($this->repositoryRoot);
        $configuration->initialize();

        $this->assertNull($configuration->configureNetwork('127.0.0.1', 8080, false, 'music-host'));
        $this->assertSame('false', $configuration->environmentValue('SONOTHEQUE_LAN_ENABLED'));
        $this->assertSame('localhost,127.0.0.1,::1', $configuration->environmentValue('SONOTHEQUE_TRUSTED_HOSTS'));

        $token = $configuration->configureNetwork('192.168.1.25', 8090, true, 'music-host');

        $this->assertSame('http://192.168.1.25:8090', $configuration->environmentValue('APP_URL'));
        $this->assertSame(
            'localhost,127.0.0.1,::1,192.168.1.25,music-host',
            $configuration->environmentValue('SONOTHEQUE_TRUSTED_HOSTS'),
        );
        $this->assertSame(48, strlen((string) $token));
    }

    public function test_it_configures_host_and_docker_group_identity(): void
    {
        $configuration = new PackagedConfiguration($this->repositoryRoot);
        $configuration->initialize();

        $configuration->configureHostIdentity(1000, 1001, 998);

        $this->assertSame('1000', $configuration->environmentValue('SONOTHEQUE_HOST_UID'));
        $this->assertSame('1001', $configuration->environmentValue('SONOTHEQUE_HOST_GID'));
        $this->assertSame('998', $configuration->environmentValue('SONOTHEQUE_DOCKER_GID'));
    }

    public function test_it_configures_and_disables_audio_intelligence(): void
    {
        $configuration = new PackagedConfiguration($this->repositoryRoot);
        $configuration->initialize();

        $configuration->configureAudioIntelligence('/models/discogs.pb', 'cuda');

        $this->assertSame('/models', $configuration->environmentValue('AUDIO_INTELLIGENCE_MODEL_DIRECTORY'));
        $this->assertSame('discogs.pb', $configuration->environmentValue('AUDIO_INTELLIGENCE_MODEL_FILENAME'));
        $this->assertSame('sonotheque-audio-intelligence:cuda', $configuration->environmentValue('AUDIO_INTELLIGENCE_DOCKER_IMAGE'));
        $this->assertSame('cuda', $configuration->environmentValue('AUDIO_INTELLIGENCE_ACCELERATOR'));
        $this->assertSame('essentia_docker', $configuration->environmentValue('AUDIO_INTELLIGENCE_DRIVER'));

        $configuration->disableAudioIntelligence();

        $this->assertSame('none', $configuration->environmentValue('AUDIO_INTELLIGENCE_DRIVER'));
    }

    private function deleteDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $directory.'/'.$entry;
            is_dir($path) ? $this->deleteDirectory($path) : unlink($path);
        }
        rmdir($directory);
    }
}
