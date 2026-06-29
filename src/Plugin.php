<?php

namespace Deployward;

use Deployward\Admin\AdminPage;
use Deployward\Cli\DeployCommand;
use Deployward\Http\RestController;
use Deployward\Http\RestRoutes;
use Deployward\Log\DeployLog;

final class Plugin
{
    public static function activate(): void
    {
        global $wpdb;
        $log = new DeployLog($wpdb);
        $log->installTable();
    }

    public static function boot(): void
    {
        global $wpdb;
        $container = new Container($wpdb);

        if (defined('WP_CLI') && WP_CLI) {
            \WP_CLI::add_command('deployward', self::makeCommand());
        }

        $routes = new RestRoutes(new RestController(
            $container->repository(),
            $container->deployer(),
            $container->log(),
            $container->github()
        ));
        add_action('rest_api_init', array($routes, 'register'));

        $page = new AdminPage();
        add_action('admin_menu', array($page, 'register'));
        add_action('admin_enqueue_scripts', array($page, 'enqueue'));
    }

    public static function makeCommand(): DeployCommand
    {
        global $wpdb;
        $container = new Container($wpdb);

        return new DeployCommand($container->repository(), $container->deployer(), $container->log());
    }
}
