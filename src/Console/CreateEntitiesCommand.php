<?php

/**
 * This file is part of the Laravel Doctrine Repository package.
 *
 * @see http://github.com/fernandozueet/laravel-doctrine-repository
 *
 * @copyright 2018
 * @license MIT License
 * @author Fernando Zueet <fernandozueet@hotmail.com>
 */

namespace Ldr\Console;

use Illuminate\Console\Command;
use Doctrine\ORM\Tools\EntityGenerator;
use Doctrine\ORM\Mapping\Driver\DatabaseDriver;
use Doctrine\ORM\Tools\DisconnectedClassMetadataFactory;

class CreateEntitiesCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'make:doctrine-repository:entities';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate doctrine entities';

    /**
     * Create a new command instance.
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $dbName = $this->ask('Name of the connection to the database? value default: (default)') ?? 'default';

        $namespace = config('doctrine.managers')[$dbName]['LdrConfig']['namespaceEntities'].'\\' ?? 'App\\Entities\\';
        $dirName = str_replace('\\', '/', $namespace);

        $em = app('registry')->getManager($dbName);

        //delete files
        $files = glob(__DIR__.'/../../../../../'.$dirName.'*');
        if (count($files) > 0) {
            foreach ($files as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }

        //custom datatypes (not mapped for reverse engineering)
        $em->getConnection()->getDatabasePlatform()->registerDoctrineTypeMapping('set', 'string');
        $em->getConnection()->getDatabasePlatform()->registerDoctrineTypeMapping('enum', 'string');
        $em->getConnection()->getDatabasePlatform()->registerDoctrineTypeMapping('json', 'json_array');

        //fetch metadata
        $driver = new DatabaseDriver(
            $em->getConnection()->getSchemaManager()
        );
        $driver->setNamespace($namespace);
        $em->getConfiguration()->setMetadataDriverImpl($driver);
        $cmf = new DisconnectedClassMetadataFactory($em);
        $cmf->setEntityManager($em);
        $classes = $driver->getAllClassNames();
        $metadata = $cmf->getAllMetadata();

        $generator = new EntityGenerator();
        $generator->setUpdateEntityIfExists(true);
        $generator->setGenerateStubMethods(true);
        $generator->setGenerateAnnotations(true);
        $generator->generate($metadata, __DIR__.'/../../../../../');

        $this->line('Successfully generated entities!');
    }
}
