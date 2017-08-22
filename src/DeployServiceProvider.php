<?php

namespace Vns\Deploy;

use Illuminate\Support\ServiceProvider;

class DeployServiceProvider extends ServiceProvider
{
    public function boot()
    {
        $this->commands([
            Deploy::class
        ]);
    }
}