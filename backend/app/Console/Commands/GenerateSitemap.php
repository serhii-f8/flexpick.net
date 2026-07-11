<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Isolatable;
use Illuminate\Support\Facades\Route;
use Spatie\Sitemap\Sitemap;

class GenerateSitemap extends Command implements Isolatable
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:generate-sitemap';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate a sitemap.xml file and add it to robots.txt';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        if (! app()->environment('production')) {
            $this->warn('Not generating sitemap in local environment');

            return;
        }

        $routes = collect(Route::getRoutes()->getRoutes())->filter(function (\Illuminate\Routing\Route $route) {

            if (! in_array('GET', $route->methods)) {
                return false;
            }

            if (! isset($route->action['middleware']) || ! is_array($route->action['middleware'])) {
                return false;
            }

            if (in_array('sitemapped', $route->action['middleware'])) {
                return true;
            }

            return false;
        })->map(function ($route) {
            return route($route->getName());
        })->values()->toArray();

        $sitemap = Sitemap::create();

        foreach ($routes as $route) {
            $sitemap->add($route);
        }

        $sitemap->writeToFile(public_path('sitemap.xml'));

        // add the sitemap to robots.txt
        $robots = $this->getDefaultRobotsTxtContent().'Sitemap: '.url('sitemap.xml');
        file_put_contents(public_path('robots.txt'), $robots);
    }

    private function getDefaultRobotsTxtContent()
    {
        return "User-agent: *\nDisallow:\n\n";
    }
}
