<?php

namespace PackageVersionsTest;

use Composer\Autoload\AutoloadGenerator;
use Composer\Composer;
use Composer\Config;
use Composer\EventDispatcher\EventDispatcher;
use Composer\Installer\InstallationManager;
use Composer\IO\IOInterface;
use Composer\Package\Locker;
use Composer\Package\PackageInterface;
use Composer\Repository\InstalledRepositoryInterface;
use Composer\Repository\RepositoryManager;
use Composer\Script\Event;
use Composer\Script\ScriptEvents;
use PackageVersions\Installer;
use PHPUnit_Framework_TestCase;

/**
 * @covers \PackageVersions\Installer
 */
final class InstallerTest extends PHPUnit_Framework_TestCase
{
    /**
     * @var Composer|\PHPUnit_Framework_MockObject_MockObject
     */
    private $composer;

    /**
     * @var EventDispatcher|\PHPUnit_Framework_MockObject_MockObject
     */
    private $eventDispatcher;

    /**
     * @var IOInterface|\PHPUnit_Framework_MockObject_MockObject
     */
    private $io;

    /**
     * @var Installer
     */
    private $installer;

    /**
     * {@inheritDoc}
     */
    protected function setUp()
    {
        parent::setUp();

        $this->installer       = new Installer();
        $this->io              = $this->getMock(IOInterface::class);
        $this->composer        = $this->getMock(Composer::class);
        $this->eventDispatcher = $this->getMockBuilder(EventDispatcher::class)->disableOriginalConstructor()->getMock();

        $this->composer->expects(self::any())->method('getEventDispatcher')->willReturn($this->eventDispatcher);
    }

    public function testActivate()
    {
        $this->eventDispatcher->expects(self::once())->method('addSubscriber')->with($this->installer);

        $this->installer->activate($this->composer, $this->io);
    }

    public function testGetSubscribedEvents()
    {
        $events = Installer::getSubscribedEvents();

        self::assertSame(
            [
                'post-install-cmd' => 'dumpVersionsClass',
                'post-update-cmd'  => 'dumpVersionsClass',
            ],
            $events
        );

        foreach ($events as $callback) {
            self::assertInternalType('callable', [$this->installer, $callback]);
        }
    }

    public function testDumpVersionsClass()
    {
        $config            = $this->getMockBuilder(Config::class)->disableOriginalConstructor()->getMock();
        $locker            = $this->getMockBuilder(Locker::class)->disableOriginalConstructor()->getMock();
        $autoloadGenerator = $this->getMockBuilder(AutoloadGenerator::class)->disableOriginalConstructor()->getMock();
        $repositoryManager = $this->getMockBuilder(RepositoryManager::class)->disableOriginalConstructor()->getMock();
        $installManager    = $this->getMockBuilder(InstallationManager::class)->disableOriginalConstructor()->getMock();
        $repository        = $this->getMock(InstalledRepositoryInterface::class);
        $package           = $this->getMock(PackageInterface::class);

        $tmpPath      = sys_get_temp_dir() . '/' . uniqid('', true) . '/vendor';
        $expectedPath = $tmpPath . '/ocramius/package-versions/src/PackageVersions';

        mkdir($expectedPath, 0777, true);

        file_put_contents($expectedPath . '/Versions.php', 'foo');

        $config->expects(self::any())->method('get')->with('vendor-dir')->willReturn($tmpPath);

        $locker
            ->expects(self::any())
            ->method('getLockData')
            ->willReturn([
                'packages' => [
                    [
                        'name'    => 'foo/bar',
                        'version' => '1.2.3',
                        'source'  => [
                            'reference' => 'abc123',
                        ],
                    ],
                    [
                        'name'    => 'baz/tab',
                        'version' => '4.5.6',
                        'source'  => [
                            'reference' => 'def456',
                        ],
                    ],
                ],
            ]);

        $autoloadGenerator->expects(self::once())->method('dump');
        $repositoryManager->expects(self::any())->method('getLocalRepository')->willReturn($repository);

        $this->composer->expects(self::any())->method('getConfig')->willReturn($config);
        $this->composer->expects(self::any())->method('getLocker')->willReturn($locker);
        $this->composer->expects(self::any())->method('getAutoloadGenerator')->willReturn($autoloadGenerator);
        $this->composer->expects(self::any())->method('getRepositoryManager')->willReturn($repositoryManager);
        $this->composer->expects(self::any())->method('getPackage')->willReturn($package);
        $this->composer->expects(self::any())->method('getInstallationManager')->willReturn($installManager);

        $this->installer->dumpVersionsClass(new Event(
            'post-install-cmd',
            $this->composer,
            $this->io
        ));

        $expectedSource = <<<'PHP'
<?php

namespace PackageVersions;

/**
 * This class is generated by
 */
final class Versions
{
    const VERSIONS = array (
  'foo/bar' => '1.2.3@abc123',
  'baz/tab' => '4.5.6@def456',
);

    private function __construct()
    {
    }

    /**
     * @throws \OutOfBoundsException if a version cannot be located
     */
    private static function getVersion(string $packageName) : string
    {
        if (! isset(self::VERSIONS[$packageName])) {
            throw new \OutOfBoundsException(
                'Required package "' . $packageName . '" is not installed: cannot detect its version'
            );
        }

        return self::VERSIONS[$packageName];
    }
}

PHP;


        self::assertSame($expectedSource, file_get_contents($expectedPath . '/Versions.php'));
    }
}
