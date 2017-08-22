<?php
namespace Vns\Deploy;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Process\Process;

class Deploy extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'deploy {environment=production}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Deploy configurations';

    /**
     * Base directory to run commands from
     *
     * @var string
     */
    protected $workingDirectory;

    /**
     * Create a new command instance.
     */
    public function __construct()
    {
        parent::__construct();

        $this->workingDirectory = base_path();
    }

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $environment = $this->argument('environment');

        if(!in_array($environment, ['production', 'staging', 'qa'])) {
            $this->error('Invalid environment argument provided. Use one of "production", "staging", or "qa"');
            return;
        }

        $bar = $this->output->createProgressBar(8);
        $bar->setFormat("[%bar%] => %current%/%max% -- %message%\n");
        $this->info('Deploying...');

        $bar->setMessage('Creating .env file...');
        $this->createDotenv();
        $bar->advance();

        $bar->setMessage('Creating config.js file...');
        $this->createConfigJS();
        $bar->advance();

        $bar->setMessage('Moving default error views...');
        $this->moveErrorViews();
        $bar->advance();

        $bar->setMessage('Creating symlinks...');
        $this->createPublicSymlink(true);
        $bar->advance();

        $bar->setMessage('Installing dependencies...');
        $this->installDependencies();
        $bar->advance();

        $bar->setMessage('Optimizing app...');
        $this->optimize();
        $bar->advance();

        $bar->setMessage('Setting file permissions...');
        $this->setFilePermissions();

        $bar->setMessage('Done!');
        $bar->finish();

        $this->info("\n");

        if($this->confirm('Do you wish to edit the .env file now?', true)) {
            $envLocation = base_path('.env');
            system("nano $envLocation > `tty`");
        }

        if($this->confirm('Do you wish to edit the config.js file now?', true)) {
            $cfgLocation = public_path('js/config.js');
            system("nano $cfgLocation > `tty`");
        }

        if($this->confirm('Do you wish to run the database migrations?', true)) {
            $withSeeders = $this->confirm('Run seeders too?', true);

            $this->info("Running database migrations...");
            $this->setupDB($withSeeders);
            $this->info("Done!");
        }

        $this->info("Finished deploy!");
    }

    /**
     * Create .env file based on user's input
     */
    private function createDotenv()
    {
        $content = file_get_contents(__DIR__ . '/stubs/.env.stub');
        file_put_contents(base_path('.env'), $content);
        Artisan::call('key:generate');
    }

    /**
     * Create config.js file based on user's input
     */
    private function createConfigJS()
    {
        $content = file_get_contents(__DIR__ . '/stubs/config.js.stub');
        file_put_contents(public_path('js/config.js'), $content);
    }

    /**
     * Move default error views from server (from public_html to laravel's public folder)
     */
    private function moveErrorViews()
    {
        $this->runCmd("mv ./public_html/*.shtml ./backend/public/", base_path('..'), false);
    }

    /**
     * Create a symlink to laravel's public folder inside the root directory (./../)
     *
     * @param bool $secure - create public_html link if set to false, private_html if set to true
     */
    private function createPublicSymlink($secure = false)
    {
        $html = $secure ? 'private' : 'public';
        $this->runCmd("rm -rf {$this->workingDirectory}/../{$html}_html");
        $this->runCmd("ln -s {$this->workingDirectory}/public {$this->workingDirectory}/../{$html}_html");
    }

    /**
     * Install application's dependencies (composer, bower, grunt, etc.)
     */
    private function installDependencies()
    {
        $this->runCmd("composer install");
    }

    /**
     * Optimize laravel's cache, generate routes cache and clear previous cache data
     */
    private function optimize()
    {
        Artisan::call('optimize');
        Artisan::call('route:cache');
        Artisan::call('cache:clear');
    }

    /**
     * Run laravel's migrations
     *
     * @param bool $seed - run laravel's seeders
     */
    private function setupDB($seed = false)
    {
        $seed
            ? Artisan::call("migrate:refresh", "seed")
            : Artisan::call("migrate:refresh");
    }

    /**
     * Set correct file permissions for the entire project
     */
    private function setFilePermissions()
    {
        $this->runCmd("find . -type d -exec chmod 755 {} \;");
        $this->runCmd("find . -type f -exec chmod 644 {} \;");
        $this->runCmd("chmod 777 -R storage/");
    }

    /**
     * Run a shell command
     *
     * @param $cmd - command to run
     * @param null $wd - working directory (where to run the supplied command) - laravel root by default
     * @param bool $must - catch errors if set to false
     */
    private function runCmd($cmd, $wd = null, $must = true)
    {
        if(!$wd) {
            $wd = $this->workingDirectory;
        }

        $process = new Process($cmd);
        $process->setWorkingDirectory($wd);

        if($must) {
            $process->mustRun();
        } else {
            $process->run();
        }
    }
}
