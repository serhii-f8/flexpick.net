<?php

namespace Deployer;

require 'recipe/laravel.php';
require 'contrib/crontab.php';

// Configs
// Server-specific values are read from the environment so no host detail is
// committed. Ploi provisions the `deployer` user with key-based sudo.

$remoteUser = 'deployer';
$sudoPassword = '';

$host = getenv('DEPLOY_HOST') ?: 'app.flexpick.net';

$repository = 'git@github.com:serhii-f8/flexpick.net.git';

// This is a monorepo. The Laravel application lives in backend/; deploying the
// repository root would deploy a directory with no composer.json.
$subDirectory = 'backend';

$phpVersion = '8.4';

// End of configs
// ///////////////////////////////////
// ///////////////////////////////////

set('repository', $repository);
set('sub_directory', $subDirectory);

set('node_version', '23.x');

add('shared_files', []);
add('shared_dirs', []);
add('writable_dirs', []);

host('production')
    ->setHostname($host)
    ->set('remote_user', $remoteUser)
    ->set('deploy_path', '~/app')
    ->set('sudo_password', $sudoPassword)
    ->set('domain', 'app.flexpick.net')
    ->set('public_path', 'public')
    ->set('php_version', $phpVersion)
    ->set('branch', 'main');

host('staging')
    ->setHostname($host)
    ->set('remote_user', $remoteUser)
    ->set('deploy_path', '~/staging')
    ->set('sudo_password', $sudoPassword)
    ->set('domain', 'staging.app.flexpick.net')
    ->set('public_path', 'public')
    ->set('php_version', $phpVersion)
    ->set('branch', 'main');

desc('Install & build npm packages');
task('npm:build', function () {
    run('cd {{release_path}} && eval "$(fnm env)" && npm ci && npm run build');
});

desc('Provision extra PHP packages');
task('provision:php-extra', function () {
    $version = get('php_version');
    info("Installing Extra PHP $version packages");
    $packages = [
        "php$version-redis",
    ];

    run('apt-get install -y '.implode(' ', $packages), env: ['DEBIAN_FRONTEND' => 'noninteractive']);
})->verbose()
    ->limit(1);

desc('Provision supervisor');
task('provision:supervisor', function () use ($remoteUser) {
    info('Installing Supervisor');

    run('apt-get install -y supervisor', env: ['DEBIAN_FRONTEND' => 'noninteractive']);

    // Both hosts live on one server (D9A2.2), so the program name and config
    // file must be per-host. A fixed `horizon` name would mean provisioning
    // staging silently overwrote production's supervisor program.
    $program = 'horizon-'.get('alias');

    $supervisorConfig = <<<'EOF'
[program:{{program}}]
process_name=%(program_name)s
command=php {{deployPath}}/current/artisan horizon
autostart=true
autorestart=true
user={{user}}
redirect_stderr=true
stdout_logfile={{deployPath}}/log/horizon.log
stopwaitsecs=60
EOF;

    $deployPathRelativeToDeployerUser = str_replace('~', '/home/'.$remoteUser, get('deploy_path'));

    $supervisorConfig = str_replace('{{program}}', $program, $supervisorConfig);
    $supervisorConfig = str_replace('{{deployPath}}', $deployPathRelativeToDeployerUser, $supervisorConfig);
    $supervisorConfig = str_replace('{{user}}', $remoteUser, $supervisorConfig);

    $supervisorConfigPath = "/etc/supervisor/conf.d/$program.conf";

    run("echo '$supervisorConfig' > $supervisorConfigPath");

    run('supervisorctl reread');
    run('supervisorctl update');
    run("supervisorctl start $program");
})->verbose()
    ->limit(1);

desc('Fixes a common bug with AWS EC2 instances that causes SSH to fail');
task('provision:fix-aws-ssh', function () {
    $authorizedKeys = run('cat /home/deployer/.ssh/authorized_keys');

    $searchSting = 'no-port-forwarding,no-agent-forwarding,no-X11-forwarding,command="echo \'Please login as the user \"ubuntu\" rather than the user \"root\".\';echo;sleep 10;exit 142" ';

    if (str_contains($authorizedKeys, $searchSting)) {
        $authorizedKeys = str_replace($searchSting, '', $authorizedKeys);
        $authorizedKeys = trim($authorizedKeys);
        run('echo "$KEY" > /home/deployer/.ssh/authorized_keys', env: ['KEY' => $authorizedKeys]);
    }
})->verbose()
    ->limit(1);

desc('Generate sitemap');
task('deploy:sitemap', artisan('app:generate-sitemap', ['skipIfNoEnv']));

desc('Export configs from database to cache');
task('deploy:export-configs', artisan('app:export-configs', ['skipIfNoEnv']));

desc('Inject the deployed git SHA as SENTRY_RELEASE (PR4)');
task('deploy:sentry-release', function () {
    $sha = trim(run('cat {{release_path}}/REVISION'));

    if ($sha === '') {
        throw new \RuntimeException(
            'REVISION is empty; refusing to deploy without release context (PR4).'
        );
    }

    // .env is a shared file, so {{release_path}}/.env is a symlink. sed -i
    // renames over its target and would replace the symlink with a plain
    // file, detaching this release from shared config. Edit the real path.
    $envPath = trim(run('readlink -f {{release_path}}/.env'));

    run("if grep -q '^SENTRY_RELEASE=' $envPath; then
            sed -i -E 's|^SENTRY_RELEASE=.*|SENTRY_RELEASE=$sha|' $envPath;
         else
            echo 'SENTRY_RELEASE=$sha' >> $envPath;
         fi");

    info("SENTRY_RELEASE set to $sha");
});

desc('Post-release smoke gate (PR8) -- fails the deploy on a non-zero exit');
task('deploy:smoke', function () {
    // Runs against the freshly symlinked release, which is now live. Read-only:
    // sends no email, runs no audit, writes nothing. Exit code is the contract.
    run('cd {{current_path}} && {{bin/php}} artisan app:smoke', timeout: 120);
});

// seed database
after('artisan:migrate', 'artisan:db:seed');

// npm
after('artisan:migrate', 'npm:build');

desc('Provision the audit scanner binaries');
task('provision:scanners', function () {
    $binDir = '/opt/flexpick/bin';

    $scc = '3.5.0';
    $gitleaks = '8.28.0';
    $jscpd = '4.0.5';
    $semgrep = '1.99.0';

    info('Installing audit scanners to '.$binDir);

    run("mkdir -p $binDir");

    // scc — single static Go binary
    run("curl -sSfL https://github.com/boyter/scc/releases/download/v$scc/scc_Linux_x86_64.tar.gz "
        ."| tar -xz -C $binDir scc && chmod +x $binDir/scc");

    // Gitleaks — single static Go binary
    run("curl -sSfL https://github.com/gitleaks/gitleaks/releases/download/v$gitleaks/gitleaks_${gitleaks}_linux_x64.tar.gz "
        ."| tar -xz -C $binDir gitleaks && chmod +x $binDir/gitleaks");

    // jscpd — npm package; Node is already provisioned via fnm
    run("eval \"\$(fnm env)\" && npm install -g jscpd@$jscpd");
    run("ln -sf \$(eval \"\$(fnm env)\" && which jscpd) $binDir/jscpd");

    // Semgrep — Python package; the only scanner needing a Python runtime
    run('apt-get install -y python3 python3-venv', env: ['DEBIAN_FRONTEND' => 'noninteractive']);
    run("python3 -m venv /opt/flexpick/semgrep-venv");
    // setuptools>=81 dropped pkg_resources, which semgrep's opentelemetry
    // dependency still imports without declaring — pin below 81 or semgrep
    // crashes with ModuleNotFoundError on invocation, not just on --version.
    run("/opt/flexpick/semgrep-venv/bin/pip install --quiet 'setuptools<81' semgrep==$semgrep");
    run("ln -sf /opt/flexpick/semgrep-venv/bin/semgrep $binDir/semgrep");
})->verbose()->limit(1);

after('provision:php-extra', 'provision:scanners');

// php
after('provision:php', 'provision:php-extra');
after('provision:verify', 'provision:supervisor');
// after('provision:deployer', 'provision:fix-aws-ssh');  // Enable if you use AWS EC2 server

after('deploy:success', 'artisan:horizon:terminate'); // to restart horizon after deploy
after('deploy:success', 'crontab:sync');
after('deploy:success', 'deploy:sitemap');
after('deploy:success', 'deploy:export-configs');

before('artisan:optimize', 'deploy:sentry-release');

after('deploy:symlink', 'deploy:smoke');

after('deploy:failed', 'deploy:unlock');

// deploy:failed already unlocks (see above); rolling back afterwards returns
// the site to the previous release. Rehearsed in the runbook, step 8.
after('deploy:failed', 'rollback');

add('crontab:jobs', [
    '* * * * * cd {{current_path}} && {{bin/php}} artisan schedule:run >> /dev/null 2>&1',
]);

task('provision:node:install', function () use ($remoteUser) {
    set('remote_user', get('provision_user'));

    // Install the specified Node version for provision user
    run('eval "$(fnm env)" && fnm install {{node_version}}');
    run('eval "$(fnm env)" && fnm default {{node_version}}');

    // Also install for the deploy user so npm:build works during deploys
    run("su - $remoteUser -c 'eval \"\$(fnm env)\" && fnm install {{node_version}} && fnm default {{node_version}}'");
});

// Run this task automatically after provision:node
after('provision:node', 'provision:node:install');
