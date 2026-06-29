<?php

namespace Deployward;

use Deployward\Cli\DeployCommand;
use Deployward\Config\DeploymentRepository;
use Deployward\Deploy\BackupManager;
use Deployward\Deploy\Deployer;
use Deployward\Deploy\Extractor;
use Deployward\Deploy\HealthChecker;
use Deployward\Deploy\MaintenanceMode;
use Deployward\Deploy\PayloadValidator;
use Deployward\GitHub\GitHubClient;
use Deployward\Log\DeployLog;
use Deployward\Notify\Notifier;
use Deployward\Security\Encryptor;

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
        if (defined('WP_CLI') && WP_CLI) {
            \WP_CLI::add_command('deployward', self::makeCommand());
        }
    }

    public static function makeCommand(): DeployCommand
    {
        global $wpdb;

        $repository = new DeploymentRepository(Encryptor::fromSalts());
        $uploads = wp_upload_dir();
        $backupsBase = trailingslashit($uploads['basedir'])
            . 'deployward-backups-' . substr(md5(AUTH_SALT), 0, 8);

        $deployer = new Deployer(
            new GitHubClient(),
            new Extractor(get_temp_dir()),
            new PayloadValidator(),
            new BackupManager($backupsBase),
            new HealthChecker(),
            new MaintenanceMode(ABSPATH),
            new DeployLog($wpdb),
            $repository,
            new Notifier((string) get_option('admin_email', '')),
            array(
                'plugin' => WP_PLUGIN_DIR,
                'theme' => get_theme_root(),
                'mu-plugin' => defined('WPMU_PLUGIN_DIR') ? WPMU_PLUGIN_DIR : WP_CONTENT_DIR . '/mu-plugins',
            ),
            home_url('/'),
            get_temp_dir(),
            defined('DEPLOYWARD_SLUG') ? DEPLOYWARD_SLUG : 'deployward',
            3
        );

        return new DeployCommand($repository, $deployer, new DeployLog($wpdb));
    }
}
