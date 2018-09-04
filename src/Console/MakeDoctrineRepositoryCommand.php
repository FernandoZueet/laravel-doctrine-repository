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
use Illuminate\Support\Facades\File;

class MakeDoctrineRepositoryCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'make:doctrine-repository {name}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create doctrine repository';

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
        $interface = false;

        //---------------------------------------------------
        //get params
        $name = $this->argument('name');
        $repositoryFolder = config('doctrine')['LdrConfig']['repositoryFolder'];
        $complName = config('doctrine')['LdrConfig']['complementClassName'];
        $templateBase = \File::get(base_path('vendor/fernandozueet/laravel-doctrine-repository/src/Console/Stubs/baseRepository.stub'));
        $templateJoin = \File::get(base_path('vendor/fernandozueet/laravel-doctrine-repository/src/Console/Stubs/join.stub'));
        $templateSelectJoin = \File::get(base_path('vendor/fernandozueet/laravel-doctrine-repository/src/Console/Stubs/selectJoin.stub'));
        $templateInterface = \File::get(base_path('vendor/fernandozueet/laravel-doctrine-repository/src/Console/Stubs/interfaceRepository.stub'));
        $templateProviderService = \File::get(base_path('vendor/fernandozueet/laravel-doctrine-repository/src/Console/Stubs/serviceProvider.stub'));

        //---------------------------------------------------
        //input
        $fkEntities = $this->ask('Enter the foreign key class name. Ex: UserAccount,UserLog');
        $mAlias = $this->ask('Enter the main alias. Ex: ua');
        if ($this->confirm('Want to create interface repository? (Recommended)')) {
            $interface = true;
        }

        //---------------------------------------------------
        //mount files names
        $subpath = explode('/', $name);
        if (count($subpath) > 1) {
            $name = end($subpath);
            array_pop($subpath);
            $namespaceImp = implode('\\', $subpath);
            $subpath = '/'.implode('/', $subpath).'/'.$name;
            $namespaceSub = "\\{$namespaceImp}";
        } else {
            $subpath = "/$name";
            $namespaceSub = '';
        }
        $namespaceClass = "{$repositoryFolder}{$namespaceSub}\\{$name}";
        $className = "{$name}{$complName}";
        $fileName = "{$className}.php";
        $pathBase = "{$repositoryFolder}{$subpath}/";
        $pathCompl = app_path("{$pathBase}{$fileName}");
        $pathComplInterf = app_path("{$pathBase}{$name}RepositoryInterface.php");
        $pathServiceProvider = app_path("Providers/{$name}{$complName}ServiceProvider.php");
        if (!$mAlias) {
            $mAlias = strtolower($name[0].substr($name, -1, 1));
        }

        //---------------------------------------------------
        //trate $fkEntities
        if ($fkEntities) {
            $fkEntities = explode(',', $fkEntities);
            foreach ($fkEntities as $key => $value) {
                $fkfAlias = strtolower($value[0].substr($value, -1, 1));
                //mount atribute $fkEntities
                $fkEntitiesNew[] = "'$value'";
                //mount method join
                $templateJoinStr = str_replace('{{doc}}', "Inner join $value.", $templateJoin); //update doc
                $templateJoinStr = str_replace('{{joinName}}', "innerJoin$value", $templateJoinStr); //update method name
                $templateJoinStr = str_replace('{{field}}', '{$this->mAlias}.'.lcfirst($value), $templateJoinStr); //update field
                $templateJoinStr = str_replace('{{alias}}', $fkfAlias, $templateJoinStr); //update alias
                $joinsArray[] = $templateJoinStr;
                //select join instance
                $templateSelectJoinStr = str_replace('{{methodName}}', "innerJoin$value", $templateSelectJoin); //update
                $selectJoinsArray[] = $templateSelectJoinStr;
                //array names fk
                $fkEntitiesNames[] = $value;
                $fkAliasArray[] = $fkfAlias;
            }
            $fkEntities = implode(',', $fkEntitiesNew);
            $joins = implode('', $joinsArray);
            //create instance join select
            $selectJoin = implode('        ', $selectJoinsArray);
            //create select
            $fkAliasArray[] = '{$this->mAlias}';
            $select = implode(', ', $fkAliasArray);
        }

        //---------------------------------------------------
        //create folder
        if (!file_exists(app_path($pathBase))) {
            \File::makeDirectory(app_path($pathBase), 0777, true);
        }

        //---------------------------------------------------
        //create file baseRepository
        if (!file_exists($pathCompl)) {
            //data update baseRepository
            $newTemplateBase = str_replace('{{nameclass}}', $className, $templateBase);
            $newTemplateBase = str_replace('{{nameEntity}}', $name, $newTemplateBase);
            $newTemplateBase = str_replace('{{namespace}}', $namespaceClass, $newTemplateBase);
            $newTemplateBase = str_replace('{{fkEntities}}', $fkEntities, $newTemplateBase);
            $newTemplateBase = str_replace('{{mAlias}}', $mAlias, $newTemplateBase);
            $newTemplateBase = str_replace('{{joins}}', $joins ?? '//Joins methods here', $newTemplateBase);
            $newTemplateBase = str_replace('{{selectJoin}}', $selectJoin ?? '//', $newTemplateBase);
            $newTemplateBase = str_replace('{{select}}', $select ?? '{$this->mAlias}', $newTemplateBase);
            $newTemplateBase = str_replace('{{interface}}', $interface ? "implements {$name}RepositoryInterface" : '', $newTemplateBase);

            //save baseRepository
            file_put_contents($pathCompl, $newTemplateBase);

            $this->line('Repository created successfully!');
            $this->line('');
        } else {
            $this->line('');
            $this->error('Error. Repository already exists!');
        }

        //---------------------------------------------------
        //create file interfaceRepository
        if ($interface) {
            if (!file_exists($pathComplInterf)) {
                //data update interfaceRepository
                $newTemplateInterface = str_replace('{{namespace}}', $namespaceClass, $templateInterface);
                $newTemplateInterface = str_replace('{{nameEntity}}', $name, $newTemplateInterface);

                //save interfaceRepository
                file_put_contents($pathComplInterf, $newTemplateInterface);

                $this->line('Interface repository created successfully!');
                $this->line('');
            } else {
                $this->line('');
                $this->error('Error. Interface repository already exists!');
            }

            //---------------------------------------------------
            //create service provider
            if (!file_exists($pathServiceProvider)) {
                if ($this->confirm('Want to create repository service provider?')) {
                    //data update baseRepository
                    $newTemplateProviderService = str_replace('{{name}}', "{$name}{$complName}", $templateProviderService);
                    $newTemplateProviderService = str_replace('{{interface}}', "\App\\$namespaceClass\\{$name}RepositoryInterface", $newTemplateProviderService);
                    $newTemplateProviderService = str_replace('{{nameClassInstance}}', "\App\\$namespaceClass\\$className", $newTemplateProviderService);

                    //save baseRepository
                    file_put_contents($pathServiceProvider, $newTemplateProviderService);

                    //success
                    $this->line('Service provider created successfully!');
                    $this->line('');
                    $this->line('add the ServiceProvider to the providers array in config/app.php');
                    $this->info("App\Providers\\{$name}{$complName}ServiceProvider::class,");
                    $this->line('');
                }
            } else {
                $this->line('');
                $this->error('Error. Service provider already exists!');
            }
        }
    }
}
